<?php
/**
 * Estrai Engine — operazioni di supporto al flusso /estrai:
 * - lookup cliente
 * - rilevamento magazzini
 * - stima pool
 * - estrazione effettiva + xlsx + magazzino insert + email duale + registro deliveries
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

class EstraiEngine
{
    /** Mappe zone italiane → regioni */
    public static function regionePerZona(string $zona): array
    {
        $map = [
            'nord' => ['Lombardia','Piemonte','Liguria','Veneto','Friuli-Venezia Giulia','Trentino-Alto Adige','Trentino-Alto Adige/Südtirol','Emilia-Romagna',"Valle d'Aosta"],
            'centro' => ['Toscana','Umbria','Marche','Lazio','Abruzzo'],
            'sud' => ['Campania','Puglia','Basilicata','Molise','Calabria'],
            'isole' => ['Sicilia','Sardegna'],
            'sud e isole' => ['Campania','Puglia','Basilicata','Molise','Calabria','Sicilia','Sardegna'],
            'nord ovest' => ['Lombardia','Piemonte','Liguria',"Valle d'Aosta"],
            'nord est' => ['Veneto','Friuli-Venezia Giulia','Trentino-Alto Adige','Trentino-Alto Adige/Südtirol','Emilia-Romagna'],
        ];
        return $map[strtolower(trim($zona))] ?? [];
    }

    /**
     * Cerca cliente per ragione_sociale / nome / cognome / PIVA / CF + filtri opzionali.
     * $filters:
     *   regione: string|array (es. "Calabria" o ["Sicilia","Calabria"])
     *   zona:    string (es. "sud" → espansa in più regioni via regionePerZona)
     *   provincia: string (es. "PA" o "Palermo")
     *   mesi_ultimo_ordine: int (richiede almeno un ordine negli ultimi N mesi)
     */
    public static function findClienti(string $hint, $filtersOrLimit = [], int $limit = 5): array
    {
        // Backward compat: se il secondo arg è int, era il vecchio $limit
        if (is_int($filtersOrLimit)) { $limit = $filtersOrLimit; $filters = []; }
        else { $filters = $filtersOrLimit ?: []; }
        return self::doFindClienti($hint, $filters, $limit);
    }

    private static function doFindClienti(string $hint, array $filters, int $limit): array
    {
        $pdo  = remoteDb('backoffice');
        $hint = trim($hint);

        // Costruisci clausole e join da applicare a OGNI query (esatta/fuzzy/fallback)
        [$filterWhere, $filterParams, $filterJoin, $filterSelect] = self::buildClienteFilterClauses($filters);

        // 1) Match esatto su PIVA/CF o ragione_sociale completa (se c'è hint)
        if ($hint !== '') {
            $exact = self::runClientiQuery($pdo,
                "(c.partita_iva = ? OR c.codice_fiscale = ? OR c.ragione_sociale = ?)",
                [$hint, $hint, $hint],
                $filterWhere, $filterParams, $filterJoin, $filterSelect, $limit);
            if ($exact) return $exact;
        }

        // Tokenize
        $stopWords = ['e','ed','di','del','della','il','la','&','and','lo','le','i','gli'];
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', strtolower($hint)),
            fn($t) => $t !== '' && !in_array($t, $stopWords) && strlen($t) > 1
        ));

        // 2) Se non ci sono token ma ci sono filtri → ritorna solo per filtri (ordinati per ordine recente)
        if (!$tokens) {
            if ($hint === '' && ($filterWhere !== '' || $filters)) {
                return self::runClientiQuery($pdo, "1=1", [], $filterWhere, $filterParams, $filterJoin, $filterSelect, $limit);
            }
            $like = '%' . $hint . '%';
            return self::runClientiQuery($pdo,
                "(c.ragione_sociale LIKE ? OR c.nome LIKE ? OR c.cognome LIKE ? OR c.partita_iva LIKE ?)",
                [$like,$like,$like,$like],
                $filterWhere, $filterParams, $filterJoin, $filterSelect, $limit);
        }

        // 3) Token AND: tutti i token presenti in almeno uno dei campi (incl. email)
        $conds = []; $params = [];
        foreach ($tokens as $tok) {
            $like = '%' . $tok . '%';
            $conds[] = "(c.ragione_sociale LIKE ? OR c.nome LIKE ? OR c.cognome LIKE ? OR c.partita_iva LIKE ? OR c.codice_fiscale LIKE ? OR c.email LIKE ?)";
            $params = array_merge($params, [$like,$like,$like,$like,$like,$like]);
        }
        $rows = self::runClientiQuery($pdo, implode(' AND ', $conds), $params, $filterWhere, $filterParams, $filterJoin, $filterSelect, $limit);
        if ($rows) return $rows;

        // 4) Fallback: OR tra token (anche email)
        $conds = []; $params = [];
        foreach ($tokens as $tok) {
            $like = '%' . $tok . '%';
            $conds[] = "c.ragione_sociale LIKE ? OR c.nome LIKE ? OR c.cognome LIKE ? OR c.email LIKE ?";
            $params = array_merge($params, [$like,$like,$like,$like]);
        }
        return self::runClientiQuery($pdo, '(' . implode(' OR ', $conds) . ')', $params, $filterWhere, $filterParams, $filterJoin, $filterSelect, $limit);
    }

    /** Costruisce WHERE/JOIN/SELECT extra in base ai filtri (regione/zona/provincia/mesi_ultimo_ordine) */
    private static function buildClienteFilterClauses(array $filters): array
    {
        $where = []; $params = []; $joins = []; $select = '';

        // Regioni esplicite o espanse da zona
        $regioni = [];
        if (!empty($filters['regione'])) {
            $regioni = is_array($filters['regione']) ? $filters['regione'] : [$filters['regione']];
        }
        if (!empty($filters['zona'])) {
            $z = strtolower(trim($filters['zona']));
            $regioni = array_merge($regioni, self::regionePerZona($z));
        }
        if ($regioni) {
            $ph = implode(',', array_fill(0, count($regioni), '?'));
            $where[] = "c.stato IN ($ph)";
            foreach ($regioni as $r) $params[] = $r;
        }

        if (!empty($filters['provincia'])) {
            $where[] = "(c.provincia = ? OR c.comune = ?)";
            $params[] = $filters['provincia'];
            $params[] = $filters['provincia'];
        }

        if (!empty($filters['mesi_ultimo_ordine']) && (int)$filters['mesi_ultimo_ordine'] > 0) {
            $months = (int)$filters['mesi_ultimo_ordine'];
            // JOIN con subquery che ritorna ultimo ordine per cliente
            $joins[] = "INNER JOIN (SELECT cliente_id, MAX(created_at) AS last_order
                                     FROM orders
                                     WHERE created_at >= DATE_SUB(NOW(), INTERVAL $months MONTH)
                                     GROUP BY cliente_id) o ON o.cliente_id = c.id";
            $select = ", o.last_order";
        }

        return [
            $where ? implode(' AND ', $where) : '',
            $params,
            implode("\n", $joins),
            $select,
        ];
    }

    /** Esegue la query base con eventuali filtri aggiuntivi */
    private static function runClientiQuery(PDO $pdo, string $mainWhere, array $mainParams, string $filterWhere, array $filterParams, string $filterJoin, string $filterSelect, int $limit): array
    {
        $whereParts = [];
        if ($mainWhere !== '') $whereParts[] = $mainWhere;
        if ($filterWhere !== '') $whereParts[] = $filterWhere;
        $whereExpr = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

        $order = str_contains($filterSelect, 'last_order') ? 'ORDER BY o.last_order DESC, c.ragione_sociale' : 'ORDER BY c.ragione_sociale';
        $sql = "SELECT c.id, c.ragione_sociale, c.nome, c.cognome, c.partita_iva, c.email, c.comune, c.provincia, c.stato$filterSelect
                FROM clientes c
                $filterJoin
                $whereExpr
                $order
                LIMIT " . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($mainParams, $filterParams));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ultimo prodotto comprato dal cliente. Prima controlla ai_laboratory.deliveries (nostra storia AI),
     * poi fallback a backoffice.orders (storico commerciale).
     * Ritorna array: ['prodotto'=>slug, 'source'=>'deliveries'|'orders'|null, 'data'=>'YYYY-MM-DD', 'nome_originale'=>string]
     */
    public static function getLastProdottoInfo(int $clienteId): ?array
    {
        // 1) deliveries (AI lab)
        $pdo = remoteDb('ai_laboratory');
        $s = $pdo->prepare("SELECT prodotto, DATE(sent_at) d FROM deliveries WHERE cliente_id = ? ORDER BY sent_at DESC LIMIT 1");
        $s->execute([$clienteId]);
        if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
            return ['prodotto'=>$r['prodotto'], 'source'=>'deliveries', 'data'=>$r['d'], 'nome_originale'=>$r['prodotto']];
        }

        // 2) fallback: backoffice.orders
        $pdo = remoteDb('backoffice');
        $s = $pdo->prepare("
            SELECT p.nome, DATE(o.data_ora) d
            FROM orders o
            JOIN prodotti p ON p.id = o.prodotto_id
            WHERE o.cliente_id = ?
            ORDER BY o.data_ora DESC, o.id DESC
            LIMIT 1
        ");
        $s->execute([$clienteId]);
        if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
            $slug = self::normalizeProductName($r['nome']);
            return ['prodotto'=>$slug, 'source'=>'orders', 'data'=>$r['d'], 'nome_originale'=>$r['nome']];
        }
        return null;
    }

    /**
     * Ultima delivery fatta — filtrabile per user_id (chi ha eseguito) o cliente_id.
     * Ritorna la riga di ai_laboratory.deliveries o null.
     */
    public static function getLastDelivery(?int $userId = null, ?int $clienteId = null): ?array
    {
        $pdo = remoteDb('ai_laboratory');
        $where = []; $params = [];
        if ($userId)    { /* deliveries non ha user_id, skippa o adattare */ }
        if ($clienteId) { $where[] = 'cliente_id = ?'; $params[] = $clienteId; }
        $whereExpr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM deliveries $whereExpr ORDER BY sent_at DESC LIMIT 1";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Shortcut: solo lo slug prodotto */
    public static function getLastProdotto(int $clienteId): ?string
    {
        $info = self::getLastProdottoInfo($clienteId);
        return $info ? $info['prodotto'] : null;
    }

    /** Normalizza "Liste Depurazione" -> "depurazione" (slug interno) */
    public static function normalizeProductName(string $name): string
    {
        $lower = strtolower(trim($name));
        $map = [
            'liste energia'                   => 'energia',
            'liste fotovoltaico'              => 'fotovoltaico',
            'liste depurazione'               => 'depurazione',
            'liste telefonia'                 => 'telefonia',
            'liste cessione del quinto'       => 'cessione_quinto',
            'liste finanziarie'               => 'finanziarie',
            'liste generiche'                 => 'generiche',
            'liste generiche (altre categorie)' => 'generiche',
            'liste alimentari'                => 'alimentari',
            'liste immobiliari'               => 'immobiliari',
            'liste cosmetica'                 => 'cosmetica',
            'lead generation / campagne voip' => 'lead_voip',
            'liste gdpr'                      => 'gdpr',
            'digital marketing'               => 'digital_mkt',
        ];
        if (isset($map[$lower])) return $map[$lower];
        foreach ($map as $k => $v) if (str_contains($lower, $k)) return $v;
        // euristica per parole chiave
        foreach (['energia','fotovoltaico','depurazione','telefonia','cessione','finanziari','alimentari','immobiliari','cosmetica','gdpr','voip','digital'] as $kw) {
            if (str_contains($lower, $kw)) return match($kw) {
                'cessione' => 'cessione_quinto',
                'finanziari' => 'finanziarie',
                'voip' => 'lead_voip',
                'digital' => 'digital_mkt',
                default => $kw,
            };
        }
        return 'generiche';
    }

    /** Mapping persistente cliente->magazzino. Ritorna null se mai scelto. */
    public static function getMagazzinoSalvato(int $clienteId): ?array
    {
        $pdo = remoteDb('ai_laboratory');
        $s = $pdo->prepare("SELECT cliente_id, magazzino_tabella, chosen_by_user_id, chosen_at FROM cliente_magazzino WHERE cliente_id = ?");
        $s->execute([$clienteId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public static function setMagazzinoSalvato(int $clienteId, ?string $tabella, int $userId): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("REPLACE INTO cliente_magazzino (cliente_id, magazzino_tabella, chosen_by_user_id) VALUES (?, ?, ?)")
            ->execute([$clienteId, $tabella, $userId]);
    }

    public static function resetMagazzinoSalvato(int $clienteId): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("DELETE FROM cliente_magazzino WHERE cliente_id = ?")->execute([$clienteId]);
    }

    // === CATEGORIA SALVATA per cliente (analoga a cliente_magazzino) ===

    public static function getCategoriaSalvata(int $clienteId): ?string
    {
        $pdo = remoteDb('ai_laboratory');
        $s = $pdo->prepare("SELECT categoria FROM cliente_categoria WHERE cliente_id = ?");
        $s->execute([$clienteId]);
        $r = $s->fetchColumn();
        return $r ?: null;
    }

    public static function setCategoriaSalvata(int $clienteId, string $categoria, int $userId): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("REPLACE INTO cliente_categoria (cliente_id, categoria, chosen_by_user_id) VALUES (?, ?, ?)")
            ->execute([$clienteId, $categoria, $userId]);
    }

    public static function resetCategoriaSalvata(int $clienteId): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("DELETE FROM cliente_categoria WHERE cliente_id = ?")->execute([$clienteId]);
    }

    /** Elenco tabelle magazzino nel DB `clienti` abbinabili al cliente */
    public static function findMagazzini(array $cliente): array
    {
        $pieces = [];
        $rs   = trim((string)($cliente['ragione_sociale'] ?? ''));
        $nome = trim((string)($cliente['nome'] ?? ''));
        $cog  = trim((string)($cliente['cognome'] ?? ''));
        $piva = trim((string)($cliente['partita_iva'] ?? ''));

        // Match ampio: TUTTI i campi disponibili. Se ci sono troppi candidati,
        // l'utente li vede e sceglie quello giusto (logica voluta).
        if ($piva !== '') $pieces[] = $piva;
        if ($rs !== '') {
            $pieces[] = $rs;
            // Tokens significativi della ragione sociale (es. "E-POWER SRL" → "power", "epower")
            $stop = ['srl','spa','sas','snc','srls','sa','scrl','&','di','co','ditta','azienda','impresa','studio','soc','societa','società','del','della'];
            $toks = preg_split('/[\s\-_\.,&\/]+/u', strtolower($rs));
            foreach ($toks as $t) {
                $t = trim($t);
                if (strlen($t) >= 4 && !in_array($t, $stop, true)) $pieces[] = $t;
            }
        }
        if ($cog !== '' && strlen($cog) >= 3) $pieces[] = $cog;
        if ($nome !== '' && strlen($nome) >= 3 && strtolower($nome) !== strtolower($cog)) $pieces[] = $nome;

        $pieces = array_values(array_unique(array_filter($pieces, fn($v) => is_string($v) && trim($v) !== '')));
        if (!$pieces) return [];

        $pdo = remoteDb('information_schema');
        $likes = array_map(fn($p) => '%' . $p . '%', $pieces);
        $placeholders = implode(' OR ', array_fill(0, count($likes), 'table_name LIKE ?'));
        $q = $pdo->prepare("SELECT table_name, table_rows, create_time
                            FROM tables
                            WHERE table_schema = 'clienti' AND ($placeholders)
                            ORDER BY create_time DESC
                            LIMIT 30");
        $q->execute($likes);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Cerca magazzini in clienti.* per keyword libera (usato da handleReply quando user dice "cerca X") */
    public static function searchMagazziniByKeyword(string $keyword, int $limit = 30): array
    {
        $kw = trim($keyword);
        if (strlen($kw) < 2) return [];
        $pdo = remoteDb('information_schema');
        $q = $pdo->prepare("SELECT table_name, table_rows, create_time
                            FROM tables
                            WHERE table_schema = 'clienti' AND table_name LIKE ?
                            ORDER BY create_time DESC LIMIT " . (int)$limit);
        $q->execute(['%' . $kw . '%']);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Scegli la migliore fonte DB per un prodotto (prima disponibile) */
    public static function pickSource(string $prodotto, array $intent = []): array
    {
        $isBusiness = ($intent['filtri']['tipo_target'] ?? '') === 'business';
        $needsPodPdr = !empty($intent['filtri']['pod_pdr']) || in_array($prodotto, ['energia','energia_business'], true);

        // BUSINESS senza POD/PDR → master_piva_numeri
        if ($isBusiness && !$needsPodPdr) {
            return ['db'=>'business','table'=>'master_piva_numeri','year'=>null,'schema'=>'master_piva'];
        }

        // CONSUMER non-energia/non-business → master_cf_numeri (default residenziali)
        // Salta solo se l'utente richiede "approfondita" (multi-fonte) o se è prodotto email (SKY)
        if (!$isBusiness && !$needsPodPdr && $prodotto !== 'email' && empty($intent['filtri']['approfondita'])) {
            return ['db'=>'trovacodicefiscale2','table'=>'master_cf_numeri','year'=>null,'schema'=>'master_cf'];
        }

        // Map prodotto → fonte primaria (db/table/year/schema_type)
        $map = [
            'depurazione'     => ['db'=>'Edicus_2023_marzo','table'=>'superpod_2023','year'=>2023,'schema'=>'superpod'],
            'fotovoltaico'    => ['db'=>'Edicus_2023_marzo','table'=>'superpod_2023','year'=>2023,'schema'=>'superpod'],
            'energia'         => ['db'=>'Edicus_2023_marzo','table'=>'superpod_2023','year'=>2023,'schema'=>'superpod'],
            'telefonia'       => ['db'=>'LIBERO_2020','table'=>'ML_POD_2020','year'=>2020,'schema'=>'libero'],
            'cessione_quinto' => ['db'=>'LIBERO_2020','table'=>'ML_POD_2020','year'=>2020,'schema'=>'libero'],
            'finanziarie'     => ['db'=>'LIBERO_2020','table'=>'ML_POD_2020','year'=>2020,'schema'=>'libero'],
            'immobiliari'     => ['db'=>'LIBERO_2020','table'=>'ML_POD_2020','year'=>2020,'schema'=>'libero'],
            'alimentari'      => ['db'=>'LIBERO_2020','table'=>'ML_POD_2020','year'=>2020,'schema'=>'libero'],
            'cosmetica'       => ['db'=>'LIBERO_2020','table'=>'ML_POD_2020','year'=>2020,'schema'=>'libero'],
            'generiche'       => ['db'=>'LIBERO_2020','table'=>'ML_POD_2020','year'=>2020,'schema'=>'libero'],
            'email'           => ['db'=>'SKY_2023','table'=>'Sky','year'=>2023,'schema'=>'sky'],
        ];
        return $map[$prodotto] ?? $map['generiche'];
    }

    /**
     * Costruisce WHERE + colonne SELECT per il prodotto richiesto, sulla tabella superpod_2023.
     * Supporta le colonne standard (localita, provincia, regione, codice_fiscale, mobile, ...).
     */
    public static function buildQuery(array $intent, array $source, ?string $antijoinTable = null): array
    {
        // Per energia/energia_business: UNION ALL multi-fonte (logica dedicata schema 17 col)
        if (in_array($intent['prodotto'] ?? '', ['energia','energia_business'], true)) {
            return self::buildQueryEnergiaUnion($intent, $antijoinTable);
        }

        // Per tutti gli altri prodotti: prova multi-fonte UNION (allineato alla stat) se più fonti compatibili
        if (class_exists('StatsSources')) {
            try {
                [$sources, , ] = StatsSources::pickForIntent($intent);
                // Filtra fonti che NON sono "speciali" (master/sky email/libero) — quelle hanno path dedicati
                if (count($sources) >= 2) {
                    return self::buildQueryUnionGeneric($intent, $sources, $antijoinTable);
                }
            } catch (\Throwable $e) {
                // fallback su path singolo
            }
        }

        if (($source['schema'] ?? 'superpod') === 'sky') {
            return self::buildQuerySky($intent, $source);
        }
        if (($source['schema'] ?? 'superpod') === 'libero') {
            return self::buildQueryLibero($intent, $source, $antijoinTable);
        }
        if (($source['schema'] ?? 'superpod') === 'master_piva') {
            return self::buildQueryMasterPiva($intent, $source, $antijoinTable);
        }
        if (($source['schema'] ?? 'superpod') === 'master_cf') {
            return self::buildQueryMasterCf($intent, $source, $antijoinTable);
        }
        return self::buildQuerySuperpod($intent, $source, $antijoinTable);
    }

    /**
     * Query su business.master_piva_numeri (master B2B consolidato 5,3M righe).
     * Usato quando filtri.tipo_target='business' E nessun POD/PDR richiesto.
     * Output normalizzato sulle stesse 10 colonne dello schema "non-energia".
     */
    private static function buildQueryMasterPiva(array $intent, array $source, ?string $antijoinTable = null): array
    {
        $where = [];
        $params = [];

        $selectExpr = 'SELECT s.tel AS mobile, '
            . 's.ragione_sociale AS nome, "" AS cognome, '
            . 's.indirizzo AS indirizzo, s.civico AS civico, '
            . 's.comune AS comune, s.cap AS cap, '
            . 's.provincia AS provincia, NULL AS regione, '
            . 'NULL AS data_attivazione';

        $fromExpr = "FROM `business`.`master_piva_numeri` s";
        if ($antijoinTable) {
            $fromExpr .= " LEFT JOIN `clienti`.`" . $antijoinTable . "` h ON CONVERT(h.mobile USING utf8mb4) = CONVERT(s.tel USING utf8mb4)";
            $where[] = "h.mobile IS NULL";
        }

        // Tipo telefono (mobile/fisso/entrambi) — master_piva_numeri ha già tel_type
        $tipo = $intent['filtri']['tipo_telefono'] ?? null;
        if ($tipo === 'mobile') $where[] = "s.tel_type = 'mobile'";
        elseif ($tipo === 'fisso') $where[] = "s.tel_type = 'fisso'";

        $a = $intent['area'] ?? [];
        if (!empty($a['valori']) || (($a['tipo'] ?? '') === 'nazionale')) {
            if (($a['tipo'] ?? '') === 'provincia') {
                $ors = [];
                foreach ($a['valori'] as $v) {
                    $ors[] = "CONVERT(s.provincia USING utf8mb4) = ?"; $params[] = $v;
                    $ors[] = "CONVERT(s.provincia USING utf8mb4) = ?"; $params[] = self::provToSigla($v);
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'regione') {
                // master_piva non ha regione → mappiamo a province
                $regioniProv = [];
                foreach ($a['valori'] as $v) {
                    foreach (self::regioneToProvinces($v) as $p) $regioniProv[] = $p;
                }
                if ($regioniProv) {
                    $ph = implode(',', array_fill(0, count($regioniProv), '?'));
                    $where[] = "CONVERT(s.provincia USING utf8mb4) IN ($ph)";
                    foreach ($regioniProv as $p) $params[] = $p;
                }
            } elseif (($a['tipo'] ?? '') === 'comune') {
                $ors = [];
                foreach ($a['valori'] as $v) { $ors[] = "CONVERT(s.comune USING utf8mb4) = ?"; $params[] = $v; }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'cap') {
                [$capConds, $capParams] = self::buildCapClauses('s.cap', $a['valori']);
                $where[] = '(' . implode(' OR ', $capConds) . ')';
                $params = array_merge($params, $capParams);
            }
        }

        // Filtri B2B: ATECO, email, PEC, sito web
        $f = $intent['filtri'] ?? [];
        if (!empty($f['ateco'])) {
            $a = $f['ateco'];
            if (preg_match('/^\d{2}$/', $a)) { $where[] = "CONVERT(s.ateco USING utf8mb4) LIKE ?"; $params[] = $a . '%'; }
            elseif (preg_match('/^\d+$/', $a)) { $where[] = "CONVERT(s.ateco USING utf8mb4) = ?"; $params[] = $a; }
            else { $where[] = "CONVERT(s.ateco USING utf8mb4) LIKE ?"; $params[] = '%' . $a . '%'; }
        }
        if (!empty($f['with_email'])) $where[] = "s.email IS NOT NULL AND s.email != ''";
        if (!empty($f['with_pec']))   $where[] = "s.pec IS NOT NULL AND s.pec != ''";
        if (!empty($f['with_sito']))  $where[] = "s.sito_web IS NOT NULL AND s.sito_web != ''";
        if (!empty($f['only_mobile'])) $where[] = "s.tel_type = 'mobile'";

        $whereExpr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        // master_piva è già dedup per (piva,tel) — non serve GROUP BY
        $orderLimit = 'ORDER BY RAND() LIMIT ' . (int)$intent['quantita'];
        return ['sql' => "$selectExpr $fromExpr $whereExpr $orderLimit", 'params' => $params];
    }

    /**
     * Query su trovacodicefiscale2.master_cf_numeri (master CF residenziale 40,5M).
     * Default per richieste consumer non-energia/non-business.
     * Output uniformato sulle 10 colonne dello schema "non-energia".
     */
    private static function buildQueryMasterCf(array $intent, array $source, ?string $antijoinTable = null): array
    {
        // NB: master_cf_numeri usa utf8mb4_unicode_ci. Per non rompere gli indici NON usiamo CONVERT()
        // sulle colonne; aggiungiamo COLLATE solo dove serve match con altre tabelle.
        $where = [];
        $params = [];

        $selectExpr = 'SELECT s.tel AS mobile, '
            . 's.nome AS nome, "" AS cognome, '
            . 's.indirizzo AS indirizzo, NULL AS civico, '
            . 'NULL AS comune, NULL AS cap, '
            . 's.provincia AS provincia, NULL AS regione, '
            . 'NULL AS data_attivazione';

        $fromExpr = "FROM `trovacodicefiscale2`.`master_cf_numeri` s";
        if ($antijoinTable) {
            // antijoin con clienti.<table> che ha collation diversa → CONVERT solo qui
            $fromExpr .= " LEFT JOIN `clienti`.`" . $antijoinTable . "` h ON CONVERT(h.mobile USING utf8mb4) COLLATE utf8mb4_unicode_ci = s.tel";
            $where[] = "h.mobile IS NULL";
        }

        // Tipo telefono
        $tipo = $intent['filtri']['tipo_telefono'] ?? null;
        if ($tipo === 'mobile' || !empty($intent['filtri']['only_mobile'])) $where[] = "s.tel_type = 'mobile'";
        elseif ($tipo === 'fisso') $where[] = "s.tel_type = 'fisso'";

        // Area
        $a = $intent['area'] ?? [];
        if (!empty($a['valori']) || (($a['tipo'] ?? '') === 'nazionale')) {
            if (($a['tipo'] ?? '') === 'provincia') {
                $vals = [];
                foreach ($a['valori'] as $v) {
                    $vals[] = $v;
                    $sigla = self::provToSigla($v);
                    if ($sigla && $sigla !== $v) $vals[] = $sigla;
                }
                $vals = array_values(array_unique($vals));
                $ph = implode(',', array_fill(0, count($vals), '?'));
                $where[] = "s.provincia IN ($ph)";
                foreach ($vals as $v) $params[] = $v;
            } elseif (($a['tipo'] ?? '') === 'regione') {
                $regioniProv = [];
                foreach ($a['valori'] as $v) {
                    foreach (self::regioneToProvinces($v) as $p) $regioniProv[] = $p;
                }
                if ($regioniProv) {
                    $ph = implode(',', array_fill(0, count($regioniProv), '?'));
                    $where[] = "s.provincia IN ($ph)";
                    foreach ($regioniProv as $p) $params[] = $p;
                }
            } elseif (($a['tipo'] ?? '') === 'comune') {
                // master_cf non ha colonna comune dedicata → ricerca in indirizzo (lento, no index)
                $ors = [];
                foreach ($a['valori'] as $v) {
                    $ors[] = "s.indirizzo LIKE ?";
                    $params[] = '%' . $v . '%';
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'cap') {
                $ors = [];
                foreach ($a['valori'] as $v) {
                    $ors[] = "s.indirizzo LIKE ?";
                    $params[] = '%' . $v . '%';
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            }
        }

        // No stranieri
        if (!empty($intent['filtri']['no_stranieri'])) {
            $where[] = "LENGTH(s.cf) = 16 AND SUBSTRING(s.cf, 12, 1) != 'Z'";
        }

        // Età (CF posizioni 7-8)
        [$etaConds, $etaParams] = self::buildEtaCfClauses('s.cf', $intent['filtri'] ?? []);
        if ($etaConds) { $where = array_merge($where, $etaConds); $params = array_merge($params, $etaParams); }

        $whereExpr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        // master_cf è già dedup per (cf,tel) — niente GROUP BY (più veloce)
        // ORDER BY RAND() su grandi set è lento → uso pre-filter con LIMIT generoso poi shuffle PHP-side
        $limit = (int)$intent['quantita'];
        $orderLimit = "LIMIT $limit";
        return ['sql' => "$selectExpr $fromExpr $whereExpr $orderLimit", 'params' => $params];
    }

    /** Helper: regione → lista sigle province italiane */
    private static function regioneToProvinces(string $r): array
    {
        $map = [
            'lombardia' => ['BG','BS','CO','CR','LC','LO','MB','MN','MI','PV','SO','VA'],
            'lazio' => ['FR','LT','RI','RM','VT'],
            'campania' => ['AV','BN','CE','NA','SA'],
            'sicilia' => ['AG','CL','CT','EN','ME','PA','RG','SR','TP'],
            'piemonte' => ['AL','AT','BI','CN','NO','TO','VB','VC'],
            'puglia' => ['BA','BT','BR','FG','LE','TA'],
            'veneto' => ['BL','PD','RO','TV','VE','VI','VR'],
            'emilia-romagna' => ['BO','FC','FE','MO','PC','PR','RA','RE','RN'],
            'toscana' => ['AR','FI','GR','LI','LU','MS','PI','PO','PT','SI'],
            'calabria' => ['CS','CZ','KR','RC','VV'],
            'sardegna' => ['CA','NU','OR','SS','SU','OT'],
            'liguria' => ['GE','IM','SP','SV'],
            'marche' => ['AN','AP','FM','MC','PU'],
            'abruzzo' => ['AQ','CH','PE','TE'],
            'umbria' => ['PG','TR'],
            'molise' => ['CB','IS'],
            'basilicata' => ['MT','PZ'],
            'trentino-alto adige' => ['BZ','TN'],
            'friuli-venezia giulia' => ['GO','PN','TS','UD'],
            'valle d\'aosta' => ['AO'],
        ];
        return $map[strtolower(trim($r))] ?? [];
    }

    /**
     * Query UNION ALL multi-fonte per energia/energia_business con dedup per mobile.
     * Ritorna SQL che produce 17 colonne normalizzate + LIMIT N dopo dedup.
     */
    public static function buildQueryEnergiaUnion(array $intent, ?string $antijoinTable = null): array
    {
        $product = $intent['prodotto'];
        $isBusiness = ($product === 'energia_business');

        // Sorgenti normalizzate (SELECT già con alias comuni: mobile, fisso, nome, cognome, cf, piva, indirizzo, civico, comune, cap, provincia, regione, pod, pdr, trader, trader_provenienza, data_attivazione)
        // Schema normalizzato — 17 colonne in questo ordine fisso:
        // mobile, fisso, nome, cognome, cf, piva, indirizzo, civico, comune, cap, provincia, regione, pod, pdr, trader, trader_provenienza, data_attivazione
        $specs = [];

        // Helper per wrappare ogni colonna text in CONVERT(... USING utf8mb4) — evita errori collation mista
        $C = fn(string $expr) => "CONVERT($expr USING utf8mb4)";

        // 1. superpod_2023 (Edicus 2023) — luce residenziale (NO piva)
        if (!$isBusiness) {
            $specs[] = [
                'db'=>'Edicus_2023_marzo', 'table'=>'superpod_2023', 'alias'=>'superpod',
                'mob_col'=>'mobile',
                'select'=> $C('s.mobile')." AS mobile, NULL AS fisso, ".$C('s.nome')." AS nome, ".$C('s.cognome')." AS cognome, ".$C('s.codice_fiscale')." AS cf, NULL AS piva, ".$C('s.indirizzo')." AS indirizzo, ".$C('s.civico')." AS civico, ".$C('s.localita')." AS comune, ".$C('s.cap')." AS cap, ".$C('s.provincia')." AS provincia, ".$C('s.regione')." AS regione, ".$C('s.pod')." AS pod, NULL AS pdr, ".$C('s.trader')." AS trader, NULL AS trader_provenienza, ".$C('s.data_attivazione')." AS data_attivazione",
                'cols'=>['provincia'=>'provincia','regione'=>'regione','comune'=>'localita','cf'=>'codice_fiscale','date'=>'data_attivazione'],
            ];
        }

        // 2. Edicus 2021 Luglio SUPERPOD — POD + PIVA + fisso
        $specs[] = [
            'db'=>'Edicus2021_luglio', 'table'=>'SUPERPOD', 'alias'=>'edicus21',
            'mob_col'=>'mobile',
            'select'=> $C('s.mobile')." AS mobile, ".$C('s.fisso')." AS fisso, NULL AS nome, NULL AS cognome, ".$C('s.CodiceFiscale')." AS cf, ".$C('s.PartitaIva')." AS piva, ".$C('s.Indirizzo')." AS indirizzo, ".$C('s.Civico')." AS civico, ".$C('s.Localita')." AS comune, ".$C('s.CAP')." AS cap, ".$C('s.PROVINCIA')." AS provincia, ".$C('s.regione')." AS regione, ".$C('s.Pod')." AS pod, NULL AS pdr, ".$C('s.Trader')." AS trader, NULL AS trader_provenienza, NULL AS data_attivazione",
            'cols'=>['provincia'=>'PROVINCIA','regione'=>'regione','comune'=>'Localita','cf'=>'CodiceFiscale','date'=>null],
        ];

        // 3. pdr_unificata — PDR puro (gas) con data_decorrenza
        $specs[] = [
            'db'=>'Edicus_2024_maggio', 'table'=>'pdr_unificata', 'alias'=>'pdr',
            'mob_col'=>'mobile',
            'select'=> $C('s.mobile')." AS mobile, ".$C('s.fisso')." AS fisso, NULL AS nome, NULL AS cognome, ".$C('s.cf')." AS cf, ".$C('s.piva')." AS piva, ".$C('s.via')." AS indirizzo, NULL AS civico, ".$C('s.localita')." AS comune, ".$C('s.cap')." AS cap, ".$C('s.provincia')." AS provincia, ".$C('s.regione')." AS regione, NULL AS pod, ".$C('s.cod_pdr')." AS pdr, ".$C('s.societa_vendita_richiedente')." AS trader, ".$C('s.societa_cedente')." AS trader_provenienza, ".$C('s.data_decorrenza')." AS data_attivazione",
            'cols'=>['provincia'=>'provincia','regione'=>'regione','comune'=>'localita','cf'=>'cf','date'=>'data_decorrenza'],
        ];

        // 4. altri_usi_2020 — POD + PIVA + fisso (business)
        if ($isBusiness) {
            $specs[] = [
                'db'=>'altri_usi_2020', 'table'=>'a', 'alias'=>'altri',
                'mob_col'=>'mobile',
                'select'=> $C('s.mobile')." AS mobile, ".$C('s.fisso')." AS fisso, NULL AS nome, NULL AS cognome, ".$C('s.CodiceFiscale')." AS cf, ".$C('s.PartitaIva')." AS piva, ".$C('s.Indirizzo')." AS indirizzo, ".$C('s.Civico')." AS civico, ".$C('s.localita')." AS comune, ".$C('s.CAP')." AS cap, ".$C('s.PROVINCIA')." AS provincia, ".$C('s.regione')." AS regione, ".$C('s.Pod')." AS pod, NULL AS pdr, ".$C('s.Trader')." AS trader, NULL AS trader_provenienza, NULL AS data_attivazione",
                'cols'=>['provincia'=>'PROVINCIA','regione'=>'regione','comune'=>'localita','cf'=>'CodiceFiscale','date'=>null],
            ];
            $specs[] = [
                'db'=>'BUSINESS2025', 'table'=>'business', 'alias'=>'biz',
                'mob_col'=>'CELL',
                'select'=> $C('s.CELL')." AS mobile, NULL AS fisso, NULL AS nome, ".$C('s.RAGIONE_SOCIALE')." AS cognome, NULL AS cf, ".$C('s.PARTITA_IVA')." AS piva, ".$C('s.INDIRIZZO')." AS indirizzo, ".$C('s.CIVICO')." AS civico, ".$C('s.CITTA')." AS comune, ".$C('s.CAP')." AS cap, ".$C('s.PROVINCIA')." AS provincia, ".$C('s.REGIONE')." AS regione, ".$C('s.POD')." AS pod, NULL AS pdr, ".$C('s.TRADER')." AS trader, NULL AS trader_provenienza, NULL AS data_attivazione",
                'cols'=>['provincia'=>'PROVINCIA','regione'=>'REGIONE','comune'=>'CITTA','cf'=>null,'date'=>null],
            ];
        }

        // Se c'è filtro data: mantieni solo fonti con date
        $hasDate = false;
        if (!empty($intent['filtri']['data_att_mese_anno']) || !empty($intent['filtri']['data_att_max_anno_mese']) || !empty($intent['filtri']['data_att_min_anno_mese'])) {
            $hasDate = true;
            $specs = array_values(array_filter($specs, fn($s) => !empty($s['cols']['date'])));
        }

        // Build subqueries
        $subs = []; $params = [];
        $hasPriority = (bool)($hasDate || !empty($intent['filtri']['data_att_mese_anno']));
        foreach ($specs as $spec) {
            $where = ["s.`{$spec['mob_col']}` IS NOT NULL AND s.`{$spec['mob_col']}` != ''"];
            // Priority column: per sorting chronological DESC (newest first)
            if ($hasPriority && !empty($spec['cols']['date'])) {
                $priorityExpr = self::buildDatePriorityExpr("s.`{$spec['cols']['date']}`", $intent['filtri']);
                $priorityCol = $priorityExpr ? ", ($priorityExpr) AS priority_month" : ", 0 AS priority_month";
            } else {
                $priorityCol = ", 0 AS priority_month";
            }

            // Area filter
            $a = $intent['area'] ?? [];
            $provCol = $spec['cols']['provincia'];
            $regCol  = $spec['cols']['regione'];
            $comCol  = $spec['cols']['comune'];
            if (($a['tipo'] ?? '') === 'regione' && $provCol) {
                $sigle = self::regionSigleMap($a['valori'][0] ?? '');
                if ($sigle) {
                    $ph = implode(',', array_fill(0, count($sigle), '?'));
                    $where[] = "s.`$provCol` IN ($ph)";
                    $params = array_merge($params, $sigle);
                }
            } elseif (($a['tipo'] ?? '') === 'provincia' && $provCol) {
                $ors = [];
                foreach ($a['valori'] as $v) {
                    $ors[] = "s.`$provCol` = ?"; $params[] = $v;
                    $ors[] = "s.`$provCol` = ?"; $params[] = self::provToSigla($v);
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'comune' && $comCol) {
                foreach ($a['valori'] as $v) { $where[] = "s.`$comCol` = ?"; $params[] = $v; }
            }

            // No stranieri
            if (!empty($intent['filtri']['no_stranieri']) && !empty($spec['cols']['cf'])) {
                $cfCol = $spec['cols']['cf'];
                $where[] = "LENGTH(s.`$cfCol`) = 16 AND SUBSTRING(s.`$cfCol`, 12, 1) != 'Z'";
            }
            // Filtro età (da CF)
            if (!empty($spec['cols']['cf']) && (($intent['filtri']['eta_min'] ?? null) !== null || ($intent['filtri']['eta_max'] ?? null) !== null)) {
                [$etaConds, $etaParams] = self::buildEtaCfClauses("s.`{$spec['cols']['cf']}`", $intent['filtri'] ?? []);
                if ($etaConds) {
                    $where = array_merge($where, $etaConds);
                    $params = array_merge($params, $etaParams);
                }
            }

            // Date filter
            if ($hasDate && !empty($spec['cols']['date'])) {
                [$dConds, $dParams] = self::buildDateAttivazioneClauses("s.`{$spec['cols']['date']}`", $intent['filtri']);
                if ($dConds) {
                    $where[] = '(' . implode(' AND ', $dConds) . ')';
                    $params = array_merge($params, $dParams);
                }
            }

            // Business: ha piva non vuota
            if ($isBusiness && !empty($spec['cols']['piva'] ?? null)) {
                // già garantito dal fatto che scegliamo le fonti business
            }

            $subs[] = "SELECT " . $spec['select'] . $priorityCol . " FROM `{$spec['db']}`.`{$spec['table']}` s WHERE " . implode(' AND ', $where);
        }

        $unionSql = implode(' UNION ALL ', $subs);

        // Magazzino anti-join a livello finale (sul mobile del dedup)
        $magJoin = '';
        if ($antijoinTable) {
            $magJoin = "LEFT JOIN `clienti`.`$antijoinTable` h ON h.mobile = d.mobile";
        }

        $finalWhere = $antijoinTable ? "WHERE h.mobile IS NULL" : '';
        $limit = (int)$intent['quantita'];

        // ORDER BY: se c'è priority cronologica (data filter attivo), ordina per priority DESC (più recente prima) poi RAND per randomizzare entro lo stesso mese
        $orderBy = $hasPriority ? 'ORDER BY d.priority_month DESC, RAND()' : 'ORDER BY RAND()';

        $sql = "SELECT d.mobile, d.fisso, d.nome, d.cognome, d.cf, d.piva, d.indirizzo, d.civico, d.comune, d.cap, d.provincia, d.regione, d.pod, d.pdr, d.trader, d.trader_provenienza, d.data_attivazione
                FROM (
                    SELECT mobile, MAX(fisso) AS fisso, MAX(nome) AS nome, MAX(cognome) AS cognome,
                           MAX(cf) AS cf, MAX(piva) AS piva, MAX(indirizzo) AS indirizzo, MAX(civico) AS civico,
                           MAX(comune) AS comune, MAX(cap) AS cap, MAX(provincia) AS provincia, MAX(regione) AS regione,
                           MAX(pod) AS pod, MAX(pdr) AS pdr, MAX(trader) AS trader, MAX(trader_provenienza) AS trader_provenienza,
                           MAX(data_attivazione) AS data_attivazione,
                           MAX(priority_month) AS priority_month
                    FROM ($unionSql) u
                    GROUP BY mobile
                ) d
                $magJoin
                $finalWhere
                $orderBy
                LIMIT $limit";

        return ['sql' => $sql, 'params' => $params];
    }

    /** Sigle province per regione — helper locale (duplicato di quelli in FlowStats per non creare dep) */
    private static function regionSigleMap(string $regione): array
    {
        $map = [
            'lazio'=>['RM','LT','FR','RI','VT'],'lombardia'=>['MI','BG','BS','CO','CR','LC','LO','MN','MB','PV','SO','VA'],
            'campania'=>['NA','AV','BN','CE','SA'],'sicilia'=>['PA','CT','ME','AG','CL','EN','RG','SR','TP'],
            'piemonte'=>['TO','AL','AT','BI','CN','NO','VB','VC'],'veneto'=>['VE','BL','PD','RO','TV','VI','VR'],
            'puglia'=>['BA','BR','BT','FG','LE','TA'],'emilia-romagna'=>['BO','FC','FE','MO','PC','PR','RA','RE','RN'],
            'emilia romagna'=>['BO','FC','FE','MO','PC','PR','RA','RE','RN'],
            'toscana'=>['FI','AR','GR','LI','LU','MS','PI','PO','PT','SI'],'calabria'=>['CZ','CS','KR','RC','VV'],
            'liguria'=>['GE','IM','SP','SV'],'marche'=>['AN','AP','FM','MC','PU'],'sardegna'=>['CA','NU','OR','SS','SU'],
            'abruzzo'=>['AQ','CH','PE','TE'],'friuli-venezia giulia'=>['UD','GO','PN','TS'],
            'friuli venezia giulia'=>['UD','GO','PN','TS'],'trentino-alto adige'=>['TN','BZ'],
            'trentino alto adige'=>['TN','BZ'],'umbria'=>['PG','TR'],'basilicata'=>['MT','PZ'],
            'molise'=>['CB','IS'],"valle d'aosta"=>['AO'],
        ];
        return $map[strtolower(trim($regione))] ?? [];
    }

    /** Colonne dell'xlsx in base al prodotto (schema unificato per energia/energia_business) */
    public static function outputColumnsForProduct(string $prodotto, array $intent = []): array
    {
        $withCf = !empty($intent['filtri']['with_extra_numbers'])
               || ($intent['filtri']['eta_min'] ?? null) !== null
               || ($intent['filtri']['eta_max'] ?? null) !== null;
        if (in_array($prodotto, ['energia','energia_business'], true)) {
            return ['Mobile','Fisso','Nome','Cognome','CF','PIVA','Indirizzo','Civico','Comune','CAP','Provincia','Regione','POD','PDR','Trader','Trader_Provenienza','Data_Attivazione'];
        }
        // Per filtri età o numeri extra aggiungiamo CF + AnnoNascita
        if ($withCf) {
            return ['Mobile','Nome','Cognome','CF','AnnoNascita','Indirizzo','Civico','Comune','CAP','Provincia','Regione','Data_Attivazione'];
        }
        return ['Mobile','Nome','Cognome','Indirizzo','Civico','Comune','CAP','Provincia','Regione','Data_Attivazione'];
    }

    public static function columnWidthsForProduct(string $prodotto, array $intent = []): array
    {
        $withCf = !empty($intent['filtri']['with_extra_numbers'])
               || ($intent['filtri']['eta_min'] ?? null) !== null
               || ($intent['filtri']['eta_max'] ?? null) !== null;
        if (in_array($prodotto, ['energia','energia_business'], true)) {
            return [14, 14, 15, 18, 18, 14, 28, 8, 22, 8, 10, 14, 16, 16, 22, 22, 14];
        }
        if ($withCf) {
            return [14, 15, 18, 18, 8, 28, 8, 22, 8, 10, 14, 14];
        }
        return [14, 15, 18, 28, 8, 22, 8, 10, 14, 14];
    }

    /**
     * Costruisce WHERE per filtro età da CF (posizioni 7-8 = anno nascita 2-cifre).
     * Ritorna [conds[], params[]]. Se $annoCol != null, usa quella colonna (anno a 4 cifre) invece di SUBSTRING.
     */
    public static function buildEtaCfClauses(string $cfCol, array $filtri, ?string $annoCol = null): array
    {
        $conds = []; $params = [];
        $etaMin = $filtri['eta_min'] ?? null;
        $etaMax = $filtri['eta_max'] ?? null;
        if ($etaMin === null && $etaMax === null) return [$conds, $params];
        $annoOggi = (int)date('Y');
        $annoMin = $etaMax !== null ? ($annoOggi - (int)$etaMax) : 1900;
        $annoMax = $etaMin !== null ? ($annoOggi - (int)$etaMin) : $annoOggi;
        if ($annoCol) {
            // Colonna anno a 4 cifre (es. anno_cf in Edicus2021_luglio)
            $conds[] = "$annoCol BETWEEN ? AND ?";
            $params[] = $annoMin;
            $params[] = $annoMax;
        } else {
            // SUBSTRING(CF, 7, 2) — lista YY (2 cifre) negli anni [annoMin..annoMax]
            $yyList = [];
            for ($y = $annoMin; $y <= $annoMax; $y++) $yyList[] = sprintf('%02d', $y % 100);
            $yyList = array_values(array_unique($yyList));
            $ph = implode(',', array_fill(0, count($yyList), '?'));
            $conds[] = "LENGTH($cfCol) = 16 AND SUBSTRING($cfCol,7,2) IN ($ph)";
            foreach ($yyList as $y) $params[] = $y;
        }
        return [$conds, $params];
    }

    /**
     * Query UNION ALL multi-fonte per prodotti NON energia (fotovoltaico/depurazione/telefonia/ecc.)
     * Dedup per mobile + anti-join magazzino.
     * Output uniformato sulle 10 colonne dello schema "non-energia".
     */
    public static function buildQueryUnionGeneric(array $intent, array $sources, ?string $antijoinTable = null): array
    {
        $unions = []; $params = [];

        foreach ($sources as $src) {
            $c = $src['cols'] ?? [];
            $mobCol = $c['mobile'] ?? null;
            if (!$mobCol) continue;
            $provCol = $c['provincia'] ?? null;
            $regCol  = $c['regione'] ?? null;
            $comCol  = $c['comune']  ?? null;
            $cfCol   = $c['cf']      ?? null;
            $indCol  = $c['indirizzo'] ?? null;  // potrebbe non esistere
            $civCol  = $c['civico'] ?? null;
            $capCol  = $c['cap'] ?? null;
            $nomeCol = $c['nome'] ?? ($c['ragsoc'] ?? null);  // fallback: ragsoc per sky/biz
            $cogCol  = $c['cognome'] ?? null;
            $dateCol = $c['date'] ?? null;

            $where = ["s.`$mobCol` IS NOT NULL AND s.`$mobCol` != ''"];

            // AREA
            $a = $intent['area'] ?? [];
            if (($a['tipo'] ?? '') === 'provincia') {
                if (!$provCol) continue;
                $ors = [];
                foreach ($a['valori'] as $v) {
                    $ors[] = "s.`$provCol` = ?"; $params[] = $v;
                    $ors[] = "s.`$provCol` = ?"; $params[] = self::provToSigla($v);
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'regione') {
                if ($provCol) {
                    $ors = [];
                    foreach ($a['valori'] as $v) {
                        $sigle = self::regioneToProvinces($v);
                        if ($sigle) {
                            $ph = implode(',', array_fill(0, count($sigle), '?'));
                            $ors[] = "s.`$provCol` IN ($ph)";
                            foreach ($sigle as $sig) $params[] = $sig;
                        } elseif ($regCol) {
                            $ors[] = "s.`$regCol` LIKE ?"; $params[] = '%' . $v . '%';
                        }
                    }
                    if ($ors) $where[] = '(' . implode(' OR ', $ors) . ')';
                } elseif ($regCol) {
                    $ors = [];
                    foreach ($a['valori'] as $v) { $ors[] = "s.`$regCol` LIKE ?"; $params[] = '%' . $v . '%'; }
                    $where[] = '(' . implode(' OR ', $ors) . ')';
                }
            } elseif (($a['tipo'] ?? '') === 'comune') {
                if (!$comCol) continue;
                $ors = [];
                foreach ($a['valori'] as $v) { $ors[] = "s.`$comCol` = ?"; $params[] = $v; }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'cap' && $capCol) {
                [$capConds, $capParams] = self::buildCapClauses("s.`$capCol`", $a['valori']);
                $where[] = '(' . implode(' OR ', $capConds) . ')';
                $params = array_merge($params, $capParams);
            }

            // No stranieri
            if (!empty($intent['filtri']['no_stranieri']) && $cfCol) {
                $where[] = "LENGTH(s.`$cfCol`) = 16 AND SUBSTRING(s.`$cfCol`, 12, 1) != 'Z'";
            }

            // Età (filtro CF)
            [$etaConds, $etaParams] = self::buildEtaCfClauses("s.`$cfCol`", $intent['filtri'] ?? []);
            if ($etaConds && $cfCol) { $where = array_merge($where, $etaConds); $params = array_merge($params, $etaParams); }

            // Date filter (solo se fonte ha colonna data)
            if ($dateCol) {
                [$dConds, $dParams] = self::buildDateAttivazioneClauses("s.`$dateCol`", $intent['filtri'] ?? []);
                if ($dConds) {
                    $where[] = '(' . implode(' AND ', $dConds) . ')';
                    $params = array_merge($params, $dParams);
                }
            }

            // SELECT — schema unificato 10 colonne (NULL dove la fonte non ha)
            $selExpr = 'SELECT '
              . "CONVERT(s.`$mobCol` USING utf8mb4) AS mobile, "
              . ($nomeCol ? "CONVERT(s.`$nomeCol` USING utf8mb4)" : "''") . " AS nome, "
              . ($cogCol  ? "CONVERT(s.`$cogCol` USING utf8mb4)" : "''") . " AS cognome, "
              . ($indCol  ? "CONVERT(s.`$indCol` USING utf8mb4)" : "NULL") . " AS indirizzo, "
              . ($civCol  ? "CONVERT(s.`$civCol` USING utf8mb4)" : "NULL") . " AS civico, "
              . ($comCol  ? "CONVERT(s.`$comCol` USING utf8mb4)" : "NULL") . " AS comune, "
              . ($capCol  ? "CONVERT(s.`$capCol` USING utf8mb4)" : "NULL") . " AS cap, "
              . ($provCol ? "CONVERT(s.`$provCol` USING utf8mb4)" : "NULL") . " AS provincia, "
              . ($regCol  ? "CONVERT(s.`$regCol` USING utf8mb4)" : "NULL") . " AS regione, "
              . ($dateCol ? "CONVERT(s.`$dateCol` USING utf8mb4)" : "NULL") . " AS data_attivazione";

            $magJoin = '';
            if ($antijoinTable) {
                $magJoin = " LEFT JOIN `clienti`.`" . $antijoinTable . "` h ON CONVERT(h.mobile USING utf8mb4) = CONVERT(s.`$mobCol` USING utf8mb4)";
                $where[] = "h.mobile IS NULL";
            }

            $unions[] = $selExpr . " FROM `" . $src['db'] . "`.`" . $src['table'] . "` s $magJoin WHERE " . implode(' AND ', $where);
        }

        if (!$unions) {
            // fallback estremo: nessuna fonte usabile → ritorna query vuota
            return ['sql' => 'SELECT NULL AS mobile WHERE 1=0', 'params' => []];
        }

        $unionSql = '(' . implode(') UNION ALL (', $unions) . ')';
        // Outer: dedup mobile + LIMIT N
        $sql = "SELECT mobile, MAX(nome) AS nome, MAX(cognome) AS cognome, MAX(indirizzo) AS indirizzo,
                       MAX(civico) AS civico, MAX(comune) AS comune, MAX(cap) AS cap,
                       MAX(provincia) AS provincia, MAX(regione) AS regione, MAX(data_attivazione) AS data_attivazione
                FROM ($unionSql) u
                GROUP BY mobile
                ORDER BY RAND()
                LIMIT " . (int)$intent['quantita'];

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Query per LIBERO_2020.ML_POD_2020 (schema libero) — colonne diverse da superpod.
     * Output alias normalizzati su nome/cognome/indirizzo/civico/comune/cap/provincia/regione/data_attivazione
     * così che il writer xlsx li trovi come per le altre fonti.
     */
    private static function buildQueryLibero(array $intent, array $source, ?string $antijoinTable = null): array
    {
        $where = [];
        $params = [];

        $withCf = !empty($intent['filtri']['with_extra_numbers'])
               || ($intent['filtri']['eta_min'] ?? null) !== null
               || ($intent['filtri']['eta_max'] ?? null) !== null;
        if ($withCf) {
            $selectExpr = 'SELECT s.mobile, '
                . 'MAX(s.NomeCliente) AS nome, "" AS cognome, '
                . 'MAX(s.CodiceFiscale) AS cf, '
                . 'MAX(s.anno_cf) AS anno_nascita, '
                . 'MAX(s.Indirizzo) AS indirizzo, MAX(s.Civico) AS civico, '
                . 'MAX(s.Localita) AS comune, MAX(s.CAP) AS cap, '
                . 'MAX(s.provincia) AS provincia, MAX(s.regione) AS regione, '
                . 'MAX(s.DataEmissione) AS data_attivazione';
        } else {
            $selectExpr = 'SELECT s.mobile, '
                . 'MAX(s.NomeCliente) AS nome, "" AS cognome, '
                . 'MAX(s.Indirizzo) AS indirizzo, MAX(s.Civico) AS civico, '
                . 'MAX(s.Localita) AS comune, MAX(s.CAP) AS cap, '
                . 'MAX(s.provincia) AS provincia, MAX(s.regione) AS regione, '
                . 'MAX(s.DataEmissione) AS data_attivazione';
        }

        $fromExpr = "FROM `" . $source['db'] . "`.`" . $source['table'] . "` s";
        if ($antijoinTable) {
            $fromExpr .= " LEFT JOIN `clienti`.`" . $antijoinTable . "` h ON h.mobile = s.mobile";
            $where[] = "h.mobile IS NULL";
        }

        $a = $intent['area'] ?? [];
        if (!empty($a['valori']) || (($a['tipo'] ?? '') === 'nazionale')) {
            if (($a['tipo'] ?? '') === 'provincia') {
                $ors = [];
                foreach ($a['valori'] as $v) {
                    $ors[] = "s.provincia = ?"; $params[] = $v;
                    $ors[] = "s.provincia = ?"; $params[] = self::provToSigla($v);
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'regione') {
                $ors = [];
                foreach ($a['valori'] as $v) { $ors[] = "s.regione = ?"; $params[] = $v; }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'comune') {
                $ors = [];
                foreach ($a['valori'] as $v) { $ors[] = "s.Localita = ?"; $params[] = $v; }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'cap') {
                [$capConds, $capParams] = self::buildCapClauses('s.CAP', $a['valori']);
                $where[] = '(' . implode(' OR ', $capConds) . ')';
                $params = array_merge($params, $capParams);
            }
        }
        if (!empty($intent['filtri']['no_stranieri'])) {
            $where[] = "LENGTH(s.CodiceFiscale) = 16 AND SUBSTRING(s.CodiceFiscale, 12, 1) != 'Z'";
        }
        if (!empty($intent['filtri']['only_mobile'])) {
            $where[] = "s.mobile IS NOT NULL AND s.mobile != ''";
        }
        // Filtro età (da CF)
        [$etaConds, $etaParams] = self::buildEtaCfClauses('s.CodiceFiscale', $intent['filtri'] ?? []);
        if ($etaConds) { $where = array_merge($where, $etaConds); $params = array_merge($params, $etaParams); }

        $whereExpr  = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $groupBy    = 'GROUP BY s.mobile';
        $orderLimit = 'ORDER BY RAND() LIMIT ' . (int)$intent['quantita'];
        return ['sql' => "$selectExpr $fromExpr $whereExpr $groupBy $orderLimit", 'params' => $params];
    }

    private static function buildQuerySuperpod(array $intent, array $source, ?string $antijoinTable = null): array
    {
        $where = [];
        $params = [];
        $prodotto = $intent['prodotto'] ?? '';
        // DEDUP per mobile: aggregate con MAX() così ogni cellulare compare 1 sola volta nell'output
        if (in_array($prodotto, ['energia','energia_business'], true)) {
            $selectExpr = 'SELECT '
                . 's.mobile, NULL AS fisso, '
                . 'MAX(s.nome) AS nome, MAX(s.cognome) AS cognome, '
                . 'MAX(s.codice_fiscale) AS cf, NULL AS piva, '
                . 'MAX(s.indirizzo) AS indirizzo, MAX(s.civico) AS civico, '
                . 'MAX(s.localita) AS comune, MAX(s.cap) AS cap, '
                . 'MAX(s.provincia) AS provincia, MAX(s.regione) AS regione, '
                . 'MAX(s.pod) AS pod, NULL AS pdr, MAX(s.trader) AS trader, NULL AS trader_provenienza, '
                . 'MAX(s.data_attivazione) AS data_attivazione';
        } else {
            $withCf = !empty($intent['filtri']['with_extra_numbers'])
                   || ($intent['filtri']['eta_min'] ?? null) !== null
                   || ($intent['filtri']['eta_max'] ?? null) !== null;
            if ($withCf) {
                $selectExpr = 'SELECT s.mobile, '
                    . 'MAX(s.nome) AS nome, MAX(s.cognome) AS cognome, '
                    . 'MAX(s.codice_fiscale) AS cf, '
                    . 'CASE WHEN LENGTH(MAX(s.codice_fiscale))=16 THEN '
                    . '  CASE WHEN CAST(SUBSTRING(MAX(s.codice_fiscale),7,2) AS UNSIGNED) <= 30 '
                    . '       THEN 2000 + CAST(SUBSTRING(MAX(s.codice_fiscale),7,2) AS UNSIGNED) '
                    . '       ELSE 1900 + CAST(SUBSTRING(MAX(s.codice_fiscale),7,2) AS UNSIGNED) '
                    . '  END ELSE NULL END AS anno_nascita, '
                    . 'MAX(s.indirizzo) AS indirizzo, MAX(s.civico) AS civico, '
                    . 'MAX(s.localita) AS comune, MAX(s.cap) AS cap, '
                    . 'MAX(s.provincia) AS provincia, MAX(s.regione) AS regione, '
                    . 'MAX(s.data_attivazione) AS data_attivazione';
            } else {
                $selectExpr = 'SELECT s.mobile, '
                    . 'MAX(s.nome) AS nome, MAX(s.cognome) AS cognome, '
                    . 'MAX(s.indirizzo) AS indirizzo, MAX(s.civico) AS civico, '
                    . 'MAX(s.localita) AS comune, MAX(s.cap) AS cap, '
                    . 'MAX(s.provincia) AS provincia, MAX(s.regione) AS regione, '
                    . 'MAX(s.data_attivazione) AS data_attivazione';
            }
        }
        $fromExpr   = "FROM `" . $source['db'] . "`.`" . $source['table'] . "` s";

        if ($antijoinTable) {
            $fromExpr .= " LEFT JOIN `clienti`.`" . $antijoinTable . "` h ON h.mobile = s.mobile";
            $where[] = "h.mobile IS NULL";
        }

        $a = $intent['area'] ?? [];
        if (!empty($a['valori']) || (($a['tipo'] ?? '') === 'nazionale')) {
            if (($a['tipo'] ?? '') === 'provincia') {
                $ors = [];
                foreach ($a['valori'] as $v) {
                    $ors[] = "s.provincia = ?"; $params[] = $v;
                    $ors[] = "s.provincia = ?"; $params[] = self::provToSigla($v);
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'regione') {
                $ors = [];
                foreach ($a['valori'] as $v) { $ors[] = "s.regione = ?"; $params[] = $v; }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'comune') {
                $ors = [];
                foreach ($a['valori'] as $v) { $ors[] = "s.localita = ?"; $params[] = $v; }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'cap') {
                [$capConds, $capParams] = self::buildCapClauses('s.cap', $a['valori']);
                $where[] = '(' . implode(' OR ', $capConds) . ')';
                $params = array_merge($params, $capParams);
            }
        }
        if (!empty($intent['filtri']['no_stranieri'])) {
            $where[] = "LENGTH(s.codice_fiscale) = 16 AND SUBSTRING(s.codice_fiscale, 12, 1) != 'Z'";
        }
        if (!empty($intent['filtri']['only_mobile'])) {
            $where[] = "s.mobile IS NOT NULL AND s.mobile != ''";
        }
        // Filtro età (da CF)
        [$etaConds, $etaParams] = self::buildEtaCfClauses('s.codice_fiscale', $intent['filtri'] ?? []);
        if ($etaConds) { $where = array_merge($where, $etaConds); $params = array_merge($params, $etaParams); }

        // Filtro data_attivazione (formato DD-MON-YY, es. "01-APR-26")
        [$dateConds, $dateParams] = self::buildDateAttivazioneClauses('s.data_attivazione', $intent['filtri'] ?? []);
        if ($dateConds) {
            $where[] = '(' . implode(' AND ', $dateConds) . ')';
            $params = array_merge($params, $dateParams);
        }

        $whereExpr  = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $groupBy    = 'GROUP BY s.mobile';   // dedup per mobile
        // Priority cronologica se filtro data attivo → ordina dal mese più recente
        $priority = self::buildDatePriorityExpr('s.data_attivazione', $intent['filtri'] ?? []);
        $orderLimit = $priority
            ? "ORDER BY MAX($priority) DESC, RAND() LIMIT " . (int)$intent['quantita']
            : 'ORDER BY RAND() LIMIT ' . (int)$intent['quantita'];
        return ['sql' => "$selectExpr $fromExpr $whereExpr $groupBy $orderLimit", 'params' => $params];
    }

    /**
     * Clausole WHERE per filtro data su colonna varchar — supporta formati misti:
     *  - DD-MON-YY (es. "01-APR-26")
     *  - YYYY-MM-DD (es. "2026-04-01")
     *  - DD/MM/YYYY (es. "01/04/2026")
     *
     * Generà pattern LIKE multipli (niente STR_TO_DATE perché lento su 5M record senza indice).
     * I valori year/month sono derivati da preg_match + int cast, safe da SQL injection.
     */
    public static function buildDateAttivazioneClauses(string $col, array $filtri): array
    {
        $conds = []; $params = [];

        // Mesi/anno specifici (es. ["APR-26"])
        if (!empty($filtri['data_att_mese_anno']) && is_array($filtri['data_att_mese_anno'])) {
            $allOrs = [];
            foreach ($filtri['data_att_mese_anno'] as $mesi) {
                $mesi = strtoupper(trim($mesi));
                if (preg_match('/^([A-Z]{3})-(\d{2})$/', $mesi, $m)) {
                    $mon = $m[1]; $yy = (int)$m[2];
                    $year = ($yy <= 50) ? (2000 + $yy) : (1900 + $yy);
                    $month = self::monthIndex($mon);
                    if ($month > 0) {
                        $allOrs = array_merge($allOrs, self::patternsForMonth($col, $year, $month));
                    }
                }
            }
            if ($allOrs) $conds[] = '(' . implode(' OR ', $allOrs) . ')';
        }

        // Max anno-mese (fino a X incluso, a ritroso fino a 5 anni prima)
        if (!empty($filtri['data_att_max_anno_mese'])) {
            if (preg_match('/^(\d{4})-(\d{2})$/', $filtri['data_att_max_anno_mese'], $m)) {
                $year = (int)$m[1]; $month = (int)$m[2];
                $allOrs = [];
                for ($y = $year; $y >= $year - 5; $y--) {
                    $maxM = ($y === $year) ? $month : 12;
                    for ($mm = 1; $mm <= $maxM; $mm++) {
                        $allOrs = array_merge($allOrs, self::patternsForMonth($col, $y, $mm));
                    }
                }
                if ($allOrs) $conds[] = '(' . implode(' OR ', $allOrs) . ')';
            }
        }

        // Min anno-mese (da X in poi, fino a 3 anni avanti)
        if (!empty($filtri['data_att_min_anno_mese'])) {
            if (preg_match('/^(\d{4})-(\d{2})$/', $filtri['data_att_min_anno_mese'], $m)) {
                $year = (int)$m[1]; $month = (int)$m[2];
                $allOrs = [];
                for ($y = $year; $y <= $year + 3; $y++) {
                    $minM = ($y === $year) ? $month : 1;
                    for ($mm = $minM; $mm <= 12; $mm++) {
                        $allOrs = array_merge($allOrs, self::patternsForMonth($col, $y, $mm));
                    }
                }
                if ($allOrs) $conds[] = '(' . implode(' OR ', $allOrs) . ')';
            }
        }

        return [$conds, $params];
    }

    /**
     * Genera ORDER BY expression che ordina per priorità mese (più recente prima).
     * Usa lo stesso pattern matching di patternsForMonth per riconoscere i 3 formati misti.
     * Ritorna stringa SQL da usare in ORDER BY — es. "CASE WHEN col LIKE 'APR-26' THEN 202604 WHEN ... END DESC".
     * Se filtri.data_att_max_anno_mese è presente, genera priorità per i mesi <= max per 5 anni indietro.
     * Se data_att_min_anno_mese presente, genera priorità per mesi >= min in avanti.
     * Se data_att_mese_anno (lista), priorità discendente per i mesi citati.
     */
    public static function buildDatePriorityExpr(string $col, array $filtri): ?string
    {
        $pairs = []; // [ [year, month, priority], ... ]
        $months = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];

        if (!empty($filtri['data_att_max_anno_mese']) && preg_match('/^(\d{4})-(\d{2})$/', $filtri['data_att_max_anno_mese'], $m)) {
            $year = (int)$m[1]; $month = (int)$m[2];
            for ($y = $year; $y >= $year - 5; $y--) {
                $maxM = ($y === $year) ? $month : 12;
                for ($mm = $maxM; $mm >= 1; $mm--) $pairs[] = [$y, $mm, $y * 100 + $mm];
            }
        } elseif (!empty($filtri['data_att_min_anno_mese']) && preg_match('/^(\d{4})-(\d{2})$/', $filtri['data_att_min_anno_mese'], $m)) {
            $year = (int)$m[1]; $month = (int)$m[2];
            for ($y = $year + 3; $y >= $year; $y--) {
                $minM = ($y === $year) ? $month : 1;
                for ($mm = 12; $mm >= $minM; $mm--) $pairs[] = [$y, $mm, $y * 100 + $mm];
            }
        } elseif (!empty($filtri['data_att_mese_anno']) && is_array($filtri['data_att_mese_anno'])) {
            foreach ($filtri['data_att_mese_anno'] as $ma) {
                if (preg_match('/^([A-Z]{3})-(\d{2})$/', strtoupper($ma), $pm)) {
                    $y = (int)$pm[2] <= 50 ? 2000 + (int)$pm[2] : 1900 + (int)$pm[2];
                    $mm = array_search($pm[1], $months) + 1;
                    if ($mm > 0) $pairs[] = [$y, $mm, $y * 100 + $mm];
                }
            }
        }
        if (!$pairs) return null;

        // Elimina duplicati + ordina per priorità desc
        $seen = []; $unique = [];
        foreach ($pairs as $p) { if (!isset($seen[$p[2]])) { $seen[$p[2]] = 1; $unique[] = $p; } }
        usort($unique, fn($a,$b) => $b[2] - $a[2]);

        $cases = [];
        foreach ($unique as [$y, $mm, $prio]) {
            $yy = sprintf('%02d', $y % 100);
            $yyyy = sprintf('%04d', $y);
            $mmStr = sprintf('%02d', $mm);
            $monAbbr = $months[$mm - 1];
            $cases[] = "WHEN UPPER($col) LIKE '%-$monAbbr-$yy' THEN $prio";
            $cases[] = "WHEN $col LIKE '$yyyy-$mmStr-%' THEN $prio";
            $cases[] = "WHEN $col LIKE '%/$mmStr/$yyyy' THEN $prio";
        }

        return 'CASE ' . implode(' ', $cases) . ' ELSE 0 END';
    }

    /**
     * Genera 3 pattern LIKE (inline literals, safe) per matchare una colonna in un mese/anno dato
     * coprendo i 3 formati misti possibili nelle fonti.
     */
    private static function patternsForMonth(string $col, int $year, int $month): array
    {
        $months = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
        $mon  = $months[$month - 1] ?? null;
        if ($mon === null) return [];
        $yy   = sprintf('%02d', $year % 100);
        $yyyy = sprintf('%04d', $year);
        $mm   = sprintf('%02d', $month);
        return [
            "UPPER($col) LIKE '%-$mon-$yy'",   // DD-MON-YY  (superpod_2023 54%)
            "$col LIKE '$yyyy-$mm-%'",         // YYYY-MM-DD (ISO — entrambi)
            "$col LIKE '%/$mm/$yyyy'",         // DD/MM/YYYY (pdr_unificata 96%)
        ];
    }

    private static function monthIndex(string $monAbbr): int
    {
        static $map = ['JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12];
        return $map[strtoupper($monAbbr)] ?? 0;
    }

    /** Query per SKY_2023.Sky (schema diverso, orientato all'email) */
    private static function buildQuerySky(array $intent, array $source): array
    {
        $where  = ["s.Email IS NOT NULL AND s.Email LIKE '%@%'"];
        $params = [];

        $a = $intent['area'] ?? [];
        if (!empty($a['valori'])) {
            if (($a['tipo'] ?? '') === 'regione') {
                $ors = [];
                foreach ($a['valori'] as $v) {
                    $ors[] = "s.REGIONE LIKE ?"; $params[] = '%' . $v . '%';
                    $ors[] = "s.PROVINCIA IN (" . self::provSigleRegione($v) . ")"; // inline, niente placeholders
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'provincia') {
                $ors = [];
                foreach ($a['valori'] as $v) {
                    $ors[] = "s.PROVINCIA = ?"; $params[] = $v;
                    $ors[] = "s.PROVINCIA = ?"; $params[] = self::provToSigla($v);
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'comune') {
                $ors = [];
                foreach ($a['valori'] as $v) { $ors[] = "s.comune = ?"; $params[] = $v; }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'cap') {
                [$capConds, $capParams] = self::buildCapClauses('s.CAP', $a['valori']);
                $where[] = '(' . implode(' OR ', $capConds) . ')';
                $params = array_merge($params, $capParams);
            }
        }

        // Dedup per email (ogni email 1 sola volta)
        $selectExpr = 'SELECT s.Email, MAX(s.NomeCognome) AS NomeCognome, MAX(s.Telefono) AS Telefono, MAX(s.Indirizzo) AS Indirizzo, MAX(s.comune) AS comune, MAX(s.CAP) AS CAP, MAX(s.PROVINCIA) AS PROVINCIA, MAX(s.REGIONE) AS REGIONE';
        $fromExpr   = "FROM `" . $source['db'] . "`.`" . $source['table'] . "` s";
        $whereExpr  = 'WHERE ' . implode(' AND ', $where);
        $groupBy    = 'GROUP BY s.Email';
        $limit      = 'LIMIT ' . (int)$intent['quantita'];
        return ['sql' => "$selectExpr $fromExpr $whereExpr $groupBy $limit", 'params' => $params];
    }

    /** Sigle provincia per una regione (inline per velocità) */
    private static function provSigleRegione(string $regione): string
    {
        $map = [
            'lazio'     => ['RM','LT','FR','RI','VT'],
            'lombardia' => ['MI','BG','BS','CO','CR','LC','LO','MN','MB','PV','SO','VA'],
            'campania'  => ['NA','AV','BN','CE','SA'],
            'sicilia'   => ['PA','CT','ME','AG','CL','EN','RG','SR','TP'],
            'piemonte'  => ['TO','AL','AT','BI','CN','NO','VB','VC'],
            'veneto'    => ['VE','BL','PD','RO','TV','VI','VR'],
            'puglia'    => ['BA','BR','BT','FG','LE','TA'],
            'emilia-romagna' => ['BO','FC','FE','MO','PC','PR','RA','RE','RN'],
            'emilia romagna' => ['BO','FC','FE','MO','PC','PR','RA','RE','RN'],
            'toscana'   => ['FI','AR','GR','LI','LU','MS','PI','PO','PT','SI'],
            'calabria'  => ['CZ','CS','KR','RC','VV'],
            'liguria'   => ['GE','IM','SP','SV'],
            'marche'    => ['AN','AP','FM','MC','PU'],
            'sardegna'  => ['CA','NU','OR','SS','SU'],
            'abruzzo'   => ['AQ','CH','PE','TE'],
            'friuli-venezia giulia' => ['UD','GO','PN','TS'],
            'friuli venezia giulia' => ['UD','GO','PN','TS'],
            'trentino-alto adige' => ['TN','BZ'],
            'trentino alto adige' => ['TN','BZ'],
            'umbria'    => ['PG','TR'],
            'basilicata'=> ['MT','PZ'],
            'molise'    => ['CB','IS'],
            "valle d'aosta" => ['AO'],
        ];
        $key = strtolower(trim($regione));
        $sigle = $map[$key] ?? [];
        return $sigle ? "'" . implode("','", $sigle) . "'" : "''";
    }

    /**
     * Costruisce clausole WHERE per filtro CAP — supporta singoli, range ("20100-20145"), liste.
     * Ritorna [conds_array, params_array]
     */
    public static function buildCapClauses(string $col, array $valori): array
    {
        $conds = []; $params = [];
        foreach ($valori as $v) {
            $v = trim($v);
            if (preg_match('/^(\d{5})\s*[-–]\s*(\d{5})$/', $v, $m)) {
                $conds[] = "$col BETWEEN ? AND ?"; $params[] = $m[1]; $params[] = $m[2];
            } elseif (preg_match('/^\d{5}$/', $v)) {
                $conds[] = "$col = ?"; $params[] = $v;
            } else {
                // ignora input non valido
                continue;
            }
        }
        if (!$conds) { $conds = ['1=0']; }
        return [$conds, $params];
    }

    /** Mapping veloce nomi provincia → sigle più comuni */
    public static function provToSigla(string $nome): string
    {
        $map = [
            'milano'=>'MI','roma'=>'RM','napoli'=>'NA','torino'=>'TO','bologna'=>'BO',
            'firenze'=>'FI','bari'=>'BA','palermo'=>'PA','genova'=>'GE','venezia'=>'VE',
            'bergamo'=>'BG','brescia'=>'BS','verona'=>'VR','padova'=>'PD','catania'=>'CT',
            'messina'=>'ME','taranto'=>'TA','cagliari'=>'CA','reggio emilia'=>'RE','modena'=>'MO',
            'ancona'=>'AN','perugia'=>'PG','pescara'=>'PE','salerno'=>'SA','foggia'=>'FG',
            'trieste'=>'TS','udine'=>'UD','trento'=>'TN','bolzano'=>'BZ','vicenza'=>'VI',
            'cosenza'=>'CS','potenza'=>'PZ','lecce'=>'LE','rimini'=>'RN','ravenna'=>'RA',
        ];
        return $map[strtolower(trim($nome))] ?? strtoupper(substr($nome, 0, 2));
    }

    /**
     * Esegue l'estrazione + XLSX. Supporta:
     *  - single sheet (intent senza 'sheets') → 1 foglio
     *  - multi sheet (intent['sheets'] array di sub-spec) → N fogli in 1 xlsx
     * Ritorna ['path','filename','count','comuni','mobiles','sheets'=>[{label,requested,extracted}]]
     */
    public static function estrai(array $intent, array $cliente, array $source, ?string $antijoinTable = null): array
    {
        $outDir = AI_ROOT . '/downloads/' . $cliente['id'];
        @mkdir($outDir, 0775, true);
        $slugCliente = self::slug($cliente['ragione_sociale'] ?: ($cliente['nome'] . '-' . $cliente['cognome']));
        $slugArea    = self::slug(implode('_', $intent['area']['valori'] ?? ['na']));
        $prodotto    = $intent['prodotto'];

        // Multi-sheet
        if (!empty($intent['sheets']) && is_array($intent['sheets'])) {
            return self::estraiMulti($intent, $cliente, $source, $antijoinTable, $outDir, $slugCliente, $slugArea);
        }

        // SPLIT mobile/fisso percentuale: 2 query separate poi unite
        $pctM = (int)($intent['filtri']['pct_mobile'] ?? 0);
        $pctF = (int)($intent['filtri']['pct_fisso'] ?? 0);
        if ($pctM > 0 && $pctF > 0 && ($pctM + $pctF) === 100) {
            return self::estraiSplitMobileFisso($intent, $cliente, $source, $antijoinTable, $outDir, $slugCliente, $slugArea, $pctM, $pctF);
        }

        // Single-sheet (legacy path)
        $pdo = rawDb();
        $pdo->exec("SET SESSION sql_mode = ''");
        $pdo->exec("SET SESSION max_execution_time = 600000");  // 10 min per estrazioni grandi con magazzino
        $q = self::buildQuery($intent, $source, $antijoinTable);
        $stmt = $pdo->prepare($q['sql']);
        $stmt->execute($q['params']);

        $totQty = (int)$intent['quantita'];
        $filename = sprintf('%s_%s_%d_%s.xlsx', $slugCliente, $slugArea, $totQty, $prodotto);
        $xlsxPath = $outDir . '/' . $filename;
        $csvPath  = sys_get_temp_dir() . '/ailab_' . uniqid() . '.csv';

        $cols = self::outputColumnsForProduct($prodotto, $intent);
        $comuneIdx = array_search('Comune', $cols, true);
        $cfIdx     = array_search('CF', $cols, true);
        $fp = fopen($csvPath, 'w');
        fputcsv($fp, $cols);
        $count = 0; $comuni = []; $mobiles = []; $cfList = []; $rowsCache = [];
        $wantExtra = !empty($intent['filtri']['with_extra_numbers']);
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
            $mobiles[] = $r[0];
            $comuni[$r[$comuneIdx] ?? ''] = 1;
            if ($wantExtra && $cfIdx !== false && !empty($r[$cfIdx])) $cfList[] = $r[$cfIdx];
            if ($wantExtra) $rowsCache[] = $r;
            else fputcsv($fp, $r);
            $count++;
        }
        if ($wantExtra) {
            // Arricchimento con Tel_Extra_N da master_cf_numeri
            $nums = self::fetchExtraNumbers(array_unique($cfList));
            $maxExtra = 0;
            foreach ($rowsCache as $r) {
                $cf = $cfIdx !== false ? ($r[$cfIdx] ?? '') : '';
                if ($cf && isset($nums[$cf])) {
                    $unique = array_values(array_unique(array_diff($nums[$cf], [$r[0]])));
                    if (count($unique) > $maxExtra) $maxExtra = count($unique);
                }
            }
            $maxExtra = min($maxExtra, 20);
            $extraHeaders = [];
            for ($i = 1; $i <= $maxExtra; $i++) $extraHeaders[] = "Tel_Extra_$i";
            // Riscrivi header + righe con extra cols
            ftruncate($fp, 0); rewind($fp);
            fputcsv($fp, array_merge($cols, $extraHeaders));
            foreach ($rowsCache as $r) {
                $cf = $cfIdx !== false ? ($r[$cfIdx] ?? '') : '';
                $extra = [];
                if ($cf && isset($nums[$cf])) {
                    $extra = array_values(array_unique(array_diff($nums[$cf], [$r[0]])));
                    $extra = array_slice($extra, 0, $maxExtra);
                }
                $line = array_merge($r, $extra);
                // Pad fino a col count
                $pad = count($cols) + $maxExtra - count($line);
                if ($pad > 0) $line = array_merge($line, array_fill(0, $pad, ''));
                fputcsv($fp, $line);
            }
            $cols = array_merge($cols, $extraHeaders);
        }
        fclose($fp);

        self::csvToXlsx($csvPath, $xlsxPath);
        @unlink($csvPath);

        return [
            'path' => $xlsxPath, 'filename' => $filename,
            'count' => $count, 'comuni' => count($comuni), 'mobiles' => $mobiles,
            'sheets' => [['label' => 'Lista', 'requested' => $totQty, 'extracted' => $count]],
        ];
    }

    /** Multi-sheet extraction */
    /**
     * Estrazione split: N% mobili + N% fissi → 2 query separate, CSV unico.
     * Se una delle 2 fonti non ha abbastanza, ritorna parziale + meta deltas.
     */
    private static function estraiSplitMobileFisso(array $intent, array $cliente, array $source, ?string $antijoinTable, string $outDir, string $slugCliente, string $slugArea, int $pctMobile, int $pctFisso): array
    {
        $totQty = (int)$intent['quantita'];
        $reqMobile = (int)round($totQty * $pctMobile / 100);
        $reqFisso  = $totQty - $reqMobile;
        $prodotto  = $intent['prodotto'];

        $pdo = rawDb();
        $pdo->exec("SET SESSION sql_mode = ''");
        $pdo->exec("SET SESSION max_execution_time = 600000");

        $cols = self::outputColumnsForProduct($prodotto, $intent);
        $comuneIdx = array_search('Comune', $cols, true);
        $cfIdx     = array_search('CF', $cols, true);
        $csvPath   = sys_get_temp_dir() . '/ailab_split_' . uniqid() . '.csv';
        $fp = fopen($csvPath, 'w');
        fputcsv($fp, $cols);

        $count = 0; $countMobile = 0; $countFisso = 0; $comuni = []; $mobiles = [];

        // Query 1: solo mobili
        $intentMob = $intent;
        $intentMob['filtri']['only_mobile'] = true;
        $intentMob['filtri']['tipo_telefono'] = 'mobile';
        unset($intentMob['filtri']['pct_mobile'], $intentMob['filtri']['pct_fisso']);
        $intentMob['quantita'] = $reqMobile;
        $qM = self::buildQuery($intentMob, $source, $antijoinTable);
        $stM = $pdo->prepare($qM['sql']);
        $stM->execute($qM['params']);
        while ($r = $stM->fetch(PDO::FETCH_NUM)) {
            fputcsv($fp, $r);
            $mobiles[] = $r[0];
            if ($comuneIdx !== false) $comuni[$r[$comuneIdx] ?? ''] = 1;
            $count++; $countMobile++;
        }

        // Query 2: solo fissi
        $intentFis = $intent;
        $intentFis['filtri']['only_mobile'] = false;
        $intentFis['filtri']['tipo_telefono'] = 'fisso';
        unset($intentFis['filtri']['pct_mobile'], $intentFis['filtri']['pct_fisso']);
        $intentFis['quantita'] = $reqFisso;
        $qF = self::buildQuery($intentFis, $source, $antijoinTable);
        $stF = $pdo->prepare($qF['sql']);
        $stF->execute($qF['params']);
        while ($r = $stF->fetch(PDO::FETCH_NUM)) {
            fputcsv($fp, $r);
            $mobiles[] = $r[0];
            if ($comuneIdx !== false) $comuni[$r[$comuneIdx] ?? ''] = 1;
            $count++; $countFisso++;
        }

        fclose($fp);

        // Genera xlsx
        $filename = sprintf('%s_%s_%d_%s_split.xlsx', $slugCliente, $slugArea, $totQty, $prodotto);
        $xlsxPath = $outDir . '/' . $filename;
        self::csvToXlsx($csvPath, $xlsxPath);
        @unlink($csvPath);

        return [
            'path' => $xlsxPath, 'filename' => $filename,
            'count' => $count, 'comuni' => count($comuni), 'mobiles' => $mobiles,
            'sheets' => [['label' => 'Lista', 'requested' => $totQty, 'extracted' => $count]],
            'split' => [
                'pct_mobile' => $pctMobile, 'pct_fisso' => $pctFisso,
                'requested_mobile' => $reqMobile, 'extracted_mobile' => $countMobile,
                'requested_fisso' => $reqFisso, 'extracted_fisso' => $countFisso,
                'shortfall_mobile' => max(0, $reqMobile - $countMobile),
                'shortfall_fisso' => max(0, $reqFisso - $countFisso),
            ],
        ];
    }

    /**
     * Conta i record disponibili per tipo telefono (mobile/fisso) usando i filtri dell'intent.
     * Usato per pre-check prima di confermare estrazioni con split percentuali.
     * Ritorna ['mobile'=>int, 'fisso'=>int].
     */
    public static function countByTelType(array $intent, array $source, ?string $antijoinTable = null): array
    {
        $pdo = rawDb();
        $pdo->exec("SET SESSION sql_mode = ''");
        $pdo->exec("SET SESSION max_execution_time = 120000");

        $out = ['mobile' => 0, 'fisso' => 0];
        foreach (['mobile', 'fisso'] as $tt) {
            $sub = $intent;
            $sub['filtri']['tipo_telefono'] = $tt;
            $sub['filtri']['only_mobile'] = ($tt === 'mobile');
            unset($sub['filtri']['pct_mobile'], $sub['filtri']['pct_fisso']);
            $sub['quantita'] = 1000000;  // disabilita LIMIT in COUNT wrapper

            try {
                $q = self::buildQuery($sub, $source, $antijoinTable);
                // wrappa la query in COUNT
                $countSql = "SELECT COUNT(*) FROM (" . preg_replace('/\s+ORDER BY .*$/is', '', $q['sql']) . ") x";
                // Rimuovi anche LIMIT se presente nel sub
                $countSql = preg_replace('/\s+LIMIT \d+\s*\)/i', ')', $countSql);
                $st = $pdo->prepare($countSql);
                $st->execute($q['params']);
                $out[$tt] = (int)$st->fetchColumn();
            } catch (\Throwable $e) {
                error_log("countByTelType($tt) error: " . $e->getMessage());
            }
        }
        return $out;
    }

    private static function estraiMulti(array $intent, array $cliente, array $source, ?string $magTable, string $outDir, string $slugCliente, string $slugArea): array
    {
        $pdo = rawDb();
        $pdo->exec("SET SESSION sql_mode = ''");
        $pdo->exec("SET SESSION max_execution_time = 600000");
        $csvPaths = []; $sheetsInfo = []; $allMobiles = []; $allComuni = []; $totExtracted = 0; $totRequested = 0;

        foreach ($intent['sheets'] as $i => $sheet) {
            $subIntent = $intent;
            $subIntent['quantita'] = (int)($sheet['quantita'] ?? 0);
            // Merge filtri: outer + override (safety deep merge per 'filtri')
            $baseFiltri = $intent['filtri'] ?? [];
            $override   = $sheet['filtri_override'] ?? [];
            $subIntent['filtri'] = array_merge($baseFiltri, is_array($override) ? $override : []);
            // FORCE only_mobile = true (sicurezza: mai perdere il filtro mobile)
            $subIntent['filtri']['only_mobile'] = true;
            // Se outer aveva no_stranieri, tienilo
            if (!empty($baseFiltri['no_stranieri'])) $subIntent['filtri']['no_stranieri'] = true;

            $label = $sheet['label'] ?? ('Foglio ' . ($i+1));
            $totRequested += $subIntent['quantita'];

            $q = self::buildQuery($subIntent, $source, $magTable);
            $stmt = $pdo->prepare($q['sql']);
            $stmt->execute($q['params']);

            $cols = self::outputColumnsForProduct($intent['prodotto']);
            $comuneIdx = array_search('Comune', $cols, true);
            $csv = sys_get_temp_dir() . '/ailab_' . uniqid() . '.csv';
            $fp = fopen($csv, 'w');
            fputcsv($fp, $cols);
            $c = 0;
            while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
                fputcsv($fp, $r); $allMobiles[] = $r[0]; $allComuni[$r[$comuneIdx]] = 1; $c++;
            }
            fclose($fp);
            $totExtracted += $c;
            $csvPaths[] = $csv;
            $sheetsInfo[] = ['label' => $label, 'requested' => $subIntent['quantita'], 'extracted' => $c, 'csv' => $csv, 'filtri' => $subIntent['filtri']];
        }

        $filename = sprintf('%s_%s_%d_%s_multisheet.xlsx', $slugCliente, $slugArea, $totRequested, $intent['prodotto']);
        $xlsxPath = $outDir . '/' . $filename;
        self::multiCsvToXlsx($sheetsInfo, $xlsxPath);
        foreach ($csvPaths as $c) @unlink($c);

        return [
            'path' => $xlsxPath, 'filename' => $filename,
            'count' => $totExtracted, 'comuni' => count($allComuni), 'mobiles' => $allMobiles,
            'sheets' => array_map(fn($s) => ['label'=>$s['label'],'requested'=>$s['requested'],'extracted'=>$s['extracted']], $sheetsInfo),
        ];
    }

    private static function multiCsvToXlsx(array $sheetsInfo, string $xlsx): void
    {
        $script = <<<'PY'
import csv, sys, json
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

sheets_json, xlsx_path = sys.argv[1], sys.argv[2]
sheets = json.loads(open(sheets_json, encoding='utf-8').read())
wb = Workbook()
wb.remove(wb.active)  # rimuovi il foglio default
header_fill = PatternFill("solid", fgColor="1E40AF")
header_font = Font(bold=True, color="FFFFFF", size=11)
thin = Side(border_style="thin", color="94A3B8")
border = Border(left=thin, right=thin, top=thin, bottom=thin)
center = Alignment(horizontal="center", vertical="center")
zebra = PatternFill("solid", fgColor="F8FAFC")

def widths_for(ncols):
    # 17 cols = schema energia; 10 = schema anagrafica snella
    return [14,14,15,18,18,14,28,8,22,8,10,14,16,16,22,22,14] if ncols > 10 else [14,15,18,28,8,22,8,10,14,14]

for s in sheets:
    label = s['label'][:31] or "Foglio"
    # Normalizza chars illegali per nomi sheet
    for ch in "[]:*?/\\":
        label = label.replace(ch, " ")
    ws = wb.create_sheet(label)
    with open(s['csv'], newline='', encoding='utf-8') as f:
        for i, r in enumerate(csv.reader(f)):
            ws.append(r)
    for col in range(1, ws.max_column+1):
        c = ws.cell(row=1, column=col); c.fill=header_fill; c.font=header_font; c.border=border; c.alignment=center
    widths = widths_for(ws.max_column)
    for i,w in enumerate(widths[:ws.max_column]):
        ws.column_dimensions[get_column_letter(i+1)].width = w
    ws.freeze_panes = "A2"
    ws.auto_filter.ref = ws.dimensions
    for row in range(2, ws.max_row+1):
        if row % 2 == 0:
            for col in range(1, ws.max_column+1):
                ws.cell(row=row, column=col).fill = zebra
    ws.row_dimensions[1].height = 24

wb.save(xlsx_path)
PY;
        $specPath = tempnam(sys_get_temp_dir(), 'pyxlsx_multi_') . '.json';
        file_put_contents($specPath, json_encode($sheetsInfo));
        $scriptPath = tempnam(sys_get_temp_dir(), 'pyxlsx_') . '.py';
        file_put_contents($scriptPath, $script);
        exec('python3 ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($specPath) . ' ' . escapeshellarg($xlsx) . ' 2>&1', $out, $rc);
        @unlink($scriptPath); @unlink($specPath);
        if ($rc !== 0) throw new RuntimeException("Python multi-xlsx conversion failed: " . implode("\n", $out));
    }

    /**
     * Lookup batch su trovacodicefiscale2.master_cf_numeri per una lista di CF.
     * Ritorna: ['CF1' => ['tel1','tel2',...], 'CF2' => [...]]
     * Ordine: mobile prima di fisso.
     */
    public static function fetchExtraNumbers(array $cfs): array
    {
        $cfs = array_values(array_filter(array_unique($cfs)));
        if (!$cfs) return [];
        $pdo = remoteDb('trovacodicefiscale2');
        $pdo->exec("SET SESSION max_execution_time = 120000");
        $out = [];
        foreach (array_chunk($cfs, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare("SELECT cf, tel FROM master_cf_numeri
                                WHERE cf IN ($ph) ORDER BY FIELD(tel_type,'mobile','fisso'), tel");
            $q->execute($chunk);
            while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                $out[$r['cf']][] = $r['tel'];
            }
        }
        return $out;
    }

    private static function csvToXlsx(string $csv, string $xlsx): void
    {
        $script = <<<PY
import csv, sys
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter
csv_path, xlsx_path = sys.argv[1], sys.argv[2]
wb = Workbook(); ws = wb.active
with open(csv_path, newline='', encoding='utf-8') as f:
    for r in csv.reader(f): ws.append(r)
header_fill = PatternFill("solid", fgColor="1E40AF")
header_font = Font(bold=True, color="FFFFFF", size=11)
thin = Side(border_style="thin", color="94A3B8")
border = Border(left=thin, right=thin, top=thin, bottom=thin)
center = Alignment(horizontal="center", vertical="center")
for col in range(1, ws.max_column+1):
    c = ws.cell(row=1, column=col); c.fill=header_fill; c.font=header_font; c.border=border; c.alignment=center
if ws.max_column == 10:
    widths = [14,15,18,28,8,22,8,10,14,14]
elif ws.max_column == 12:
    widths = [14,15,18,18,8,28,8,22,8,10,14,14]
elif ws.max_column >= 17:
    widths = [14,14,15,18,18,14,28,8,22,8,10,14,16,16,22,22,14]
else:
    widths = [14] * ws.max_column
# Pad for any extra Tel_Extra_* columns
while len(widths) < ws.max_column:
    widths.append(14)
for i,w in enumerate(widths[:ws.max_column]): ws.column_dimensions[get_column_letter(i+1)].width = w
ws.freeze_panes = "A2"; ws.auto_filter.ref = ws.dimensions
zebra = PatternFill("solid", fgColor="F8FAFC")
for row in range(2, ws.max_row+1):
    if row % 2 == 0:
        for col in range(1, ws.max_column+1): ws.cell(row=row, column=col).fill = zebra
ws.row_dimensions[1].height = 24
wb.save(xlsx_path)
PY;
        $scriptPath = tempnam(sys_get_temp_dir(), 'pyxlsx_') . '.py';
        file_put_contents($scriptPath, $script);
        exec('python3 ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($csv) . ' ' . escapeshellarg($xlsx) . ' 2>&1', $out, $rc);
        @unlink($scriptPath);
        if ($rc !== 0) throw new RuntimeException("Python xlsx conversion failed: " . implode("\n", $out));
    }

    /** Inserisce i mobile nel magazzino con data_lotto + moo progressivo */
    public static function insertMagazzino(string $table, array $mobiles): array
    {
        if (!$mobiles) return ['inserted' => 0];
        $pdo = remoteDb('clienti');
        $maxMoo = (int)$pdo->query("SELECT COALESCE(MAX(moo),0) FROM `$table`")->fetchColumn();
        $dataLotto = date('d-m-Y H.i.s');
        $ins = $pdo->prepare("INSERT INTO `$table` (mobile, data_lotto, moo) VALUES (?, ?, ?)");
        $pdo->beginTransaction();
        $moo = $maxMoo;
        foreach ($mobiles as $m) { $moo++; $ins->execute([$m, $dataLotto, $moo]); }
        $pdo->commit();
        return [
            'inserted'    => count($mobiles),
            'data_lotto'  => $dataLotto,
            'moo_from'    => $maxMoo + 1,
            'moo_to'      => $moo,
        ];
    }

    /** Registra delivery in ai_laboratory.deliveries */
    public static function logDelivery(array $data): int
    {
        $pdo = remoteDb('ai_laboratory');
        $stmt = $pdo->prepare("INSERT INTO deliveries
            (sent_at, cliente_id, cliente_nome, cliente_email, prodotto, query_ricerca, area,
             fonte_db, filtri, contatti_inviati, magazzino_tabella, file_path, file_name, prezzo_eur, note, intent_json)
            VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['cliente_id'],
            $data['cliente_nome'],
            $data['cliente_email'],
            $data['prodotto'],
            $data['query_ricerca'],
            $data['area'],
            $data['fonte_db'],
            $data['filtri'],
            $data['records'],
            $data['magazzino'] ?: null,
            $data['file_path'],
            $data['file_name'],
            $data['prezzo_eur'],
            $data['note'] ?: null,
            isset($data['intent']) ? json_encode($data['intent'], JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    private static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\b(srl|srls|spa|sas|snc|s\.r\.l\.?|s\.p\.a\.?)\b/i', '', $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-') ?: 'x';
    }
}
