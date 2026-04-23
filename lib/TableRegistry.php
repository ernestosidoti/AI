<?php
/**
 * TableRegistry — Elenco delle 11 fonti dati disponibili per estrazione
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

class TableRegistry
{
    public static function getAll(): array
    {
        return [
            [
                'id' => 'edicus2023', 'label' => 'Edicus 2023 (SuperPOD)',
                'db' => 'Edicus_2023_marzo', 'table' => 'superpod_2023',
                'type' => 'luce', 'year' => 2023, 'records' => 5441194, 'icon' => 'bolt',
                'description' => 'POD residenziali luce con data attivazione e potenza. Ottima qualità dati.',
                'columns' => ['pod','trader','data_attivazione','attivazione','potenza','cognome','nome','codice_fiscale','mobile','indirizzo','civico','localita','cap','provincia','regione','sesso','anno','whatsapp'],
            ],
            [
                'id' => 'edicus2021lug', 'label' => 'Edicus 2021 Luglio',
                'db' => 'Edicus2021_luglio', 'table' => 'SUPERPOD',
                'type' => 'luce', 'year' => 2021, 'records' => 24628423, 'icon' => 'bolt',
                'description' => 'Database storico luce con 24M record, nome cliente unico.',
                'columns' => ['Pod','CodiceFiscale','PartitaIva','mobile','fisso','NomeCliente','Indirizzo','Civico','Localita','CAP','PROVINCIA','Trader'],
            ],
            [
                'id' => 'edicus2021ago', 'label' => 'Edicus 2021 Agosto',
                'db' => 'edicus_2021_agosto', 'table' => 'ML_POD_2021',
                'type' => 'luce', 'year' => 2021, 'records' => 1468069, 'icon' => 'bolt',
                'description' => 'ML POD agosto 2021 con telefoni mobili e fissi.',
                'columns' => ['Pod','CodiceFiscale','PartitaIva','mobile','fisso','NomeCliente','Indirizzo','Civico','Localita','CAP','PROVINCIA','Trader'],
            ],
            [
                'id' => 'tot_ml2019', 'label' => 'Totale ML 2019',
                'db' => 'edicus_totale', 'table' => 'TOT_ML2019',
                'type' => 'luce', 'year' => 2019, 'records' => 13665941, 'icon' => 'bolt',
                'description' => 'Database storico 2019 (13M record). Qualità media.',
                'columns' => ['Pod','CodiceFiscale','PartitaIva','mobile','Fisso','NomeCliente','Indirizzo','Civico','Localita','CAP','provincia','Trader'],
            ],
            [
                'id' => 'elettrico_mac', 'label' => 'Elettrico MAC',
                'db' => 'elettrico_mac', 'table' => 'a',
                'type' => 'luce', 'year' => 2020, 'records' => 23534798, 'icon' => 'bolt',
                'description' => 'Database elettrico MAC (23M record). Buona qualità.',
                'columns' => ['Pod','CodiceFiscale','PartitaIva','mobile','Fisso','NomeCliente','Indirizzo','Civico','Localita','CAP','provincia','Trader'],
            ],
            [
                'id' => 'sen_2021', 'label' => 'SEN Italia 2021',
                'db' => 'ese_2022', 'table' => 'SEN_ITALIA_2021_TOTALE_copy1',
                'type' => 'luce', 'year' => 2021, 'records' => 6774453, 'icon' => 'bolt',
                'description' => 'SEN Italia 2021 con intestatario fattura.',
                'columns' => ['POD','CODICE_FISCALE','PARTITA_IVA','mobile','fisso','INTESTATARIO_FATTURA','FORNIAMO_ENERGIA_IN','FORNIAMO_ENERGIA_CIVICO','FORNIAMO_ENERGIA_LOCALITA','FORNIAMO_ENERGIA_CAP','pronvincia'],
            ],
            [
                'id' => 'libero_2020', 'label' => 'Libero 2020',
                'db' => 'LIBERO_2020', 'table' => 'ML_POD_2020',
                'type' => 'luce', 'year' => 2020, 'records' => 24336476, 'icon' => 'bolt',
                'description' => 'Libero 2020 (24M record, mercato libero). Alta qualità.',
                'columns' => ['Pod','CodiceFiscale','PartitaIva','mobile','fisso','NomeCliente','Indirizzo','Civico','Localita','CAP','provincia','Trader'],
            ],
            [
                'id' => 'business2025', 'label' => 'Business 2025',
                'db' => 'BUSINESS2025', 'table' => 'business',
                'type' => 'business', 'year' => 2025, 'records' => 50408, 'icon' => 'building',
                'description' => 'Solo aziende: 50K PIVA business 2025 con ragione sociale.',
                'columns' => ['POD','PARTITA_IVA','CELL','RAGIONE_SOCIALE','INDIRIZZO','CIVICO','CITTA','CAP','PROVINCIA','TRADER'],
            ],
            [
                'id' => 'altri_usi_2020', 'label' => 'Altri Usi 2020',
                'db' => 'altri_usi_2020', 'table' => 'a',
                'type' => 'gas', 'year' => 2020, 'records' => 5876208, 'icon' => 'flame',
                'description' => 'Altri usi gas 2020 (bar, negozi, attività).',
                'columns' => ['Pod','CodiceFiscale','PartitaIva','mobile','fisso','NomeCliente','Indirizzo','Civico','localita','CAP','PROVINCIA','Trader'],
            ],
            [
                'id' => 'gas2023', 'label' => 'Gas 2023',
                'db' => 'Edicus_2023_marzo', 'table' => 'gas',
                'type' => 'gas', 'year' => 2023, 'records' => 948682, 'icon' => 'flame',
                'description' => 'Gas 2023 con PDR, società distribuzione.',
                'columns' => ['POD_INCROCIO','`Codice PDR`','CF_PIVA','PIVA','`Telefono Cliente`','phone','`Nominativo Cliente Finale`','Indirizzo','Civico','Comune','CAP','Provincia','Regione','`Societa di vendita abbinata`','`Societa di distribuzione abbinata`'],
            ],
            [
                'id' => 'pdr2024', 'label' => 'PDR 2024 (più recente)',
                'db' => 'Edicus_2024_maggio', 'table' => 'pdr_unificata',
                'type' => 'gas', 'year' => 2024, 'records' => 1229474, 'icon' => 'flame',
                'description' => 'DB gas più recente con storico trader (cedente/richiedente) e data decorrenza.',
                'columns' => ['cod_pdr','cf','piva','mobile','fisso','nominativo','ragione_sociale','via','cap','localita','provincia','regione','societa_vendita_richiedente','societa_cedente','data_decorrenza'],
            ],
        ];
    }

    public static function getById(string $id): ?array
    {
        foreach (self::getAll() as $source) {
            if ($source['id'] === $id) return $source;
        }
        return null;
    }

    public static function buildSchemaPrompt(array $sourceIds): string
    {
        $parts = ["Le tabelle disponibili per questa query sono:\n"];
        foreach ($sourceIds as $id) {
            $src = self::getById($id);
            if (!$src) continue;
            $parts[] = "TABELLA: `{$src['db']}`.`{$src['table']}` (alias: {$src['id']})";
            $parts[] = "  Descrizione: {$src['description']}";
            $parts[] = "  Tipo: {$src['type']} | Record totali: " . number_format($src['records']);
            $parts[] = "  Colonne: " . implode(', ', $src['columns']);
            $parts[] = "";
        }
        return implode("\n", $parts);
    }
}
