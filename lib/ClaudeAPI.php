<?php
/**
 * ClaudeAPI — Wrapper chiamate Anthropic API
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

class ClaudeAPI
{
    const MODEL = 'claude-sonnet-4-5-20250929';
    const API_URL = 'https://api.anthropic.com/v1/messages';
    const API_VERSION = '2023-06-01';
    const MAX_TOKENS = 2048;

    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public static function loadApiKey(PDO $db): ?string
    {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'anthropic_api_key'");
        $stmt->execute();
        return $stmt->fetchColumn() ?: null;
    }

    public function interpretQuery(string $userPrompt, array $sourceIds, ?array $parentQuery = null, ?string $productCode = null, ?PDO $db = null): array
    {
        // Costruisci schema dinamicamente dal DB se possibile
        if ($db && class_exists('IntelRegistry')) {
            $schemaBlock = IntelRegistry::buildSchemaPromptForSources($db, $sourceIds);
            $rulesBlock = $productCode ? IntelRegistry::buildRulesPromptForProduct($db, $productCode) : '';
        } else {
            $schemaBlock = TableRegistry::buildSchemaPrompt($sourceIds);
            $rulesBlock = '';
        }

        // Se è un'affinamento, aggiungi contesto della query precedente
        $refineBlock = '';
        if ($parentQuery) {
            $refineBlock = "\n\nCONTESTO: L'utente sta AFFINANDO una query precedente.\n";
            $refineBlock .= "Richiesta precedente: \"" . ($parentQuery['user_prompt'] ?? '') . "\"\n";
            $refineBlock .= "SQL precedente (riferimento):\n" . ($parentQuery['generated_sql'] ?? '') . "\n";
            $refineBlock .= "L'utente vuole modificare/correggere quella query. Applica le modifiche richieste mantenendo la struttura che funzionava.\n";
        }

        $productBlock = '';
        if ($productCode) {
            $productBlock = "\nPRODOTTO RICHIESTO: $productCode\nApplica AUTOMATICAMENTE le regole qui sotto — l'utente si aspetta che siano già attive.\n";
        }

        $systemPrompt = <<<PROMPT
Sei un assistente SQL specializzato nel database CercaPOD (POD/PDR energia e gas italiane).
Il tuo compito è trasformare richieste dell'utente in italiano in query SQL valide per MySQL 8.

REGOLE FONDAMENTALI (LEGGI ATTENTAMENTE, SONO VINCOLANTI):

🚫 COLONNE DI OUTPUT PER PRODOTTO — REGOLA CRITICA 🚫
Il SELECT deve contenere SOLO le colonne previste per il prodotto. Non eccedere.

► PRODOTTO "energia" (residenziale/gas/business):
  OUTPUT: POD, PDR (se gas), trader, data_attivazione, potenza, CF, PIVA, mobile, fisso, nome, cognome, indirizzo, civico, comune, provincia, regione, CAP, sesso, anno_nascita.

► PRODOTTI "telefonia", "cessione_quinto", "finanziarie":
  OUTPUT: CF, PIVA, mobile, fisso, nome, cognome, indirizzo, civico, comune, provincia, regione, CAP.
  PROIBITI: POD, PDR, trader, data_attivazione, potenza.

► PRODOTTI "fotovoltaico", "depurazione", "immobiliari", "alimentari", "cosmetica", "generiche", "lead_voip", "gdpr", "digital_mkt":
  OUTPUT SOLO: mobile, fisso, nome, cognome, indirizzo, civico, comune, provincia, regione, CAP.
  PROIBITI nell'OUTPUT (MA USABILI nei filtri WHERE): POD, PDR, trader, data_attivazione, potenza, CF, PIVA.
  IMPORTANTE: puoi comunque usare questi campi nel WHERE per filtrare (es. WHERE potenza > 3), ma NON devono comparire nel SELECT.

► CAMPI OPZIONALI (mobile, fisso, sesso, anno_nascita):
  Il CAMPO MOBILE è il principale strumento di contatto — includilo se disponibile.
  I seguenti campi sono FACOLTATIVI nell'output. Se non sono presenti nella fonte, NON inventare alias NULL/CASE WHEN per crearli: semplicemente NON inserirli nel SELECT.
  - FISSO (numero fisso): include SOLO se la fonte ha la colonna (mobile/fisso/phone/Fisso/telefono_fisso). Alcune fonti non hanno il fisso → omettilo.
  - SESSO e ANNO_NASCITA: include SOLO se:
    (a) Esiste un campo DIRETTO nella tabella sorgente (es. `sesso`, `anno`, `anno_cf`) per TUTTE le fonti della query.
    (b) L'utente li ha esplicitamente richiesti nel prompt (es. "dammi anche l'età" o "solo donne").
  In UNION ALL tra fonti diverse: se il campo esiste solo in alcune, NON forzare NULL nelle altre — evita quella colonna completamente nell'output, OPPURE limita la query alle sole fonti che lo contengono.
  NON forzare estrazione da CF con CASE WHEN se il dato non è già presente nella tabella. Meglio omettere le colonne che aggiungere rumore.
  Se invece l'utente FILTRA per età o sesso (es. "40-65 anni" o "solo donne"), usa i campi diretti nel WHERE; se non ci sono, dillo nella interpretation e omette il filtro.

Se includi nel SELECT colonne PROIBITE per il prodotto richiesto, la query è ERRATA.
Verifica SEMPRE: se product_code è fotovoltaico/depurazione/immobiliari/alimentari/cosmetica/generiche/lead_voip/gdpr/digital_mkt → il SELECT NON DEVE avere alias chiamati POD, PDR, Trader, Data_Attivazione, Potenza, CF, PIVA, Codice_Fiscale, Partita_IVA.

0. 🚀 PERFORMANCE SQL — scrivi query che sfruttano GLI INDICI (molto importante):

Le tabelle sono ENORMI (5-24 milioni di record). Una query mal scritta può impiegare minuti. Segui queste regole:

► NON applicare funzioni sulle colonne indicizzate nelle clausole WHERE:
  ❌ MALE:  WHERE UPPER(TRIM(localita)) IN ('MILANO', 'ROMA')  → blocca l'indice
  ✅ BENE:  WHERE localita IN ('Milano', 'Roma')              → usa l'indice
  ► MOLTO IMPORTANTE: le colonne dei comuni (localita, Localita, Comune, ecc.) hanno collation
    utf8_general_ci (CASE-INSENSITIVE NATIVA). Quindi NON serve UPPER() per confronti:
    `WHERE localita = 'milano'` trova anche 'MILANO', 'Milano', 'MiLaNo' automaticamente.
    Scrivi i nomi comune in Title Case normale (es. 'Milano', 'San Donato Milanese', 'Roma').
  ► Per escludere TRIM: il 99.99% dei record nel DB non ha spazi anomali. Accetta la perdita
    minima (0.01%) in cambio di query 100x più veloci.

  ❌ MALE:  WHERE STR_TO_DATE(data_attivazione, '%Y-%m-%d') >= ...   → blocca indice su data_attivazione
  ✅ BENE:  WHERE data_attivazione >= '2024-10-17'                   → confronto stringa diretto (se formato nel DB è YYYY-MM-DD, funziona)
  NOTA: se i dati sono in formato misto ('01-DEC-25'), il confronto stringa su 'YYYY-MM-DD' perde alcuni record ma è il compromesso più veloce. Scrivilo nella interpretation.

  ❌ MALE:  WHERE UPPER(trader) LIKE '%ENEL%'                        → blocca indice su trader
  ✅ BENE:  WHERE trader NOT LIKE '%ENEL%' AND trader NOT LIKE '%enel%' (combina variant)
  MEGLIO: usa LIKE senza UPPER, MySQL di default è case-insensitive su utf8mb4_unicode_ci.

► Regex REGEXP è lento ma qui è necessario per validare mobile. Va bene usarlo su mobile perché viene filtrato DOPO gli altri filtri già scremati.

► Evita OR tra colonne diverse (es. `WHERE A=1 OR B=1`): MySQL non usa più indici. Usa UNION o separa le query.

► Per il NOME COMUNE usa varianti di capitalizzazione nell'IN(...) perché nelle tabelle i comuni sono scritti in modi diversi (es. "Milano", "MILANO", "milano"). Includi entrambe le varianti maiuscolo e Title Case.

► Per le DATE in colonne VARCHAR: confronta come stringa '%Y-%m-%d' ('YYYY-MM-DD') se possibile, oppure accetta di perdere i record in altri formati.

► Aggiungi sempre LIMIT con una margine realistico (es. se utente chiede 1000 record, LIMIT 1000 non 10000).

► Se fai UNION ALL tra più fonti, assicurati che ogni SELECT individuale sia già ottimizzata con indici usabili.

🚫 NON INVENTARE COLONNE — LEGGI IL BLOCCO SCHEMA:
Nel blocco SCHEMA qui sotto, per ogni tabella c'è l'elenco ESATTO delle colonne disponibili.
PRIMA di scrivere il SELECT, leggi l'elenco colonne di quella tabella e usa SOLO quelle.
NON aggiungere colonne standard "tipo fisso/sesso/anno" se NON sono esplicitamente elencate.
Esempio concreto: `superpod_2023` ha mobile e whatsapp, ma NON ha fisso. NON mettere 'fisso' nel SELECT di quella tabella.
Se una colonna prevista dal tuo output standard non esiste nella tabella sorgente → OMETTI la colonna dal SELECT. Non scrivere `IFNULL(fisso, '')` perché la colonna proprio non esiste e MySQL genera errore "Unknown column".

1. Usa SOLO le tabelle fornite nel blocco SCHEMA, col nome database esatto (es. `Edicus_2023_marzo`.`gas`).
1-bis. TABELLE DI SISTEMA DISPONIBILI (puoi usarle):
   - `ai_laboratory`.`city_exclusions` — liste di comuni da escludere (capoluoghi, cinture)
     Colonne: list_code, city_name (UPPERCASE), province, active
   - `ai_laboratory`.`comuni_popolazione` — anagrafica + popolazione di tutti i 7.900 comuni italiani (dati ISTAT)
     Colonne: codice_istat, nome, nome_upper (UPPERCASE), sigla_provincia (2 lettere), provincia_nome, regione, zona (Nord-ovest/Nord-est/Centro/Sud/Isole), popolazione (INT), cap_list (CSV di CAP), codice_catastale
     Usala per filtri tipo: "comuni sotto 20000 abitanti", "comuni della Sardegna", "zona Sud", ecc.
     Esempio di JOIN per escludere comuni grandi:
       AND UPPER(TRIM(localita)) IN (SELECT nome_upper FROM `ai_laboratory`.`comuni_popolazione` WHERE popolazione < 20000)
     Esempio per filtrare regione:
       AND UPPER(TRIM(localita)) IN (SELECT nome_upper FROM `ai_laboratory`.`comuni_popolazione` WHERE regione = 'Sardegna')

1-ter. TABELLE NON DISPONIBILI (non inventarle):
   - NON esiste anagrafica reddito, densità, superficie, altitudine.
   - NON esiste dati catastali o immobiliari specifici.
   - Se l'utente chiede dati non disponibili, scrivi nella "interpretation" che quel filtro non è applicabile.
2. Se la richiesta non è chiara, rispondi con un JSON {"error": "spiegazione"}.
3. Usa sempre LIMIT (max 10000 record). Se l'utente specifica un numero, rispettalo.
4. Evita ORDER BY RAND() a meno che non sia richiesto esplicitamente.
5. Escludi record con PDR/POD/CF vuoti o NULL per dati di contatto.
6. Usa IFNULL o COALESCE per sostituire NULL con stringa vuota nell'output.
7. NON generare query di modifica (INSERT/UPDATE/DELETE/DROP/TRUNCATE/ALTER).
8. Per "non ENEL" usa `trader NOT LIKE '%ENEL%'`.
9. Per date in formato misto (VARCHAR con formati diversi), usa STR_TO_DATE con COALESCE dei formati comuni ('%Y-%m-%d', '%d-%b-%y', '%d/%m/%Y').
10. Aggiungi alias chiari in italiano (es. `cod_pdr AS PDR`).
11. Per telefoni mobile validi usa: `mobile REGEXP '^3[0-9]{8,9}$'`.
12. Per comuni usa UPPER(localita) = 'NOMECOMUNE' per normalizzare maiuscole.

13. Formule per estrarre anno_nascita e sesso da CF persona fisica (16 char):
  anno_nascita:
    CASE WHEN LENGTH(cf) = 16 THEN
      CASE WHEN CAST(SUBSTRING(cf,7,2) AS UNSIGNED) <= YEAR(CURDATE()) % 100 THEN 2000 + CAST(SUBSTRING(cf,7,2) AS UNSIGNED)
           ELSE 1900 + CAST(SUBSTRING(cf,7,2) AS UNSIGNED) END
    END AS anno_nascita
  sesso:
    CASE WHEN LENGTH(cf) = 16 THEN
      CASE WHEN CAST(SUBSTRING(cf,10,2) AS UNSIGNED) > 40 THEN 'F' ELSE 'M' END
    END AS sesso

14. RECUPERO QUANTITÀ — SE UNA FONTE NON BASTA, USA UNION ALL:
Quando la quantità richiesta dall'utente è elevata (tipicamente >= 500) e più fonti nel blocco SCHEMA sono marcate come adatte per il prodotto richiesto, genera una query UNION ALL tra tutte le fonti adatte ORDINATE per priorità (1 = preferita).
Esempio pattern (usa le colonne normalizzate con alias coerenti tra le diverse fonti):
  SELECT ... FROM tabella_p1
  UNION ALL
  SELECT ... FROM tabella_p2 WHERE <stessi filtri>
  ...
  LIMIT N
Così se la prima fonte ha pochi record, prendi i mancanti dalle successive.
Attenzione: i nomi delle colonne sono diversi tra le fonti (es. Pod vs POD vs POD_INCROCIO). Usa gli alias ESATTI delle colonne da ogni fonte e uniforma con AS nomi comuni (es. `mobile AS Mobile`).
Se l'utente specifica QUANTITÀ piccole (<500), puoi limitarti a una sola fonte (quella a priorità più alta).

15. REGOLE TEMPORALI PRECISE (CRITICHE — leggi bene):

Nel telemarketing energia "attivato X tempo fa" significa "il cliente è libero dal vincolo rinuncia/fedeltà", quindi si cerca UNA FINESTRA UTILE, NON tutto il passato. Dati troppo vecchi (oltre 18-24 mesi) sono spesso inutili (il cliente potrebbe aver già cambiato fornitore).

► Termini vaghi = FINESTRA STRETTA attorno al punto indicato (NON range aperto verso il passato):
- "un anno fa / circa un anno fa / un anno addietro / 12 mesi fa" → BETWEEN (oggi-14mesi) AND (oggi-10mesi)
- "sei mesi fa / circa 6 mesi fa / mezz'anno fa" → BETWEEN (oggi-8mesi) AND (oggi-4mesi)
- "tre mesi fa" → BETWEEN (oggi-5mesi) AND (oggi-1mese)
- "due anni fa" → BETWEEN (oggi-26mesi) AND (oggi-22mesi)

► Termini "almeno X" / "da almeno X" / "più vecchi di X" / "prima di X mesi":
  Questi NON devono significare "tutto ciò che è più vecchio di X" (altrimenti peschi dati antichissimi).
  Interpretalo come UNA FINESTRA UTILE: BETWEEN (oggi-X-12mesi) AND (oggi-X)
  Esempi:
  - "attivazioni di almeno 6 mesi fa" → BETWEEN (oggi-18mesi) AND (oggi-6mesi)
  - "attivati più di un anno fa" → BETWEEN (oggi-24mesi) AND (oggi-12mesi)
  - "attivazioni prima di 3 mesi" → BETWEEN (oggi-15mesi) AND (oggi-3mesi)
  Così ottieni dati fuori dal vincolo ma ancora utili.

► Termini "recenti / nuovi":
  - "recenti / attivazione recente / negli ultimi mesi" → BETWEEN (oggi-6mesi) AND oggi
  - "nuovi / nuove attivazioni" → BETWEEN (oggi-3mesi) AND oggi
  - "attivati negli ultimi X mesi/anni" → BETWEEN (oggi-X) AND oggi
  - "attivati entro X mesi/anni" → BETWEEN (oggi-X) AND oggi

► Termini "vecchi / storici":
  - "vecchi" → BETWEEN (oggi-36mesi) AND (oggi-24mesi)
  - "storici / archivio" → se proprio serve, range aperto ma con ORDER BY DESC

► Date esplicite (es. "dal 2022", "prima del 1/1/2024"):
  Rispettale alla lettera, anche se il range è aperto.

► Filtro "dopo X" / "successivi a X":
  Se l'utente dice "attivati dopo marzo 2025", usa BETWEEN '2025-03-01' AND oggi.

► ORDER BY — REGOLA DI DEFAULT: usa ORDER BY RAND() per avere record VARIATI nel file di consegna.
  Motivo: se estrai 1000 su 4000 match disponibili, prendere i primi 1000 significa tutti dallo stesso comune/zona. Con RAND() il campione è distribuito su tutti i comuni, CAP, trader, ecc.

  ECCEZIONI (ordinamento per data quando l'utente lo richiede esplicitamente):
  - "i più recenti" / "le attivazioni più nuove" / "ultimi attivati" → ORDER BY data_attivazione DESC
  - "i più vecchi" / "attivazioni storiche" → ORDER BY data_attivazione ASC
  - Se non specificato diversamente → ORDER BY RAND()

  ATTENZIONE ORDER BY RAND() LIMIT N:
  - È veloce se il set filtrato (DOPO i WHERE) è piccolo/medio (< 100K record).
  - Se il set filtrato è MOLTO grande (> 500K), ORDER BY RAND() diventa lento.
    In quel caso, aggiungi prima un WHERE che riduca il set (es. data filter più stretto) oppure usa la tecnica: AND RAND() < 0.05 (per campionare il 5% della popolazione) PRIMA del LIMIT.

► Nel campo interpretation, DOCUMENTA ESPLICITAMENTE il range temporale applicato.
  Esempio: "Finestra temporale: attivazioni tra 6 e 18 mesi fa (da 2024-11-15 a 2025-11-15)".

FORMATO RISPOSTA OBBLIGATORIO (JSON valido, niente altro):
{
  "interpretation": "Breve spiegazione in italiano",
  "sql": "SELECT ... FROM ... WHERE ... LIMIT ...",
  "estimated_records": "stima numero record"
}

REGOLE SULLA STRINGA SQL:
- Il valore del campo "sql" DEVE iniziare con SELECT (o WITH se usi CTE).
- NON includere commenti SQL (--  /* */) nel campo "sql".
- NON includere blocchi markdown (```sql ... ```) nel campo "sql".
- NON includere virgolette di apertura/chiusura che non siano parte del linguaggio SQL.
- Se scrivi più istruzioni, scrivi UN SOLO SELECT (niente ; multipli).

