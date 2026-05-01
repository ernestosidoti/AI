<?php
/**
 * Analisi.php — Engine query builder unificato per la pagina /ai/analisi.php
 *
 * Supporta:
 *  - target = "business" → query su business.master_piva_numeri
 *  - target = "consumer" → query su trovacodicefiscale2.master_cf_numeri
 *  - filtri: regione, provincia, comune, cap, ateco (sezione/codice), eta_min/max,
 *            tipo_telefono (mobile/fisso/entrambi), pod_pdr (luce/gas/entrambi/alm_uno)
 *  - magazzino: nessuno | cliente_table (clienti.*) | tmp_table (uploaded)
 *  - operazione: stat (count + breakdown) | extract (genera xlsx)
 *
 * NOTA AUTH: per ora niente auth — predisposto via callable $authCheck (di default ritorna sempre true).
 * Quando aggiungeremo gli utenti, basterà passare un closure che valida ruolo/permessi.
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

require_once __DIR__ . '/db.php';

class Analisi
{
    /** @var callable|null hook autenticazione (false = blocca) */
    public static $authCheck = null;

    // === COSTRUTTORE FILTRI ===
    /** Normalizza l'array di filtri ricevuto dal frontend */
    public static function normalizeFilters(array $f): array
    {
        $out = [
            'target'       => $f['target'] ?? 'business',  // business|consumer|both
            'regioni'      => array_filter(array_map('trim', (array)($f['regioni'] ?? []))),
            'province'     => array_map('strtoupper', array_filter(array_map('trim', (array)($f['province'] ?? [])))),
            'comuni'       => array_filter(array_map('trim', (array)($f['comuni'] ?? []))),
            'cap'          => array_filter(array_map('trim', (array)($f['cap'] ?? []))),
            'ateco'        => trim($f['ateco'] ?? ''),         // sezione 2 char, codice esatto, o keyword
            'tipo_tel'     => $f['tipo_tel'] ?? 'entrambi',    // mobile|fisso|entrambi
            'eta_min'      => isset($f['eta_min']) && $f['eta_min'] !== '' ? (int)$f['eta_min'] : null,
            'eta_max'      => isset($f['eta_max']) && $f['eta_max'] !== '' ? (int)$f['eta_max'] : null,
            'pod_pdr'      => $f['pod_pdr'] ?? null,            // luce|gas|entrambi|alm_uno (solo business+energia)
            'magazzino'    => $f['magazzino'] ?? null,          // null | "clienti.tabella" | "tmp_xxx"
            'with_email'   => !empty($f['with_email']),
            'with_pec'     => !empty($f['with_pec']),
            'with_sito'    => !empty($f['with_sito']),
        ];
        return $out;
    }

    // === STATISTICA ===
    /**
     * Esegue la statistica con i filtri dati.
     * Ritorna ['total'=>int, 'breakdown'=>['per_regione'=>[], 'per_provincia'=>[], 'per_comune'=>[]], 'sources'=>[]]
     */
    public static function stat(array $filters): array
    {
        $f = self::normalizeFilters($filters);
        self::checkAuth($f);

        $result = ['filters' => $f, 'total' => 0, 'breakdown' => [], 'sources' => []];

        if ($f['target'] === 'business' || $f['target'] === 'both') {
            $r = self::statBusiness($f);
            $result['business'] = $r;
            $result['total'] += $r['total'];
        }
        if ($f['target'] === 'consumer' || $f['target'] === 'both') {
            $r = self::statConsumer($f);
            $result['consumer'] = $r;
            $result['total'] += $r['total'];
        }
        return $result;
    }

    private static function statBusiness(array $f): array
    {
        $pdo = remoteDb('business');
        $pdo->exec("SET SESSION sql_mode = ''");
        $pdo->exec("SET SESSION max_execution_time = 600000");

        [$where, $params, $joins] = self::buildBusinessWhere($f);
        $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $joinSQL = $joins ? implode("\n", $joins) : '';

        // 1) Totale
        $sqlTot = "SELECT COUNT(*) FROM business.master_piva_numeri m $joinSQL $whereSQL";
        $st = $pdo->prepare($sqlTot);
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        // 2) Breakdown per regione (via mappa provincia → regione, tramite GROUP BY provincia)
        $sqlProv = "SELECT m.provincia, COUNT(*) c FROM business.master_piva_numeri m $joinSQL $whereSQL GROUP BY m.provincia ORDER BY c DESC";
        $st = $pdo->prepare($sqlProv);
        $st->execute($params);
        $perProv = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $perReg = self::aggregateByRegione($perProv);

        return [
            'total' => $total,
            'per_regione' => $perReg,
            'per_provincia' => array_slice($perProv, 0, 50, true),
            'sources' => ['business.master_piva_numeri'],
        ];
    }

    private static function statConsumer(array $f): array
    {
        $pdo = remoteDb('trovacodicefiscale2');
        $pdo->exec("SET SESSION sql_mode = ''");
        $pdo->exec("SET SESSION max_execution_time = 600000");

        [$where, $params, $joins] = self::buildConsumerWhere($f);
        $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $joinSQL = $joins ? implode("\n", $joins) : '';

        $sqlTot = "SELECT COUNT(*) FROM trovacodicefiscale2.master_cf_numeri m $joinSQL $whereSQL";
        $st = $pdo->prepare($sqlTot);
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        $sqlProv = "SELECT m.provincia, COUNT(*) c FROM trovacodicefiscale2.master_cf_numeri m $joinSQL $whereSQL GROUP BY m.provincia ORDER BY c DESC";
        $st = $pdo->prepare($sqlProv);
        $st->execute($params);
        $perProv = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $perReg = self::aggregateByRegione($perProv);

        return [
            'total' => $total,
            'per_regione' => $perReg,
            'per_provincia' => array_slice($perProv, 0, 50, true),
            'sources' => ['trovacodicefiscale2.master_cf_numeri'],
        ];
    }

    // === ESTRAZIONE ===
    /**
     * Estrae righe e genera CSV (poi convertito in xlsx).
     * Ritorna ['count'=>int, 'csv_path'=>string, 'cols'=>array]
     */
    public static function extract(array $filters, int $limit, string $outDir): array
    {
        $f = self::normalizeFilters($filters);
        self::checkAuth($f);
        $files = [];

        if ($f['target'] === 'business' || $f['target'] === 'both') {
            $files[] = self::extractBusiness($f, $limit, $outDir);
        }
        if ($f['target'] === 'consumer' || $f['target'] === 'both') {
            $files[] = self::extractConsumer($f, $limit, $outDir);
        }
        return ['files' => $files];
    }

    private static function extractBusiness(array $f, int $limit, string $outDir): array
    {
        $pdo = remoteDb('business');
        $pdo->exec("SET SESSION sql_mode = ''");
        $pdo->exec("SET SESSION max_execution_time = 600000");
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        [$where, $params, $joins] = self::buildBusinessWhere($f);
        $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $joinSQL = $joins ? implode("\n", $joins) : '';

        $sql = "SELECT m.tel, m.tel_type, m.ragione_sociale, m.partita_iva AS piva,
                       m.indirizzo, m.civico, m.cap, m.comune, m.provincia,
                       m.email, m.pec, m.sito_web, m.ateco
                FROM business.master_piva_numeri m
                $joinSQL $whereSQL
                ORDER BY RAND()
                LIMIT " . (int)$limit;
        // CORREZIONE: tabella usa 'piva' non 'partita_iva'
        $sql = str_replace('m.partita_iva AS piva', 'm.piva', $sql);

        $st = $pdo->prepare($sql);
        $st->execute($params);

        $cols = ['tel','tel_type','ragione_sociale','piva','indirizzo','civico','cap','comune','provincia','email','pec','sito_web','ateco'];
        $csvPath = $outDir . '/analisi_business_' . date('Ymd_His') . '_' . uniqid() . '.csv';
        $fp = fopen($csvPath, 'w');
        fputcsv($fp, $cols);
        $count = 0;
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            fputcsv($fp, $r);
            $count++;
        }
        fclose($fp);

        return ['target' => 'business', 'count' => $count, 'csv_path' => $csvPath, 'cols' => $cols];
    }

    private static function extractConsumer(array $f, int $limit, string $outDir): array
    {
        $pdo = remoteDb('trovacodicefiscale2');
        $pdo->exec("SET SESSION sql_mode = ''");
        $pdo->exec("SET SESSION max_execution_time = 600000");
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        [$where, $params, $joins] = self::buildConsumerWhere($f);
        $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $joinSQL = $joins ? implode("\n", $joins) : '';

        $sql = "SELECT m.tel, m.tel_type, m.cf, m.nome, m.indirizzo, m.provincia
                FROM trovacodicefiscale2.master_cf_numeri m
                $joinSQL $whereSQL
                ORDER BY RAND()
                LIMIT " . (int)$limit;

        $st = $pdo->prepare($sql);
        $st->execute($params);

        $cols = ['tel','tel_type','cf','nome','indirizzo','provincia'];
        $csvPath = $outDir . '/analisi_consumer_' . date('Ymd_His') . '_' . uniqid() . '.csv';
        $fp = fopen($csvPath, 'w');
        fputcsv($fp, $cols);
        $count = 0;
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            fputcsv($fp, $r);
            $count++;
        }
        fclose($fp);

        return ['target' => 'consumer', 'count' => $count, 'csv_path' => $csvPath, 'cols' => $cols];
    }

    // === COSTRUZIONE WHERE: BUSINESS ===
    private static function buildBusinessWhere(array $f): array
    {
        $where = []; $params = []; $joins = [];
        // CONVERT(col USING utf8mb4) → forza la collation client (utf8mb4_general_ci)
        // così i parametri PHP (anch'essi general_ci) matchano senza errore di collation.

        // Tipo telefono
        if ($f['tipo_tel'] === 'mobile') $where[] = "m.tel_type = 'mobile'";
        elseif ($f['tipo_tel'] === 'fisso') $where[] = "m.tel_type = 'fisso'";

        // Regione (richiede mapping provincia → regione)
        if (!empty($f['regioni'])) {
            $provRegioni = self::provinceForRegioni($f['regioni']);
            if ($provRegioni) {
                $ph = implode(',', array_fill(0, count($provRegioni), '?'));
                $where[] = "CONVERT(m.provincia USING utf8mb4) IN ($ph)";
                foreach ($provRegioni as $p) $params[] = $p;
            }
        }

        // Provincia
        if (!empty($f['province'])) {
            $ph = implode(',', array_fill(0, count($f['province']), '?'));
            $where[] = "CONVERT(m.provincia USING utf8mb4) IN ($ph)";
            foreach ($f['province'] as $p) $params[] = $p;
        }

        // Comune
        if (!empty($f['comuni'])) {
            $ors = [];
            foreach ($f['comuni'] as $c) {
                $ors[] = "CONVERT(m.comune USING utf8mb4) LIKE ?";
                $params[] = '%' . $c . '%';
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }

        // CAP
        if (!empty($f['cap'])) {
            $ph = implode(',', array_fill(0, count($f['cap']), '?'));
            $where[] = "CONVERT(m.cap USING utf8mb4) IN ($ph)";
            foreach ($f['cap'] as $c) $params[] = $c;
        }

        // ATECO
        if ($f['ateco'] !== '') {
            $a = $f['ateco'];
            if (preg_match('/^\d{2}$/', $a)) {
                $where[] = "CONVERT(m.ateco USING utf8mb4) LIKE ?";
                $params[] = $a . '%';
            } elseif (preg_match('/^\d+$/', $a)) {
                $where[] = "CONVERT(m.ateco USING utf8mb4) = ?";
                $params[] = $a;
            } else {
                $where[] = "CONVERT(m.ateco USING utf8mb4) LIKE ?";
                $params[] = '%' . $a . '%';
            }
        }

        // Email/PEC/Sito
        if ($f['with_email']) $where[] = "m.email IS NOT NULL AND m.email != ''";
        if ($f['with_pec'])   $where[] = "m.pec IS NOT NULL AND m.pec != ''";
        if ($f['with_sito'])  $where[] = "m.sito_web IS NOT NULL AND m.sito_web != ''";

        // POD/PDR (NOTA: master_piva_numeri non ha campi pod/pdr nativamente — questi vengono dalle fonti energia)
        // Per ora skip, andrà gestito a livello di fonte specifica energia
        // $f['pod_pdr'] sarà rilevante quando importeremo le tabelle ENI/SEN/superpod nel master

        // MAGAZZINO: anti-join
        if (!empty($f['magazzino'])) {
            [$mJoin, $mWhere] = self::buildMagazzinoJoin($f['magazzino'], 'm.tel');
            if ($mJoin) $joins[] = $mJoin;
            if ($mWhere) $where[] = $mWhere;
        }

        return [$where, $params, $joins];
    }

    // === COSTRUZIONE WHERE: CONSUMER ===
    private static function buildConsumerWhere(array $f): array
    {
        $where = []; $params = []; $joins = [];

        // Tipo telefono
        if ($f['tipo_tel'] === 'mobile') $where[] = "m.tel_type = 'mobile'";
        elseif ($f['tipo_tel'] === 'fisso') $where[] = "m.tel_type = 'fisso'";

        // Regione → province
        if (!empty($f['regioni'])) {
            $provRegioni = self::provinceForRegioni($f['regioni']);
            if ($provRegioni) {
                $ph = implode(',', array_fill(0, count($provRegioni), '?'));
                $where[] = "CONVERT(m.provincia USING utf8mb4) IN ($ph)";
                foreach ($provRegioni as $p) $params[] = $p;
            }
        }
        if (!empty($f['province'])) {
            $ph = implode(',', array_fill(0, count($f['province']), '?'));
            $where[] = "CONVERT(m.provincia USING utf8mb4) IN ($ph)";
            foreach ($f['province'] as $p) $params[] = $p;
        }
        if (!empty($f['comuni'])) {
            $ors = [];
            foreach ($f['comuni'] as $c) {
                $ors[] = "CONVERT(m.indirizzo USING utf8mb4) LIKE ?";
                $params[] = '%' . $c . '%';
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }

        // Età (da CF posizioni 7-8)
        $etaMin = $f['eta_min']; $etaMax = $f['eta_max'];
        if ($etaMin !== null || $etaMax !== null) {
            $annoOggi = (int)date('Y');
            $annoMin = $etaMax !== null ? ($annoOggi - $etaMax) : 1900;
            $annoMax = $etaMin !== null ? ($annoOggi - $etaMin) : $annoOggi;
            $yyList = [];
            for ($y = $annoMin; $y <= $annoMax; $y++) $yyList[] = sprintf('%02d', $y % 100);
            $yyList = array_values(array_unique($yyList));
            $ph = implode(',', array_fill(0, count($yyList), '?'));
            $where[] = "LENGTH(m.cf) = 16 AND CONVERT(SUBSTRING(m.cf,7,2) USING utf8mb4) IN ($ph)";
            foreach ($yyList as $y) $params[] = $y;
        }

        // Magazzino anti-join
        if (!empty($f['magazzino'])) {
            [$mJoin, $mWhere] = self::buildMagazzinoJoin($f['magazzino'], 'm.tel');
            if ($mJoin) $joins[] = $mJoin;
            if ($mWhere) $where[] = $mWhere;
        }

        return [$where, $params, $joins];
    }

    // === MAGAZZINO ANTI-JOIN ===
    /**
     * Costruisce il LEFT JOIN per anti-join contro un magazzino.
     * Supporta:
     *   - "clienti.<table>" → magazzino esistente
     *   - "tmp.<table>"     → tabella temp uploaded (in ai_laboratory)
     */
    private static function buildMagazzinoJoin(string $magKey, string $telField): array
    {
        if (strpos($magKey, 'clienti.') === 0) {
            $tbl = substr($magKey, 8);
            $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);  // sanitize
            if ($tbl === '') return [null, null];
            return [
                "LEFT JOIN `clienti`.`$tbl` mag ON CONVERT(mag.mobile USING utf8mb4) = CONVERT($telField USING utf8mb4)",
                "mag.mobile IS NULL"
            ];
        }
        if (strpos($magKey, 'tmp.') === 0) {
            $tbl = substr($magKey, 4);
            $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);
            if ($tbl === '') return [null, null];
            return [
                "LEFT JOIN `ai_laboratory`.`$tbl` mag ON CONVERT(mag.tel USING utf8mb4) = CONVERT($telField USING utf8mb4)",
                "mag.tel IS NULL"
            ];
        }
        return [null, null];
    }

    // === LISTA MAGAZZINI DISPONIBILI ===
    /** Lista delle tabelle in `clienti.*` con dimensione + ultima modifica */
    public static function listMagazzini(int $limit = 200): array
    {
        $pdo = remoteDb('information_schema');
        $rows = $pdo->query("
            SELECT table_name, table_rows, create_time, update_time, data_length
            FROM tables
            WHERE table_schema = 'clienti'
              AND table_rows >= 100
            ORDER BY update_time DESC
            LIMIT " . (int)$limit
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function($r) {
            return [
                'key' => 'clienti.' . $r['table_name'],
                'label' => $r['table_name'],
                'rows' => (int)$r['table_rows'],
                'created' => $r['create_time'],
                'updated' => $r['update_time'],
                'size_kb' => round($r['data_length'] / 1024),
            ];
        }, $rows);
    }

    // === MAGAZZINO TEMP DA UPLOAD ===
    /**
     * Crea una tabella temp in ai_laboratory con i numeri da escludere caricati dall'utente.
     * @param array $tels lista di numeri tel
     * @return string nome chiave magazzino "tmp.<tablename>"
     */
    public static function createTmpMagazzino(array $tels): string
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->exec("SET SESSION sql_mode = ''");

        $tbl = 'tmp_mag_' . date('YmdHis') . '_' . substr(md5(microtime()), 0, 6);
        $pdo->exec("CREATE TABLE `$tbl` (
            tel VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL,
            KEY idx_tel (tel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $batch = [];
        $ins = function() use (&$batch, $pdo, $tbl) {
            if (!$batch) return;
            $ph = implode(',', array_fill(0, count($batch), '(?)'));
            $pdo->prepare("INSERT INTO `$tbl` (tel) VALUES $ph")->execute($batch);
            $batch = [];
        };
        foreach ($tels as $t) {
            $t = trim((string)$t);
            $t = preg_replace('/\D/', '', $t);
            if (strlen($t) < 6) continue;
            $batch[] = substr($t, 0, 20);
            if (count($batch) >= 5000) $ins();
        }
        $ins();

        return 'tmp.' . $tbl;
    }

    /** Drop una temp table magazzino quando finita */
    public static function dropTmpMagazzino(string $magKey): void
    {
        if (strpos($magKey, 'tmp.') !== 0) return;
        $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', substr($magKey, 4));
        if ($tbl === '') return;
        $pdo = remoteDb('ai_laboratory');
        $pdo->exec("DROP TABLE IF EXISTS `$tbl`");
    }

    // === HELPERS GEO ===
    private static $provReg = [
        "AG"=>"Sicilia","CL"=>"Sicilia","CT"=>"Sicilia","EN"=>"Sicilia","ME"=>"Sicilia","PA"=>"Sicilia","RG"=>"Sicilia","SR"=>"Sicilia","TP"=>"Sicilia",
        "AV"=>"Campania","BN"=>"Campania","CE"=>"Campania","NA"=>"Campania","SA"=>"Campania",
        "CH"=>"Abruzzo","AQ"=>"Abruzzo","PE"=>"Abruzzo","TE"=>"Abruzzo",
        "AN"=>"Marche","AP"=>"Marche","FM"=>"Marche","MC"=>"Marche","PU"=>"Marche",
        "PG"=>"Umbria","TR"=>"Umbria",
        "FR"=>"Lazio","LT"=>"Lazio","RI"=>"Lazio","RM"=>"Lazio","VT"=>"Lazio",
        "BO"=>"Emilia-Romagna","FC"=>"Emilia-Romagna","FE"=>"Emilia-Romagna","MO"=>"Emilia-Romagna","PC"=>"Emilia-Romagna","PR"=>"Emilia-Romagna","RA"=>"Emilia-Romagna","RE"=>"Emilia-Romagna","RN"=>"Emilia-Romagna",
        "AR"=>"Toscana","FI"=>"Toscana","GR"=>"Toscana","LI"=>"Toscana","LU"=>"Toscana","MS"=>"Toscana","PI"=>"Toscana","PO"=>"Toscana","PT"=>"Toscana","SI"=>"Toscana",
        "BL"=>"Veneto","PD"=>"Veneto","RO"=>"Veneto","TV"=>"Veneto","VE"=>"Veneto","VI"=>"Veneto","VR"=>"Veneto",
        "BG"=>"Lombardia","BS"=>"Lombardia","CO"=>"Lombardia","CR"=>"Lombardia","LC"=>"Lombardia","LO"=>"Lombardia","MB"=>"Lombardia","MI"=>"Lombardia","MN"=>"Lombardia","PV"=>"Lombardia","SO"=>"Lombardia","VA"=>"Lombardia",
        "AL"=>"Piemonte","AT"=>"Piemonte","BI"=>"Piemonte","CN"=>"Piemonte","NO"=>"Piemonte","TO"=>"Piemonte","VB"=>"Piemonte","VC"=>"Piemonte",
        "GE"=>"Liguria","IM"=>"Liguria","SP"=>"Liguria","SV"=>"Liguria",
        "BA"=>"Puglia","BT"=>"Puglia","BR"=>"Puglia","FG"=>"Puglia","LE"=>"Puglia","TA"=>"Puglia",
        "CS"=>"Calabria","CZ"=>"Calabria","KR"=>"Calabria","RC"=>"Calabria","VV"=>"Calabria",
        "MT"=>"Basilicata","PZ"=>"Basilicata",
        "CA"=>"Sardegna","NU"=>"Sardegna","OR"=>"Sardegna","SS"=>"Sardegna","SU"=>"Sardegna","OT"=>"Sardegna",
        "AO"=>"Valle d'Aosta",
        "BZ"=>"Trentino-Alto Adige","TN"=>"Trentino-Alto Adige",
        "GO"=>"Friuli-Venezia Giulia","PN"=>"Friuli-Venezia Giulia","TS"=>"Friuli-Venezia Giulia","UD"=>"Friuli-Venezia Giulia",
        "CB"=>"Molise","IS"=>"Molise",
    ];

    public static function provinceForRegioni(array $regioni): array
    {
        $regSet = array_map('strtolower', $regioni);
        $out = [];
        foreach (self::$provReg as $p => $r) {
            if (in_array(strtolower($r), $regSet, true)) $out[] = $p;
        }
        return $out;
    }

    public static function regioneForProvincia(string $p): ?string
    {
        $p = strtoupper(trim($p));
        return self::$provReg[$p] ?? null;
    }

    /** Aggrega un dict provincia=>count in regione=>count */
    public static function aggregateByRegione(array $perProv): array
    {
        $perReg = [];
        foreach ($perProv as $p => $c) {
            $r = self::regioneForProvincia($p) ?? 'Altro/N.D.';
            $perReg[$r] = ($perReg[$r] ?? 0) + (int)$c;
        }
        arsort($perReg);
        return $perReg;
    }

    // === AUTH HOOK (per ora bypass) ===
    private static function checkAuth(array $f): void
    {
        if (is_callable(self::$authCheck)) {
            $ok = call_user_func(self::$authCheck, $f);
            if (!$ok) throw new RuntimeException('Non autorizzato');
        }
        // Per ora niente auth — passa sempre
    }

    /** Lista regioni Italia (per dropdown) */
    public static function listRegioni(): array
    {
        return array_values(array_unique(array_values(self::$provReg)));
    }

    /** Lista province (per dropdown) */
    public static function listProvince(): array
    {
        $out = [];
        foreach (self::$provReg as $p => $r) {
            $out[] = ['sigla' => $p, 'regione' => $r];
        }
        usort($out, fn($a,$b) => strcmp($a['sigla'], $b['sigla']));
        return $out;
    }
}
