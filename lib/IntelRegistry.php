<?php
/**
 * IntelRegistry — Legge metadata DB e regole prodotto dal database
 * Sostituisce il TableRegistry statico.
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

class IntelRegistry
{
    /**
     * Ritorna tutti i database attivi, ordinati per priorità
     */
    public static function getAllSources(PDO $db, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM db_metadata" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY priorita, source_id";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['prodotti_adatti'] = json_decode($r['prodotti_adatti'] ?? '[]', true);
        }
        return $rows;
    }

    /**
     * Ritorna i database adatti per un determinato prodotto, ordinati per priorità
     */
    public static function getSourcesForProduct(PDO $db, string $productCode): array
    {
        $all = self::getAllSources($db, true);
        $matching = [];
        foreach ($all as $s) {
            if (in_array($productCode, $s['prodotti_adatti'], true)) {
                $matching[] = $s;
            }
        }
        return $matching;
    }

    /**
     * Ritorna tutte le regole attive per un prodotto
     */
    public static function getRulesForProduct(PDO $db, string $productCode): array
    {
        $stmt = $db->prepare("SELECT * FROM product_rules WHERE product_code = ? AND active = 1 ORDER BY priority");
        $stmt->execute([$productCode]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ritorna il catalogo prodotti attivi
     */
    public static function getProducts(PDO $db): array
    {
        return $db->query("SELECT * FROM products_catalog WHERE active = 1 ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Costruisce il blocco schema per il prompt Claude (solo fonti attive)
     * Include le colonne esatte di ogni tabella per evitare hallucination.
     */
    public static function buildSchemaPromptForSources(PDO $db, array $sourceIds): string
    {
        if (empty($sourceIds)) return '';

        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $stmt = $db->prepare("SELECT * FROM db_metadata WHERE source_id IN ($placeholders) AND active = 1 ORDER BY priorita");
        $stmt->execute($sourceIds);
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mappa source_id → colonne (da TableRegistry statico)
        $colsMap = [];
        if (class_exists('TableRegistry')) {
            foreach (TableRegistry::getAll() as $t) {
                $colsMap[$t['id']] = $t['columns'] ?? [];
            }
        }

        $parts = ["DATABASE DISPONIBILI (ordinati per priorità — usa sempre prima quelli a priorità più bassa):\n"];
        foreach ($sources as $s) {
            $prodotti = json_decode($s['prodotti_adatti'] ?? '[]', true);
            $parts[] = "TABELLA: `{$s['database_name']}`.`{$s['table_name']}` (alias: {$s['source_id']})";
            $parts[] = "  Priorità: {$s['priorita']} | Tipo: {$s['tipo_principale']} | Anno: {$s['anno']} | Record: " . number_format($s['records_count']);
            $parts[] = "  Descrizione: {$s['description']}";
            $parts[] = "  Prodotti adatti: " . implode(', ', $prodotti);
            if (!empty($colsMap[$s['source_id']])) {
                $parts[] = "  COLONNE ESATTE (usa SOLO queste, niente altro): " . implode(', ', $colsMap[$s['source_id']]);
            }
            $parts[] = "";
        }
        $parts[] = "IMPORTANTE: usa ESCLUSIVAMENTE i nomi delle colonne elencati sopra. NON inventare colonne che non ci sono.";
        $parts[] = "Se una colonna che ti servirebbe non c'è in una tabella, usa un'altra tabella o ometti quel campo dal SELECT.";
        return implode("\n", $parts);
    }

    /**
     * Costruisce il blocco regole per un prodotto
     */
    public static function buildRulesPromptForProduct(PDO $db, string $productCode): string
    {
        $rules = self::getRulesForProduct($db, $productCode);
        if (empty($rules)) {
            // Nessuna regola speciale per questo prodotto: NON applicare filtri come capoluoghi/cintura.
            return "REGOLE PRODOTTO: per \"$productCode\" NON ci sono regole speciali attive.\n"
                 . "IMPORTANTE: non applicare di tua iniziativa filtri come 'escludi capoluoghi' o 'escludi cintura grandi città'. Quelle regole valgono solo per prodotti specifici (fotovoltaico) e non per questo.\n";
        }

        $parts = ["REGOLE PER IL PRODOTTO \"$productCode\":\n"];
        $parts[] = "Applica SOLO le regole elencate qui sotto. Non aggiungere filtri aggiuntivi che non sono nell'elenco.";
        $parts[] = "Applica le regole con intelligenza in base allo scope geografico della richiesta (es. cintura_milano solo se lo scope include Lombardia/provincia MI).";
        $parts[] = "";
        foreach ($rules as $r) {
            $parts[] = "[{$r['rule_type']}] {$r['rule_name']}";
            if ($r['description']) $parts[] = "  → {$r['description']}";
            if ($r['rule_sql']) {
                $parts[] = "  SQL WHERE suggerito: {$r['rule_sql']}";
            }
            $parts[] = "";
        }

        // Linee guida geografiche: solo se il prodotto ha regole di esclusione città
        $hasCityRules = false;
        foreach ($rules as $r) {
            if ($r['rule_sql'] && stripos($r['rule_sql'], 'city_exclusions') !== false) {
                $hasCityRules = true; break;
            }
        }
        if ($hasCityRules) {
            $parts[] = "LINEE GUIDA GEOGRAFICHE (solo per questo prodotto):";
            $parts[] = "- 'escludi capoluoghi provincia': applicabile in tutta Italia (vale per qualsiasi regione).";
            $parts[] = "- Regole 'cintura_milano/roma/napoli/torino/bologna': applicale SOLO se lo scope include la regione/provincia corrispondente:";
            $parts[] = "  * cintura_milano → solo se scope Lombardia o MI/MB/LO/VA/BG/CO/LC/PV";
            $parts[] = "  * cintura_roma → solo se scope Lazio o RM/LT/FR/VT/RI";
            $parts[] = "  * cintura_napoli → solo se scope Campania o NA/CE/SA/AV/BN";
            $parts[] = "  * cintura_torino → solo se scope Piemonte o TO/AL/AT/CN/NO/VC/BI/VB";
            $parts[] = "  * cintura_bologna → solo se scope Emilia-Romagna o BO/MO/FE/RE/PR/PC/FC/RN/RA";
            $parts[] = "- Per altre regioni (Sardegna, Sicilia, Puglia, ecc.), NON includere filtri 'cintura_X'.";
        }
        $parts[] = "- Documenta nel campo 'interpretation' quali regole hai applicato e quali hai tralasciato e perché.";
        return implode("\n", $parts);
    }

    /**
     * Ritorna lista dei codici prodotto validi
     */
    public static function getProductCodes(PDO $db): array
    {
        return $db->query("SELECT code FROM products_catalog WHERE active = 1 ORDER BY display_order")->fetchAll(PDO::FETCH_COLUMN);
    }
}
