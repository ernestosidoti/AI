<?php
/**
 * Estrai Parser — usa Claude per trasformare la richiesta utente in intent strutturato.
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

class EstraiParser
{
    public static function parse(string $userText, ?array $previousContext = null): array
    {
        $apiKey = self::apiKey();
        if (!$apiKey) throw new RuntimeException('API key Anthropic non configurata');

        $ctxBlock = '';
        if ($previousContext) {
            $ctxBlock = "\n\nCONTESTO PRECEDENTE (la richiesta precedente dell'utente):\n"
                . json_encode($previousContext, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                . "\n\nSe il nuovo testo è una richiesta INCOMPLETA (es. 'altri 100', 'stessa cosa per Roma', 'dammene ancora 500'), EREDITA dal contesto precedente i campi non specificati."
                . "\nSe invece è una richiesta COMPLETAMENTE NUOVA (cita cliente o prodotto diverso esplicitamente), ignora il contesto precedente."
                . "\nIn entrambi i casi, i campi esplicitamente menzionati nel nuovo testo vincono sul contesto precedente.";
        }

        // Contesto data odierna per risolvere date relative
        $today  = date('Y-m-d');
        $giorno = date('l, d F Y');
        $dateCtx = "\n\nDATA ODIERNA: $today ($giorno).\nUsala come riferimento per risolvere date relative come 'ieri', 'oggi', 'settimana scorsa', 'ultimi 7 giorni', 'questo mese', ecc.";

        $system = <<<PROMPT
Sei un parser di richieste per un bot Telegram di gestione liste commerciali.
Trasformi il testo dell'utente in un JSON strutturato.$ctxBlock$dateCtx

PRODOTTI VALIDI:
- energia (residenziale) — richiede POD o PDR
- energia_business — richiede POD/PDR lato business (fonte BUSINESS2025)
- fotovoltaico, depurazione, telefonia, cessione_quinto, finanziarie
- alimentari, immobiliari, cosmetica, generiche, lead_voip, gdpr, digital_mkt
- email (→ campagne email marketing / newsletter, fonte SKY)

RICONOSCIMENTO "energia" vs "energia_business":
- "energia business" / "energia B2B" / "aziende energia" / "business energetico" → "energia_business"
- "energia residenziale" / "energia" / "luce casa" / "bolletta luce" → "energia"
- "gas" da solo senza menzione business → tratta come "energia" (uso pdr)

NOTA: il filtro data attivazione e il requisito POD/PDR si applicano SOLO a "energia" e "energia_business". Per TUTTI gli altri prodotti, qualsiasi fonte va bene.

RICONOSCIMENTO MULTI-CATEGORIA:
Se l'utente menziona PIÙ categorie nella stessa richiesta (es. "energia business e residenziale", "cessione del quinto e finanziarie", "entrambi i tipi") restituisci l'ARRAY in "prodotti" E metti null in "prodotto".
Esempi:
- "energia business e residenziale" → prodotti = ["energia_business","energia"]
- "energia POD PDR residenziale e business" → prodotti = ["energia","energia_business"]
- "cessione del quinto e finanziarie" → prodotti = ["cessione_quinto","finanziarie"]
Se è una sola → usa "prodotto" e lascia "prodotti" null.

RICONOSCIMENTO PRODOTTO "email":
Se la richiesta contiene "email", "e-mail", "mail", "newsletter", "email marketing", "sky" → prodotto = "email".
Anche se ambiguo ma c'è indicazione di inviare via email anziché chiamare, usa "email".

RICONOSCIMENTO MULTI-SHEET (più estrazioni nello stesso ordine):
Se l'utente elenca più blocchi con quantità e filtri diversi, usa il campo "sheets" (array).
ESEMPIO CHIAVE DELL'UTENTE:
  "estrai 21000 record per spendogiusto residenziale energia
   8.000 anagrafiche per energia attivazione aprile 2026
   13.000 anagrafiche per energia attivazione da marzo 2026 a ritroso"
→ quantita (outer) = null (o 21000 totale)
→ sheets = [
    {"label":"Attivazione aprile 2026","quantita":8000,"filtri_override":{"data_att_mese_anno":["APR-26"]}},
    {"label":"Attivazione da marzo 2026 a ritroso","quantita":13000,"filtri_override":{"data_att_max_anno_mese":"2026-03"}}
  ]

Regole:
- Ogni sheet ha: label (string descrittivo), quantita (int), filtri_override (object con SOLO i campi diversi dall'outer).
- Cliente, prodotto, area restano a livello OUTER (condivisi).
- Se l'utente dice "21000 totale" o "in totale" → puoi mettere quel numero in outer quantita (informativo) e dettagliare gli sheet.
- Un SOLO blocco quantita+filtri → sheets = null e usa quantita nell'outer.

RICONOSCIMENTO DATE ATTIVAZIONE (data_att_*):
Usa la DATA ODIERNA (sopra) per calcolare date relative.

Casi base:
- "attivazione aprile 2026" → data_att_mese_anno = ["APR-26"]
- "attivazione marzo-aprile 2026" → data_att_mese_anno = ["MAR-26","APR-26"]
- "attivazione da marzo 2026 a ritroso" / "fino a marzo 2026" → data_att_max_anno_mese = "2026-03"
- "attivazione dal 2024 in poi" → data_att_min_anno_mese = "2024-01"

Range relativi (da oggi indietro) — IMPORTANTISSIMO:
- "entro N mesi" / "ultimi N mesi" / "negli ultimi N mesi" / "N mesi a ritroso" significano SEMPRE "dalla data odierna indietro di N mesi fino a oggi". Output:
    data_att_min_anno_mese = (oggi - N mesi) in formato YYYY-MM
    data_att_max_anno_mese = (oggi) in formato YYYY-MM
  ESEMPIO: se oggi è 2026-04-22 e l'utente scrive "entro 6 mesi" → min = "2025-10", max = "2026-04".
- "entro l'anno" / "ultimo anno" → min = oggi-12mesi, max = oggi (YYYY-MM).
- "quest'anno" → min = gennaio dell'anno corrente, max = oggi.

Mesi inglese abbreviati 3 lettere: JAN FEB MAR APR MAY JUN JUL AUG SEP OCT NOV DEC
Anno abbreviato 2 cifre (26 = 2026, 25 = 2025).

RICONOSCIMENTO AREA (supporta LISTE e RANGE):
⚠️ Se l'utente NON menziona esplicitamente un'area geografica, NON inventare "nazionale". Lascia area.tipo = null e area.valori = []. Il bot chiederà.
- "Milano" → tipo="provincia" o "comune" (scegli il più probabile dal contesto; se "provincia di Milano" → provincia, se solo "Milano" → provincia)
- "Milano, Bergamo, Brescia" → tipo="provincia", valori=["Milano","Bergamo","Brescia"]
- "Lombardia, Veneto" → tipo="regione", valori=["Lombardia","Veneto"]
- "San Donato Milanese, San Giuliano Milanese, Melegnano" → tipo="comune", valori=[...]
- "20100, 20121, 20122" → tipo="cap", valori=["20100","20121","20122"]
- "da 20100 a 20145" / "20100-20145" → tipo="cap", valori=["20100-20145"]   (range mantenuto come stringa con trattino)
- "tutta Italia" / "nazionale" → tipo="nazionale", valori=[]
- Misto "Lombardia ma solo Milano provincia" → prevale "provincia" con valori=["Milano"]

OUTPUT:
Rispondi SOLO con un JSON valido, senza testo aggiuntivo, senza markdown code fences.
Schema:
{
  "action": "estrai" | "stat" | "storico" | "list_stats" | "view_stat" | "magazzino_manage" | "repeat_last" | "help" | "explain" | "unknown",
  "cliente_hint": string | null,
  "cliente_regione": string | null,     // regione del cliente: "Calabria", "Sicilia", ecc.
  "cliente_zona": string | null,        // macroarea: "nord","sud","centro","isole","nord ovest","nord est","sud e isole"
  "cliente_provincia": string | null,   // sigla o nome provincia del cliente
  "cliente_mesi_ultimo_ordine": int | null, // se l'utente dice "ha comprato N mesi fa/di recente/pochi mesi fa/ultimi X mesi"
  "prodotto": string | null,            // singolo prodotto; usa questo se è UNO solo
  "prodotti": [string] | null,          // ARRAY se l'utente indica più categorie insieme (es. "energia business e residenziale", "cessione quinto e finanziarie")
  "quantita": int | null,               // solo per estrai. Se l'utente dice "tutti/tutto/massimo/tutti quelli disponibili/senza limite" usa 500000.
  "area": {
    "tipo": "provincia"|"regione"|"comune"|"cap"|"nazionale"|null,
    "valori": [string]                  // array — supporta MULTIPLI valori (comuni, province, regioni, CAP)
  },
  "group_by": "provincia"|"regione"|"comune"|null,
  "date_from": string | null,           // ISO YYYY-MM-DD, usato per list_stats / storico
  "date_to": string | null,             // ISO YYYY-MM-DD
  "stat_id": int | null,                // per action=view_stat se l'utente cita un ID
  "explain_topic": string | null,       // per action=explain: estrai|stat|storico|list_stats|view_stat|ripeti|magazzino|menu|tutto
  "magazzino_op": "list"|"change"|"reset"|null,  // per action=magazzino_manage
  "filtri": {
    "no_stranieri": bool,
    "only_mobile": bool,
    "anno_min": int | null,
    "anno_max": int | null,
    "eta_min": int | null,              // età minima (da CF posizioni 7-8)
    "eta_max": int | null,              // età massima
    "with_extra_numbers": bool | null,  // arricchisci xlsx con Tel_Extra_1..N da master_cf_numeri
    "data_att_mese_anno": [string] | null,  // esempi: ["APR-26"], ["MAR-26","FEB-26"] (mese-anno inglese abbreviato a 3 lettere)
    "data_att_max_anno_mese": string | null,  // "2026-03" = fino a marzo 2026 a ritroso (tutti mesi precedenti + inclusi)
    "data_att_min_anno_mese": string | null,
    "extra": string
  },

  "sheets": [                // SE l'utente chiede PIÙ estrazioni nello stesso ordine → array di sub-intent
    {                        // ciascuno eredita cliente+prodotto+area dall'outer, ma ha quantita+filtri specifici
      "label": string,       // es. "Attivazione aprile 2026"
      "quantita": int,
      "filtri_override": {}  // stessa struttura di "filtri" — merge con quello outer (override per questo sheet)
    }
  ] | null,
  "ambiguo": [string]
}

RICONOSCIMENTO ACTION (ordine di priorità):
1. "mostra/vedi/fammi vedere la stat <numero>", "richiamami la stat N", solo un numero → action = "view_stat", stat_id = N
2. "stat salvate", "statistiche salvate", "elenca stat", "mostrami le stat", "le ultime stat", "stat di <periodo>", "cosa abbiamo fatto ieri/stamattina/questa settimana" → action = "list_stats"
3. "stat X", "statistica", "quanti ne abbiamo", "disponibili", "report", "conteggio" con un cliente → action = "stat"
4. "storico", "ultimi ordini", "cosa ha acquistato", "cronologia", "ordini di", "consegne di" con un cliente → action = "storico"
5. "estrai", "voglio", "dammi", "mandami", "servono", numeri seguiti da tipo di lista → action = "estrai"
6. "spiegami X", "come funziona X", "cosa significa X", "a cosa serve X", "fammi capire X" → action = "explain", explain_topic = X (vedi mapping sotto)
7. "aiuto", "help", "cosa sai fare", "comandi" → action = "help"
7-bis. "ripeti l'ultima delivery", "ripeti l'ultima estrazione", "rifai ultima", "ripeti quella di prima", "fai la stessa cosa di prima" → action = "repeat_last"
8. Magazzino management: → action = "magazzino_manage"
   Parole chiave: "magazzino", "magazzini", "deduplica", "dedup", "dedupe", "anti-join".
   - "togli/rimuovi/resetta/cancella/dimentica/disattiva il magazzino (o la deduplica) di X" → magazzino_op = "reset"
   - "cambia/riscegliere/riscegli/scegli un altro/sostituisci/modifica magazzino di X" → magazzino_op = "change"
   - "associa/imposta/aggiungi/lega magazzino per X" → magazzino_op = "change"
   - "voglio riscegliere il magazzino" / "riscegli magazzino" → magazzino_op = "change"
   - "non voglio usare il magazzino/la deduplica" per X → magazzino_op = "reset"
   - "magazzini salvati / lista magazzini / mostrami i magazzini" → magazzino_op = "list"
   Se il cliente non è specificato nella frase ma c'è stata una delivery recente, usa quel cliente (verrà messo da contesto).
9. Nulla di chiaro → action = "unknown"

MAPPING explain_topic (estrai il nome della funzionalità dall'input):
- "estrai" / "estrazione" / "generare lista" / "generare file" → "estrai"
- "stat" / "statistica" / "disponibilità" / "quanti ne abbiamo" → "stat"
- "storico" / "ordini cliente" / "cronologia" / "cosa ha comprato" → "storico"
- "stat salvate" / "lista stat" / "archivio stat" → "list_stats"
- "vedi stat" / "richiama stat" / "richiamo" / "recupera stat" → "view_stat"
- "ripeti" / "altri" / "follow up" / "post delivery" / "contesto" → "ripeti"
- "magazzino" / "dedup" / "anti-join" → "magazzino"
- "menu" / "cosa vuoi fare" / "comandi disponibili" → "menu"
- Se generico "spiegami tutto" → "tutto"

Per action="stat":
- "per provincia" → group_by = "provincia"
- "per comune" → group_by = "comune"
- "per regione" → group_by = "regione"
- Se non specificato ma area è regione → default group_by = "provincia"
- Se non specificato ma area è provincia → default group_by = "comune"
- quantita = null (non serve per stat)

Per action="list_stats" (elenco stat salvate): calcola date_from/date_to in formato ISO YYYY-MM-DD usando la DATA ODIERNA fornita sopra.
- "oggi" → date_from=oggi, date_to=oggi
- "ieri" → date_from=ieri, date_to=ieri
- "questa settimana" → lunedì della settimana corrente → oggi
- "settimana scorsa" → lunedì della settimana scorsa → domenica della settimana scorsa
- "questo mese" → primo del mese corrente → oggi
- "mese scorso" → primo del mese scorso → ultimo giorno del mese scorso
- "ultimi 7 giorni" / "ultima settimana" → oggi-7 → oggi
- "dal 10 al 20 aprile" → 2026-04-10 → 2026-04-20 (usa anno corrente se non specificato)
- Nessun vincolo temporale → date_from e date_to null (default: ultime 15)
- Se "stat salvate per &lt;cliente&gt;" → usa anche cliente_hint.

REGOLE:
- "non stranieri" / "solo italiani" → no_stranieri = true
- "depurazione acqua" → prodotto = "depurazione"
- "provincia di X" → area.tipo="provincia", valori=["X"]
- "regione X" / "in X" (se X è regione) → area.tipo="regione"
- Se quantità non specificata → quantita = null + ambiguo = ["quantita"]
- Se cliente non citato → cliente_hint = null + ambiguo = ["cliente"]
- Se prodotto non chiaro → ambiguo = ["prodotto"]
- FILTRO ETÀ (da CF):
  - "18-30 anni", "tra 18 e 30", "età 20 a 35" → eta_min/eta_max espliciti
  - "giovani" / "under 30" / "sotto i 30" → eta_min=18 eta_max=30
  - "over 50" / "sopra i 50" → eta_min=50 eta_max=80
  - "adulti" → eta_min=30 eta_max=55
  - "senior" / "anziani" → eta_min=55 eta_max=85
- NUMERI EXTRA: "con numeri aggiuntivi", "tutti i numeri", "più telefoni per contatto", "numeri secondari" → with_extra_numbers=true
PROMPT;

        $body = [
            'model'       => 'claude-sonnet-4-5-20250929',
            'max_tokens'  => 512,
            'system'      => $system,
            'messages'    => [['role' => 'user', 'content' => $userText]],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException("Claude API error: $err");

        $resp = json_decode($raw, true);
        if (!isset($resp['content'][0]['text'])) {
            throw new RuntimeException("Claude response invalid: " . substr($raw, 0, 500));
        }
        $text = $resp['content'][0]['text'];
        // Pulisci eventuali fence ```json
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) throw new RuntimeException("Claude ha restituito JSON non valido: $text");

        // Normalizza
        $parsed['action']   = $parsed['action']   ?? 'estrai';
        $parsed['ambiguo']  = $parsed['ambiguo']  ?? [];
        $parsed['filtri']   = $parsed['filtri']   ?? [];
        $parsed['filtri']['no_stranieri'] = $parsed['filtri']['no_stranieri'] ?? false;
        $parsed['filtri']['only_mobile']  = $parsed['filtri']['only_mobile']  ?? true;
        $parsed['area']     = $parsed['area']     ?? ['tipo' => null, 'valori' => []];
        $parsed['group_by'] = $parsed['group_by'] ?? null;
        $parsed['date_from']= $parsed['date_from']?? null;
        $parsed['date_to']  = $parsed['date_to']  ?? null;
        $parsed['stat_id']  = $parsed['stat_id']  ?? null;
        $parsed['explain_topic'] = $parsed['explain_topic'] ?? null;
        $parsed['prodotti']      = $parsed['prodotti']      ?? null;
        $parsed['magazzino_op']  = $parsed['magazzino_op']  ?? null;

        // Salva il testo originale per safeguard (es. verifica filtri data non parsati)
        $parsed['_raw_text'] = $userText;

        // Log cost (stima veloce dai tokens)
        $inTok  = $resp['usage']['input_tokens']  ?? 0;
        $outTok = $resp['usage']['output_tokens'] ?? 0;
        $parsed['_meta'] = ['input_tokens' => $inTok, 'output_tokens' => $outTok];

        // Log su queries table per tracking costi giornaliero
        self::logClaudeCall('parse', $userText, $inTok, $outTok,
            'action=' . ($parsed['action'] ?? 'n/a') . ' prod=' . ($parsed['prodotto'] ?? 'n/a'),
            ['prodotto' => $parsed['prodotto'] ?? null]);

        return $parsed;
    }

    /**
     * Parse di un blob libero con dati anagrafici cliente. Usa Claude per estrarre
     * i campi strutturati da un paste disordinato.
     */
    public static function parseClientBlob(string $text): array
    {
        $apiKey = self::apiKey();
        if (!$apiKey) throw new RuntimeException('API key Anthropic non configurata');

        $system = <<<PROMPT
Sei un parser di anagrafica cliente italiana. L'utente ti incollerà un testo libero con i dati di un cliente (può essere disordinato, su più righe, con o senza etichette). Devi estrarre i campi e restituirli come JSON strutturato.

SCHEMA OUTPUT (solo JSON, niente markdown fences):
{
  "piva": string | null,               // Partita IVA italiana, 11 cifre
  "codice_fiscale": string | null,     // CF persona fisica 16 char
  "ragione_sociale": string | null,    // Nome ditta (SRL, SPA, ecc.) se impresa
  "nome": string | null,               // Nome persona di riferimento (o di titolare/libero professionista)
  "cognome": string | null,            // Cognome persona di riferimento
  "email": string | null,              // Email valida
  "telefono": string | null,           // Cellulare o fisso
  "indirizzo": string | null,          // Via/Piazza/Corso + nome (senza civico)
  "civico": string | null,
  "comune": string | null,
  "provincia": string | null,          // sigla 2 char se riconoscibile (MI, RM, VR, ecc.)
  "cap": string | null,
  "regione": string | null             // Italiano esteso (Lombardia, Veneto, ...)
}

REGOLE:
- Se PIVA e CF sono uguali (11 cifre), metti entrambi con lo stesso valore.
- Nome ditta con "SRL/SPA/SAS/SNC/SRLS" → ragione_sociale.
- Persona di riferimento separata dal nome ditta → nome + cognome.
- Se provincia è scritta per esteso (es. "Verona") e conosci la sigla, deduci "VR". Altrimenti lascia il nome.
- Se CAP a 5 cifre rilevato, mettilo in cap.
- Ignora etichette generiche tipo "piva:", "email:", "cell:" — usa il valore successivo.
PROMPT;

        $body = [
            'model'       => 'claude-sonnet-4-5-20250929',
            'max_tokens'  => 512,
            'system'      => $system,
            'messages'    => [['role' => 'user', 'content' => $text]],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $resp = json_decode($raw, true);
        if (!isset($resp['content'][0]['text'])) {
            throw new RuntimeException("Claude parseClientBlob response invalid");
        }
        $txt = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($resp['content'][0]['text']));
        $parsed = json_decode($txt, true);
        if (!is_array($parsed)) throw new RuntimeException("Claude client-blob JSON invalid: $txt");
        // Normalizza campi null
        foreach (['piva','codice_fiscale','ragione_sociale','nome','cognome','email','telefono','indirizzo','civico','comune','provincia','cap','regione'] as $k) {
            $parsed[$k] = $parsed[$k] ?? null;
        }
        // Log tracking costi
        $inTok  = $resp['usage']['input_tokens']  ?? 0;
        $outTok = $resp['usage']['output_tokens'] ?? 0;
        $summary = ($parsed['ragione_sociale'] ?? ($parsed['nome'] . ' ' . $parsed['cognome'])) . ' · PIVA=' . ($parsed['piva'] ?? '-');
        self::logClaudeCall('clientblob', $text, $inTok, $outTok, $summary);
        return $parsed;
    }

    /** Costo approssimativo per Claude Sonnet 4.5 */
    private static function computeCost(int $inTok, int $outTok): float
    {
        // Sonnet 4.5 pricing: $3/MTok input, $15/MTok output
        return ($inTok / 1_000_000) * 3.0 + ($outTok / 1_000_000) * 15.0;
    }

    /** Salva una chiamata Claude nella tabella ai_laboratory.queries */
    private static function logClaudeCall(string $callType, string $userText, int $inTok, int $outTok, ?string $interpretation = null, ?array $extra = null): void
    {
        try {
            $pdo = remoteDb('ai_laboratory');
            $cost = self::computeCost($inTok, $outTok);
            $stmt = $pdo->prepare("INSERT INTO queries
                (user_name, user_prompt, model, input_tokens, output_tokens, cost_usd, status, interpretation, product_code, cliente_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'interpreted', ?, ?, ?, NOW())");
            $stmt->execute([
                'tg_bot:' . $callType,
                mb_substr($userText, 0, 2000),
                'claude-sonnet-4-5-20250929',
                $inTok,
                $outTok,
                $cost,
                $interpretation ? mb_substr($interpretation, 0, 1000) : null,
                $extra['prodotto'] ?? null,
                isset($extra['cliente_id']) ? (int)$extra['cliente_id'] : null,
            ]);
        } catch (\Throwable $e) {
            error_log('logClaudeCall error: ' . $e->getMessage());
        }
    }

    private static function apiKey(): ?string
    {
        $pdo = remoteDb('ai_laboratory');
        $s = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'anthropic_api_key'");
        $s->execute();
        return $s->fetchColumn() ?: null;
    }
}