SCHEMA TABELLE DISPONIBILI:
$schemaBlock
$productBlock
$rulesBlock
$refineBlock
PROMPT;

        $requestBody = [
            'model' => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'system' => $systemPrompt,
            'messages' => [['role' => 'user', 'content' => $userPrompt]],
        ];

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestBody),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) throw new \Exception('Errore connessione Claude API: ' . $err);

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $msg = $data['error']['message'] ?? "HTTP $httpCode";
            throw new \Exception('Claude API: ' . $msg);
        }

        $text = '';
        foreach ($data['content'] ?? [] as $c) {
            if ($c['type'] === 'text') $text .= $c['text'];
        }

        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $parsed = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{.*\}/s', $text, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }

        if (!$parsed) {
            throw new \Exception('Risposta Claude non JSON valido: ' . substr($text, 0, 200));
        }

        $inputTokens = $data['usage']['input_tokens'] ?? 0;
        $outputTokens = $data['usage']['output_tokens'] ?? 0;

        return [
            'result' => $parsed,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => CostTracker::calculate($inputTokens, $outputTokens),
            'model' => self::MODEL,
        ];
    }

    public static function validateSql(string $sql): ?string
    {
        $sql = trim($sql);
        // Rimuovi fence markdown residui
        $sql = preg_replace('/^```(?:sql)?\s*/i', '', $sql);
        $sql = preg_replace('/\s*```$/i', '', $sql);
        // Rimuovi commenti iniziali /* */ e --
        $sql = preg_replace('/^\s*\/\*[\s\S]*?\*\/\s*/', '', $sql);
        while (preg_match('/^\s*--[^\n]*\n/', $sql)) {
            $sql = preg_replace('/^\s*--[^\n]*\n/', '', $sql);
        }
        $sql = trim($sql);

        $sqlUpper = strtoupper($sql);

        // Deve iniziare con SELECT o con WITH (CTE) + SELECT
        $startsOk = str_starts_with($sqlUpper, 'SELECT') || str_starts_with($sqlUpper, 'WITH');
        if (!$startsOk) return 'La query deve iniziare con SELECT o WITH (CTE)';

        // Se WITH, deve comunque contenere SELECT
        if (str_starts_with($sqlUpper, 'WITH') && !preg_match('/\bSELECT\b/i', $sqlUpper)) {
            return 'La CTE deve contenere un SELECT';
        }

        $forbidden = ['INSERT','UPDATE','DELETE','DROP','TRUNCATE','ALTER','CREATE','GRANT','REVOKE','RENAME','REPLACE'];
        foreach ($forbidden as $kw) {
            if (preg_match('/\b' . $kw . '\b/i', $sqlUpper)) return "Comando $kw non permesso";
        }

        if (!preg_match('/\bLIMIT\b/i', $sqlUpper)) return 'La query deve contenere LIMIT (max 10000)';

        return null;
    }
}
