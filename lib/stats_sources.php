<?php
/**
 * Catalogo fonti per stat multi-fonte — ordinate per qualità.
 * Schema-aware: ogni fonte dichiara i nomi colonna per mobile/provincia/regione/comune/cf.
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

class StatsSources
{
    const TOP_N_FAST = 3; // prime 3 fonti da lanciare subito

    public static function all(): array
    {
        // Flags: has_pod, has_pdr, has_piva (fonte ha P.IVA ergo è usabile per business)
        return [
            ['key'=>'sky2022',      'db'=>'SKY',                'table'=>'SKY_2022',        'label'=>'SKY 2022',
             'cols'=>['mobile'=>'mobile','fisso'=>null,'provincia'=>'provincia','regione'=>'regione','comune'=>null,'cf'=>'codicefiscale','piva'=>null,'date'=>null],
             'has_pod'=>false, 'has_pdr'=>false, 'has_piva'=>false],
            ['key'=>'edicus2023',   'db'=>'Edicus_2023_marzo',  'table'=>'superpod_2023',   'label'=>'Edicus 2023 (SuperPOD)',
             'cols'=>['mobile'=>'mobile','fisso'=>null,'provincia'=>'provincia','regione'=>'regione','comune'=>'localita','cf'=>'codice_fiscale','piva'=>null,'date'=>'data_attivazione'],
             'has_pod'=>true,  'has_pdr'=>false, 'has_piva'=>false],
            ['key'=>'edicus2021lug','db'=>'Edicus2021_luglio',  'table'=>'SUPERPOD',        'label'=>'Edicus 2021 Luglio',
             'cols'=>['mobile'=>'mobile','fisso'=>'fisso','provincia'=>'PROVINCIA','regione'=>'regione','comune'=>'Localita','cf'=>'CodiceFiscale','piva'=>'PartitaIva','date'=>null],
             'has_pod'=>true,  'has_pdr'=>false, 'has_piva'=>true],
            ['key'=>'gas2023',      'db'=>'Edicus_2023_marzo',  'table'=>'gas',             'label'=>'Gas 2023',
             'cols'=>['mobile'=>'phone','fisso'=>'Telefono Cliente','provincia'=>'Provincia','regione'=>'Regione','comune'=>'Comune','cf'=>'CF_PIVA','piva'=>'PIVA','date'=>null],
             'has_pod'=>true,  'has_pdr'=>true,  'has_piva'=>true],
            ['key'=>'pdr2024',      'db'=>'Edicus_2024_maggio', 'table'=>'pdr_unificata',   'label'=>'PDR 2024',
             'cols'=>['mobile'=>'mobile','fisso'=>'fisso','provincia'=>'provincia','regione'=>'regione','comune'=>'localita','cf'=>'cf','piva'=>'piva','date'=>'data_decorrenza'],
             'has_pod'=>false, 'has_pdr'=>true,  'has_piva'=>true],
            ['key'=>'dbu2023',      'db'=>'DBU',                'table'=>'dbu_2023',        'label'=>'DBU 2023',
             'cols'=>['mobile'=>'MOBILE','fisso'=>'NUMTELCOMPL','provincia'=>'PROVINCIA','regione'=>'REGIONE','comune'=>'COMUNE','cf'=>'CODICE_FISCALE','piva'=>'PARTITA_IVA','date'=>null],
             'has_pod'=>false, 'has_pdr'=>false, 'has_piva'=>true],
            ['key'=>'dbu2021',      'db'=>'dbu2021',            'table'=>'dbu_2021',        'label'=>'DBU 2021',
             'cols'=>['mobile'=>'MOBILE','fisso'=>'TELEFONO','provincia'=>'PROVINCIA','regione'=>'REGIONE','comune'=>'COMUNE','cf'=>'CodFiscale','piva'=>'PartitaIVA','date'=>null],
             'has_pod'=>false, 'has_pdr'=>false, 'has_piva'=>true],
            ['key'=>'altri_usi_2020','db'=>'altri_usi_2020',    'table'=>'a',               'label'=>'Altri Usi 2020 (business)',
             'cols'=>['mobile'=>'mobile','fisso'=>'fisso','provincia'=>'PROVINCIA','regione'=>'regione','comune'=>'localita','cf'=>'CodiceFiscale','piva'=>'PartitaIva','date'=>null],
             'has_pod'=>true,  'has_pdr'=>false, 'has_piva'=>true],
            ['key'=>'business2025', 'db'=>'BUSINESS2025',       'table'=>'business',        'label'=>'Business 2025 (PIVA puri)',
             'cols'=>['mobile'=>'CELL','fisso'=>null,'provincia'=>'PROVINCIA','regione'=>'REGIONE','comune'=>'CITTA','cf'=>null,'piva'=>'PARTITA_IVA','date'=>null],
             'has_pod'=>true,  'has_pdr'=>false, 'has_piva'=>true],
            // ⭐ MASTER B2B consolidato — 5,35M righe, dedup (piva,tel) — PRIORITARIO per business non-POD/PDR
            ['key'=>'master_piva',  'db'=>'business',           'table'=>'master_piva_numeri', 'label'=>'Master B2B consolidato (5,3M)',
             'cols'=>['mobile'=>'tel','fisso'=>'tel','provincia'=>'provincia','regione'=>null,'comune'=>'comune','cf'=>null,'piva'=>'piva','date'=>null,
                      'tel_type'=>'tel_type','email'=>'email','pec'=>'pec','sito_web'=>'sito_web','ateco'=>'ateco','indirizzo'=>'indirizzo','civico'=>'civico','cap'=>'cap','ragsoc'=>'ragione_sociale'],
             'has_pod'=>false, 'has_pdr'=>false, 'has_piva'=>true, 'is_master'=>true],
            // ⭐ MASTER CONSUMER — 40,5M righe dedup (cf,tel) — PRIORITARIO per residenziali non-energia/non-POD
            ['key'=>'master_cf',    'db'=>'trovacodicefiscale2', 'table'=>'master_cf_numeri',   'label'=>'Master CF residenziale (40,5M)',
             'cols'=>['mobile'=>'tel','fisso'=>'tel','provincia'=>'provincia','regione'=>null,'comune'=>null,'cf'=>'cf','piva'=>null,'date'=>null,
                      'tel_type'=>'tel_type','nome'=>'nome','indirizzo'=>'indirizzo'],
             'has_pod'=>false, 'has_pdr'=>false, 'has_piva'=>false, 'is_master_cf'=>true],
        ];
    }

    /**
     * Seleziona le fonti giuste in base a prodotto + presenza di filtro data.
     * Ritorna [array $sources, string $reasonMsg, array $meta] — meta può avere 'date_filter_ignored' ecc.
     *
     * REGOLE:
     *  • SOLO i prodotti "energia" / "energia_business" richiedono POD o PDR.
     *  • Per "energia" + filtro data → intersezione: fonti con POD/PDR E colonna data.
     *  • Per "energia" senza filtro data → fonti con POD o PDR.
     *  • Per TUTTI gli altri prodotti (fotovoltaico, depurazione, finanziarie, immobiliari,
     *    telefonia, cessione_quinto, alimentari, cosmetica, generiche, lead_voip, gdpr,
     *    digital_mkt, email) → QUALSIASI fonte, ignora POD/PDR e filtro data.
     */
    public static function pickForIntent(array $intent): array
    {
        $product   = $intent['prodotto'] ?? '';
        $hasDate   = self::hasDateFilter($intent);
        $hasPodPdr = !empty($intent['filtri']['pod_pdr']) || in_array($product, ['energia','energia_business'], true);
        $isBusiness = self::detectBusinessIntent($intent);
        $all       = self::all();

        // RAMO 0a: richiesta BUSINESS senza POD/PDR → master_piva_numeri prioritario
        if ($isBusiness && !$hasPodPdr && $product !== 'energia' && $product !== 'energia_business') {
            $masterPiva = array_values(array_filter($all, fn($s) => ($s['is_master'] ?? false)));
            $msg = "💼⭐ Richiesta business (no POD/PDR) → uso il master B2B consolidato " . ($masterPiva[0]['label'] ?? 'master_piva_numeri');
            $meta = ['business_master' => true];
            if ($hasDate) { $meta['date_filter_ignored'] = true; $msg .= "\n⚠️ <i>Filtro data ignorato (master B2B non ha colonna data attivazione).</i>"; }
            return [$masterPiva, $msg, $meta];
        }

        // RAMO 0b: richiesta CONSUMER non-energia/non-business → master_cf_numeri prioritario (default)
        // Si estende a multi-fonte SOLO se l'utente chiede "approfondita" (intent['filtri']['approfondita']=true)
        if (!$isBusiness && !in_array($product, ['energia','energia_business'], true) && empty($intent['filtri']['approfondita'])) {
            $masterCf = array_values(array_filter($all, fn($s) => ($s['is_master_cf'] ?? false)));
            if ($masterCf) {
                $msg = "👤⭐ Richiesta residenziale → uso il master consumer " . $masterCf[0]['label'] . " (per stat estesa scrivi <i>«approfondisci»</i>)";
                return [$masterCf, $msg, []];
            }
        }

        // RAMO 1: prodotto diverso da energia* → qualsiasi fonte, filtro data ignorato
        if (!in_array($product, ['energia','energia_business'], true)) {
            // Escludo master_piva e master_cf dalle fonti standard (sono usate dai rami sopra)
            $sel = array_slice(array_filter($all, fn($s) => in_array($s['key'], ['sky2022','edicus2023','edicus2021lug'], true)), 0, self::TOP_N_FAST);
            $sel = array_values($sel);
            $msg = "🔝 Prodotto \"$product\" → cerca da qualsiasi fonte, parto dalle " . count($sel) . " principali (" . implode(', ', array_column($sel, 'label')) . ")";
            $meta = [];
            if ($hasDate) {
                $meta['date_filter_ignored'] = true;
                $msg .= "\n⚠️ <i>Il filtro data attivazione è ignorato per questo prodotto (rilevante solo per \"energia\"/\"energia_business\").</i>";
            }
            return [$sel, $msg, $meta];
        }

        // RAMO 2: energia_business → serve PIVA
        if ($product === 'energia_business') {
            // Fonti business prioritarie: altri_usi_2020 (5.8M, POD+PIVA), BUSINESS2025 (50K puro business)
            // Poi altre con POD/PDR E PIVA
            $podPdrPiva = array_values(array_filter($all, fn($s) => ($s['has_pod'] || $s['has_pdr']) && $s['has_piva']));
            // Ordine: altri_usi_2020, business2025, poi il resto con POD/PDR+PIVA
            usort($podPdrPiva, function($a, $b) {
                $order = ['altri_usi_2020'=>1, 'business2025'=>2, 'pdr2024'=>3, 'gas2023'=>4, 'edicus2021lug'=>5];
                return ($order[$a['key']] ?? 99) - ($order[$b['key']] ?? 99);
            });
            if ($hasDate) {
                $sel = array_values(array_filter($podPdrPiva, fn($s) => !empty($s['cols']['date'])));
                return [$sel, "⚡💼📅 Energia business + filtro data → uso le " . count($sel) . " fonti con POD/PDR + PIVA + colonna data (" . implode(', ', array_column($sel, 'label')) . ")", []];
            }
            return [$podPdrPiva, "⚡💼 Energia business → uso le " . count($podPdrPiva) . " fonti con POD/PDR + PIVA (" . implode(', ', array_column($podPdrPiva, 'label')) . ")", []];
        }

        // RAMO 3: energia residenziale
        $podPdr = array_values(array_filter($all, fn($s) => $s['has_pod'] || $s['has_pdr']));
        if ($hasDate) {
            $sel = array_values(array_filter($podPdr, fn($s) => !empty($s['cols']['date'])));
            return [$sel, "⚡📅 Energia + filtro data → uso le " . count($sel) . " fonti con POD/PDR E colonna data (" . implode(', ', array_column($sel, 'label')) . ")", []];
        }
        return [$podPdr, "⚡ Prodotto energia → uso le " . count($podPdr) . " fonti con POD/PDR (" . implode(', ', array_column($podPdr, 'label')) . ")", []];
    }

    public static function top(int $n): array { return array_slice(self::all(), 0, $n); }
    public static function rest(int $skip): array { return array_slice(self::all(), $skip); }

    /** True se l'intent contiene un filtro data_attivazione/decorrenza */
    public static function hasDateFilter(array $intent): bool
    {
        $f = $intent['filtri'] ?? [];
        return !empty($f['data_att_mese_anno']) || !empty($f['data_att_max_anno_mese']) || !empty($f['data_att_min_anno_mese']);
    }

    /**
     * Rileva se l'intent è una richiesta BUSINESS (B2B) — usato per scegliere master_piva_numeri.
     * Trigger:
     *   - filtri.tipo_target = 'business' (esplicito)
     *   - prodotto = 'energia_business' (già B2B)
     *   - filtri.with_piva, filtri.ateco, filtri.with_pec → indizi business
     *   - testo libero contiene "azienda", "ditta", "impresa", "PIVA", "B2B", "business"
     */
    public static function detectBusinessIntent(array $intent): bool
    {
        $f = $intent['filtri'] ?? [];
        if (($f['tipo_target'] ?? '') === 'business') return true;
        if (($intent['prodotto'] ?? '') === 'energia_business') return true;
        if (!empty($f['ateco']) || !empty($f['with_pec']) || !empty($f['with_piva'])) return true;
        $text = strtolower($intent['_raw_text'] ?? '');
        if ($text === '') return false;
        $kw = ['business','b2b','azienda','aziende','ditta','ditte','impresa','imprese','partita iva','p.iva','piva','società','societa','attività commercial','commerciali','professionist'];
        foreach ($kw as $w) if (strpos($text, $w) !== false) return true;
        return false;
    }

    /** Ritorna solo le fonti che supportano il filtro date */
    public static function withDateSupport(): array
    {
        return array_values(array_filter(self::all(), fn($s) => !empty($s['cols']['date'])));
    }
}
