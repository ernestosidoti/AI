<?php
/**
 * FlowAgent — orchestrator AI-driven per la fase di raccolta intent.
 *
 * Invece di usare stati rigidi (S_AWAIT_CLIENTE, S_AWAIT_AREA, S_AWAIT_DATE, ecc.)
 * questo agente mantiene una conversazione con Claude che accumula l'intent finché non è
 * completo, poi delega a FlowStats/FlowEstrai/ecc. per l'esecuzione deterministica.
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/estrai_engine.php';

class FlowAgent
{
    const S_CHATTING  = 'agent_chatting';
    const MAX_TURNS   = 10;

    /** Entry point quando l'utente scrive testo libero e non c'è conversazione attiva */
    public static function start(int $chatId, array $user, string $text): void
    {
        // Inizializza nuova conversazione
        $history = [['role'=>'user','content'=>$text]];
        self::turn($chatId, $user, $history, []);
    }

    /** Chiamato quando siamo nello stato S_CHATTING e l'utente risponde */
    public static function handleReply(int $chatId, array $user, string $text, array $conv): void
    {
        if (FlowEstrai::checkStopIntent($chatId, $user, $text, $conv)) return;
        $data = $conv['data'];
        $history = $data['history'] ?? [];
        $partial = $data['partial_intent'] ?? [];
        $turn    = (int)($data['turn'] ?? 0);
        if ($turn >= self::MAX_TURNS) {
            TG::sendMessage($chatId, "🤷 Non sono riuscito a capire la richiesta dopo " . self::MAX_TURNS . " tentativi. Proviamo daccapo — riformula in modo diverso.");
            self::clearState($chatId);
            FlowEstrai::mainMenu($chatId);
            return;
        }
        $history[] = ['role'=>'user','content'=>$text];
        self::turn($chatId, $user, $history, $partial, $turn + 1);
    }

    /** Un turno di conversazione: chiama Claude e processa la risposta */
    private static function turn(int $chatId, array $user, array $history, array $partial, int $turn = 1): void
    {
        TG::sendChatAction($chatId, 'typing');
        try {
            $result = self::callAgent($history, $partial, $user);
        } catch (\Throwable $e) {
            TG::sendMessage($chatId, "❌ Errore agente: <code>" . htmlspecialchars($e->getMessage()) . "</code>");
            self::clearState($chatId);
            FlowEstrai::mainMenu($chatId);
            return;
        }

        $status = $result['status'] ?? 'reject';
        $message = $result['message'] ?? '';
        $intent  = $result['intent']  ?? $partial;
        // Salva anche il raw text così i safeguard downstream funzionano
        $rawText = '';
        foreach ($history as $h) if (($h['role'] ?? '') === 'user') $rawText .= ' ' . ($h['content'] ?? '');
        $intent['_raw_text'] = trim($rawText);

        // Aggiungi risposta agent alla history
        $history[] = ['role'=>'assistant','content'=> $message ?: json_encode($intent)];

        if ($status === 'ready') {
            self::clearState($chatId);
            if ($message) TG::sendMessage($chatId, $message);
            self::executeIntent($chatId, $user, $intent);
            return;
        }

        if ($status === 'multi') {
            // Esecuzione di più intent in sequenza (es. 2 stat)
            $intents = $result['intents'] ?? [$intent];
            self::clearState($chatId);
            foreach ($intents as $i => $subIntent) {
                $subIntent['_raw_text'] = $intent['_raw_text'] ?? '';
                if ($message && $i === 0) TG::sendMessage($chatId, $message);
                TG::sendMessage($chatId, "▶️ Esecuzione richiesta " . ($i+1) . "/" . count($intents));
                self::executeIntent($chatId, $user, $subIntent);
            }
            return;
        }

        if ($status === 'reject') {
            TG::sendMessage($chatId, $message ?: "Non sono riuscito a capire. Puoi riformulare?");
            self::clearState($chatId);
            FlowEstrai::mainMenu($chatId);
            return;
        }

        // status = need_info
        TG::sendMessage($chatId, $message ?: "Mi serve qualche info in più, dimmi.");
        self::saveState($chatId, $user, self::S_CHATTING, [
            'history' => $history, 'partial_intent' => $intent, 'turn' => $turn,
        ]);
    }

    /** Chiama Claude API con system prompt + history + partial intent */
    private static function callAgent(array $history, array $partial, array $user): array
    {
        $apiKey = self::apiKey();
        if (!$apiKey) throw new RuntimeException('API key Anthropic non configurata');

        $today = date('Y-m-d');
        $giorno = date('l, d F Y');
        $userInfo = "Utente corrente: {$user['name']} (role={$user['role']}, id={$user['id']}).";

        $system = <<<PROMPT
Sei un assistente conversazionale per un bot Telegram di un'azienda che vende liste di contatti commerciali.

Il tuo compito: raccogliere via conversazione naturale l'intent completo di una richiesta, poi segnalare che è pronta per l'esecuzione. I flussi deterministici (conferme, esecuzione, email, magazzino) sono gestiti dopo — tu ti occupi solo della fase di chiarimento e raccolta info.

DATA ODIERNA: $today ($giorno) — usa per calcolare date relative ("ieri", "entro 6 mesi", "settimana scorsa", ecc.)
$userInfo

AZIONI SUPPORTATE:

• action="estrai" — Estrai lista contatti
  Richiesti: cliente_hint, prodotto (chiamato "categoria" all'utente), quantita, area (tipo + valori)
  Opzionali: filtri (data_att_*, no_stranieri, only_mobile), sheets (multi-foglio)
  QUANTITA: accetta numero (es. 2000) OPPURE la stringa "tutti" (sinonimi: "tutto","tutte","massimo","max","illimitato","senza limite","all") che significa "estrai tutti i disponibili" — passa esattamente la parola "tutti" come quantita, il sistema la traduce in cap 500000.
  IMPORTANTE: nei messaggi all'utente usa SEMPRE la parola "categoria" non "prodotto" (es. "Per quale CATEGORIA?", non "Per quale prodotto?"). Il campo nell'intent rimane "prodotto" per compatibilità tecnica.

• action="stat" — Statistica disponibilità
  Richiesti: cliente_hint, prodotto (o prodotti array), area
  Opzionali: filtri data, group_by (provincia/comune/regione)

• action="storico" — Storico ordini cliente
  Richiesti: cliente_hint

• action="chat_history" — Sunto delle conversazioni passate con il bot per un cliente
  Richiesti: cliente_hint
  Opzionali: date_from / date_to (formato YYYY-MM-DD) → arco temporale del filtro

  TRIGGER frase:
    - "cosa abbiamo fatto per X", "cosa ho fatto per X" → action=chat_history, cliente_hint=X
    - "ultime conversazioni X", "ultime chat X", "sessioni X" → idem
    - "cronologia chat X" / "storico conversazioni X" → idem

  TRIGGER arco temporale (calcola date_from e date_to in formato ISO YYYY-MM-DD usando la DATA ODIERNA $today):
    - "oggi" → date_from=oggi, date_to=oggi
    - "ieri" → date_from=ieri, date_to=ieri
    - "questa settimana" → lunedì corrente → oggi
    - "settimana scorsa" / "una settimana fa" → lunedì-domenica della settimana scorsa
    - "questo mese" → primo del mese → oggi
    - "mese scorso" / "il mese scorso" → primo del mese scorso → ultimo del mese scorso
    - "anno scorso" / "l'anno scorso" → 1 gennaio → 31 dicembre dell'anno scorso
    - "ultimi N giorni" / "ultima settimana" → oggi-N → oggi
    - "da DATE a DATE" → date specifiche
    - "dal 10 al 20 aprile" → 2026-04-10 → 2026-04-20 (anno corrente se non specificato)
    - "dal 5 marzo" (senza fine) → date_from=2026-03-05, date_to=oggi
  Se l'utente menziona un arco temporale AMBIGUO ("qualche settimana fa", "un po' di tempo fa", "recentemente") → status=need_info, message="Da che data a che data vuoi vedere lo storico?"
  Se NON menziona alcun arco → date_from/date_to entrambi null (mostra le ultime 30 sessioni)

  NOTA: questa è la cronologia di CHAT col bot, distinta da action=storico (che è storico ORDINI).
  Se l'utente non distingue chiaramente "chat" vs "ordini", usa action=storico (più frequente).

• action="list_stats" — Elenco stat salvate
  Opzionali: cliente_hint, date_from/date_to (formato YYYY-MM-DD)

• action="view_stat" — Richiama stat per ID (mostra messaggio testo)
  Richiesti: stat_id (int)

• action="export_stat_excel" — Genera e invia l'Excel di riepilogo di una stat salvata
  Richiesti: stat_id (int) — se l'utente dice "di questa/ultima stat" usa stat_id=0 (significato: ultima eseguita)
  Trigger: frasi come "genera excel", "scarica excel della stat", "dammi il file excel", "export xlsx"

• action="magazzino_manage" — Gestione magazzino cliente (RICHIEDE parole "magazzino"/"dedup"/"deduplica"/"anti-join")
  Richiesti: magazzino_op (list|change|reset). Se change/reset serve cliente_hint.
  ATTENZIONE: solo se il testo contiene esplicitamente "magazzino" o "dedup" o "anti-join". Altrimenti NON è questa action.

• action="repeat_last" — Ripeti ultima spedizione

• action="help" / "explain" — Mostra aiuto o spiega un comando
  Topic supportati: estrai, stat, storico, list_stats, view_stat, ripeti, magazzino, menu, tutto, business_examples, consumer_examples, esempi
  TRIGGER per esempi:
    - BUSINESS: "esempi business", "fai esempi business", "esempi B2B", "fai vedere esempi aziende", "che esempi business hai", "esempi di richieste business" → action=explain, explain_topic=business_examples
    - CONSUMER/RESIDENZIALI: "esempi consumer", "esempi residenziali", "esempi privati", "esempi B2C", "fammi esempi privati", "esempi liste residenziali" → action=explain, explain_topic=consumer_examples
    - GENERALI: "fai esempi", "esempi", "che esempi hai", "fammi vedere esempi" (senza specifica) → action=explain, explain_topic=esempi (mostra panoramica)

• action="toggle_menu" — Abilita/disabilita il menu automatico «Cosa vuoi fare?»
  Campi: menu_enabled (bool) — true per abilitare, false per disabilitare
  TRIGGER (PRIORITÀ ALTA su magazzino_manage quando la parola è aiuto/menu):
    - Frasi con parole "aiuto", "menu", "menù" insieme a "disabilita/disattiva/nascondi/togli/spegni/silenzia" → menu_enabled=false
    - Frasi con parole "aiuto", "menu", "menù" insieme a "abilita/attiva/mostra/riaccendi/riattiva" → menu_enabled=true
  Esempi ESATTI:
    • "disabilita aiuto" → toggle_menu, menu_enabled=false
    • "disattiva il menu" → toggle_menu, menu_enabled=false
    • "nascondi aiuto" → toggle_menu, menu_enabled=false
    • "non voglio vedere il menù" → toggle_menu, menu_enabled=false
    • "togli il menu di aiuto" → toggle_menu, menu_enabled=false (NON magazzino_manage, perché non c'è "magazzino")
    • "abilita aiuto" → toggle_menu, menu_enabled=true
    • "riattiva il menu" → toggle_menu, menu_enabled=true

PRODOTTI VALIDI:
energia, energia_business, fotovoltaico, depurazione, telefonia, cessione_quinto, finanziarie, alimentari, immobiliari, cosmetica, generiche, lead_voip, gdpr, digital_mkt, email

AREA:
{tipo: "provincia"|"regione"|"comune"|"cap"|"nazionale", valori: [string]}

RICONOSCIMENTO TIPO AREA (IMPORTANTE):
- Parole "paese", "cittadina", "paesino", "comune", "borgo", "località", "nel comune di" → tipo="comune"
- Parole "provincia di", "in provincia" → tipo="provincia"
- Parole "regione", "nella regione" o nome di regione nota (Lombardia, Sicilia, Sardegna, Liguria, Piemonte, Veneto, Lazio, Campania, Puglia, Toscana, Emilia-Romagna, Calabria, Marche, Abruzzo, Umbria, Sardegna, ecc.) → tipo="regione"
- Sigla di 2 lettere maiuscole (MI, RM, NA, GE, SV, AL, TO) → tipo="provincia"
- CAP (5 cifre) → tipo="cap"
- "tutta Italia", "nazionale", "in Italia" → tipo="nazionale"
- Nome ambiguo senza qualificatore → NON inventare: chiedi all'utente "Intendi il comune, la provincia o la regione di X?"

Per action=stat con area.tipo="comune" imposta group_by=null (il comune è già l'aggregazione finale).

FILTRI DATA (formati standard):
- data_att_mese_anno: array come ["APR-26","MAR-26"] (mese inglese 3-char + anno 2-char)
- data_att_max_anno_mese: "YYYY-MM" (fino a, a ritroso)
- data_att_min_anno_mese: "YYYY-MM" (dalla data in poi)
Esempi: "aprile 2026" → data_att_mese_anno=["APR-26"]
"entro 6 mesi" → min=oggi-6mesi max=oggi (mese-anno)
"da marzo 2026 a ritroso" → max="2026-03"

SPLIT MOBILE/FISSO (percentuali):
  Quando l'utente specifica una mix, salva nei filtri pct_mobile + pct_fisso (interi 0-100, somma 100).
  Esempi:
    • "5000 numeri 80% mobili 20% fissi" → pct_mobile=80, pct_fisso=20
    • "metà metà mobili e fissi" / "50/50" → pct_mobile=50, pct_fisso=50
    • "70 mobili 30 fissi" / "70-30" → pct_mobile=70, pct_fisso=30
    • "solo mobili" → tipo_telefono="mobile" (NON pct_*)
    • "solo fissi" → tipo_telefono="fisso"
  Se imposti pct_mobile + pct_fisso, NON impostare tipo_telefono.

FONTI DATI (importante per estrazioni e statistiche):
- DEFAULT residenziale: master_cf_numeri (40,5M righe consumer dedup) — veloce, contiene tutto
- DEFAULT business: master_piva_numeri (5,3M righe B2B dedup)
- ENERGIA: usa multi-fonte UNION (POD/PDR + dati attivazione)
- Quando l'utente dice "approfondisci", "ricerca approfondita", "tutte le fonti", "esteso", "completo", "approfondita"
  → imposta filtri.approfondita=true (estende a TUTTE le fonti, no solo master)

FILTRI BUSINESS / B2B:
Quando l'utente cerca contatti AZIENDALI (parole: "business", "B2B", "aziende", "ditte", "imprese", "P.IVA", "professionisti"),
imposta filtri.tipo_target="business". Questo fa usare al sistema il master B2B consolidato (5,3M righe, già deduplicato per (piva,tel)).
NOTA: NON usare tipo_target=business se l'utente chiede esplicitamente POD o PDR (in quel caso servono fonti energia legacy).
Esempi:
  • "5000 aziende in Lombardia" → tipo_target=business
  • "estrai PIVA con email a Roma" → tipo_target=business + filtri.with_email=true
  • "imprese ATECO 47 in Sicilia" → tipo_target=business + filtri.ateco="47"
  • "fissi business Sardegna" → tipo_target=business + tipo_telefono="fisso"
  • "energia business POD scaduti" → energia_business (NON master, serve POD)

FILTRI ETÀ (da codice fiscale — posizioni 7-8 del CF = anno nascita 2-cifre):
- filtri.eta_min: int (età minima, anni)
- filtri.eta_max: int (età massima, anni)
Riconosci frasi tipo:
  • "18-30 anni" / "tra 18 e 30" / "età 20 a 35" → eta_min/eta_max espliciti
  • "giovani" → eta_min=18, eta_max=30
  • "under 30" / "sotto i 30" → eta_min=18, eta_max=30
  • "over 50" / "sopra i 50" → eta_min=50, eta_max=80
  • "adulti" → eta_min=30, eta_max=55
  • "senior" / "anziani" → eta_min=55, eta_max=85
  • "maggiorenni" senza altra specifica → eta_min=18 (no max)
Data riferimento: oggi $today. Il filtro si applica sia all'estrazione che alla stat.

NUMERI EXTRA da master_cf_numeri:
- filtri.with_extra_numbers: true se l'utente chiede "con numeri aggiuntivi", "tutti i numeri", "più numeri per contatto", "telefoni secondari" — l'xlsx avrà colonne Tel_Extra_1..N.
- Default false se non esplicitato.

IMPORTANTE — OUTPUT:
Rispondi SEMPRE con UN SOLO JSON valido, senza markdown fences, senza testo prima/dopo.

Schema output:
{
  "status": "ready" | "need_info" | "multi" | "reject",
  "message": "testo breve da mostrare all'utente (obbligatorio se need_info, opzionale altrimenti)",
  "intent": {...},               // usare quando status=ready o need_info
  "intents": [ {...}, {...} ]    // usare quando status=multi (più azioni in fila, es. 2 stat)
}

REGOLE CONVERSAZIONE:
1. Se hai tutti i campi richiesti per l'azione → status=ready + intent completo
2. Se manca qualcosa → status=need_info, message = domanda MIRATA (una sola cosa alla volta, breve), intent = parziale accumulato fino a ora
3. Se l'utente dice qualcosa che NON si riferisce a queste azioni → status=reject, message spiega cortesemente cosa sai fare
4. Se l'utente chiede più azioni (es. "2 stat, una per aprile e l'altra dal 2024 al 2026") → status=multi con array intents
5. Se l'utente corregge ("no, intendevo Lombardia") aggiorna l'intent accumulato e, se ora è completo, ready, altrimenti continua a chiedere
6. NON inventare cliente o PIVA. Se il cliente non è chiaro chiedi. Se scrive "generico" → cliente_hint="generico"
7. Per ambiguità date usa need_info con message mirato ("Da quale a quale mese?")

ESEMPI:

Input: "stat energia Lombardia per cerullo"
Output: {"status":"ready","message":"","intent":{"action":"stat","cliente_hint":"cerullo","prodotto":"energia","area":{"tipo":"regione","valori":["Lombardia"]}}}

Input: "estrai 2000 leads"
Output: {"status":"need_info","message":"Per quale cliente? E di quale prodotto?","intent":{"action":"estrai","quantita":2000}}

Input: "solo aprile" (dopo aver già detto che serve una stat energia per cerullo Sardegna)
Output: {"status":"ready","message":"","intent":{"action":"stat","cliente_hint":"cerullo","prodotto":"energia","area":{"tipo":"regione","valori":["Sardegna"]},"filtri":{"data_att_mese_anno":["APR-26"]}}}

Input: "fai 2 stat: una aprile 2026 e l'altra da marzo 2024 a marzo 2026, per spendogiusto energia Sardegna"
Output: {"status":"multi","message":"OK, faccio 2 statistiche in sequenza.","intents":[
  {"action":"stat","cliente_hint":"spendogiusto","prodotto":"energia","area":{"tipo":"regione","valori":["Sardegna"]},"filtri":{"data_att_mese_anno":["APR-26"]}},
  {"action":"stat","cliente_hint":"spendogiusto","prodotto":"energia","area":{"tipo":"regione","valori":["Sardegna"]},"filtri":{"data_att_min_anno_mese":"2024-03","data_att_max_anno_mese":"2026-03"}}
]}

Input: "spiegami le stat salvate"
Output: {"status":"ready","message":"","intent":{"action":"explain","explain_topic":"list_stats"}}

NON includere "_raw_text" nell'intent, lo aggiunge il sistema.
PROMPT;

        // Prefill: forziamo Claude a partire con "{" così la risposta è obbligatoriamente JSON
        $messages = $history;
        $messages[] = ['role' => 'assistant', 'content' => '{'];

        $body = [
            'model'      => 'claude-sonnet-4-5-20250929',
            'max_tokens' => 1024,
            'system'     => $system,
            'messages'   => $messages,
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
            CURLOPT_TIMEOUT => 45,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException("Claude API error: $err");

        $resp = json_decode($raw, true);
        if (!isset($resp['content'][0]['text'])) throw new RuntimeException("Agent response invalid: " . substr($raw, 0, 300));

        $txt = trim($resp['content'][0]['text']);
        // Riaggiungo il "{" che è stato consumato dal prefill
        if ($txt !== '' && $txt[0] !== '{') $txt = '{' . $txt;
        // Strip eventuali fences
        $txt = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $txt);
        $parsed = json_decode($txt, true);
        if (!is_array($parsed)) {
            // Fallback: estrai il primo blocco JSON {...} da prosa eventuale
            if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $txt, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }
        if (!is_array($parsed)) throw new RuntimeException("Agent non-JSON: $txt");

        // Log costi
        $inTok  = $resp['usage']['input_tokens']  ?? 0;
        $outTok = $resp['usage']['output_tokens'] ?? 0;
        self::logCall($history, $inTok, $outTok, $parsed['status'] ?? 'unknown');

        return $parsed;
    }

    /** Esegue l'intent completo delegando al flusso deterministico appropriato */
    private static function executeIntent(int $chatId, array $user, array $intent): void
    {
        $action = $intent['action'] ?? 'unknown';
        switch ($action) {
            case 'stat':
                require_once __DIR__ . '/telegram_flow_stats.php';
                FlowStats::run($chatId, $user, $intent);
                return;
            case 'estrai':
                require_once __DIR__ . '/telegram_flow_estrai.php';
                // Passa l'intent già strutturato — NIENTE re-parsing
                $intent['action'] = 'estrai';
                FlowEstrai::startWithIntent($chatId, $user, $intent, null);
                return;
            case 'storico':
                require_once __DIR__ . '/telegram_flow_storico.php';
                FlowStorico::run($chatId, $user, $intent);
                return;
            case 'chat_history':
                require_once __DIR__ . '/TGArchive.php';
                self::handleChatHistory($chatId, $intent);
                return;
            case 'list_stats':
                require_once __DIR__ . '/telegram_flow_stats.php';
                FlowStats::listStats($chatId, $intent['cliente_hint'] ?? null, 30, $intent['date_from'] ?? null, $intent['date_to'] ?? null);
                return;
            case 'view_stat':
                require_once __DIR__ . '/telegram_flow_stats.php';
                $id = (int)($intent['stat_id'] ?? 0);
                if ($id > 0) FlowStats::viewStat($chatId, $id);
                else TG::sendMessage($chatId, "Indica un ID stat valido.");
                return;
            case 'export_stat_excel':
                require_once __DIR__ . '/telegram_flow_stats.php';
                $id = (int)($intent['stat_id'] ?? 0);
                if ($id === 0) {
                    // ultima stat eseguita
                    $pdo = remoteDb('ai_laboratory');
                    $id = (int)$pdo->query("SELECT id FROM stat_history ORDER BY executed_at DESC LIMIT 1")->fetchColumn();
                }
                if ($id <= 0) { TG::sendMessage($chatId, "❌ Nessuna stat trovata."); return; }
                TG::sendMessage($chatId, "⏳ Genero l'Excel di riepilogo per stat #$id...");
                TG::sendChatAction($chatId, 'upload_document');
                try {
                    $path = FlowStats::generateStatExcel($id);
                    if ($path && is_file($path)) TG::sendDocument($chatId, $path, "📊 Riepilogo stat #$id");
                    else TG::sendMessage($chatId, "❌ Generazione xlsx fallita.");
                } catch (\Throwable $e) {
                    TG::sendMessage($chatId, "❌ Errore: <code>" . htmlspecialchars($e->getMessage()) . "</code>");
                }
                FlowEstrai::mainMenu($chatId);
                return;
            case 'magazzino_manage':
                require_once __DIR__ . '/telegram_flow_magazzino.php';
                FlowMagazzino::run($chatId, $user, $intent);
                return;
            case 'repeat_last':
                require_once __DIR__ . '/telegram_flow_estrai.php';
                FlowEstrai::start($chatId, $user, 'ripeti ultima spedizione');
                return;
            case 'explain':
                require_once __DIR__ . '/explanations.php';
                $topic = $intent['explain_topic'] ?? 'tutto';
                TG::sendMessage($chatId, Explanations::get($topic));
                return;
            case 'help':
                require_once __DIR__ . '/telegram_flow_estrai.php';
                // Force menu anche se disabilitato perché l'utente chiede aiuto esplicitamente
                $enabled = FlowEstrai::isMenuEnabled($chatId);
                if (!$enabled) FlowEstrai::setMenuEnabled($chatId, true);
                FlowEstrai::mainMenu($chatId);
                if (!$enabled) FlowEstrai::setMenuEnabled($chatId, false);
                return;
            case 'toggle_menu':
                require_once __DIR__ . '/telegram_flow_estrai.php';
                $en = (bool)($intent['menu_enabled'] ?? true);
                FlowEstrai::setMenuEnabled($chatId, $en);
                TG::sendMessage($chatId, $en
                    ? "✅ Menu aiuto <b>riabilitato</b>. Lo vedrai a fine di ogni azione."
                    : "🔕 Menu aiuto <b>disabilitato</b>. Non te lo mostro più.\n<i>Per riattivarlo scrivi «abilita aiuto» o «mostra menu».</i>");
                return;
            default:
                TG::sendMessage($chatId, "Non so cosa fare con l'azione: <code>" . htmlspecialchars($action) . "</code>");
                require_once __DIR__ . '/telegram_flow_estrai.php';
                FlowEstrai::mainMenu($chatId);
        }
    }

    /**
     * Mostra il sunto delle ultime sessioni di chat per un cliente.
     * Trigger: "cosa abbiamo fatto per X", "ultime conversazioni X", ecc.
     */
    private static function handleChatHistory(int $chatId, array $intent): void
    {
        $hint = trim($intent['cliente_hint'] ?? '');
        if ($hint === '') {
            TG::sendMessage($chatId, "Per quale cliente vuoi vedere lo storico chat? Scrivi il nome (es. <i>cosa abbiamo fatto per cerullo</i>).");
            return;
        }

        // Arco temporale (opzionale)
        $dateFrom = $intent['date_from'] ?? null;
        $dateTo   = $intent['date_to']   ?? null;
        // Validazione formato ISO YYYY-MM-DD
        if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = null;
        if ($dateTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo = null;

        // Cerca per nome cliente nelle sessioni archive
        require_once __DIR__ . '/TGArchive.php';
        require_once __DIR__ . '/estrai_engine.php';
        $sessions = TGArchive::sessionsForCliente(null, $hint, 100, $dateFrom, $dateTo);

        if (!$sessions) {
            // Fallback: trova il cliente in backoffice e prova con il nome esatto
            $cands = EstraiEngine::findClienti($hint, [], 1);
            if ($cands) {
                $cli = $cands[0];
                $cnome = $cli['ragione_sociale'] ?: trim(($cli['nome']??'') . ' ' . ($cli['cognome']??''));
                $sessions = TGArchive::sessionsForCliente((int)$cli['id'], $cnome, 100, $dateFrom, $dateTo);
            }
        }

        if (!$sessions) {
            $rangeStr = ($dateFrom || $dateTo) ? " nel periodo " . ($dateFrom ?: '...') . " → " . ($dateTo ?: '...') : "";
            TG::sendMessage($chatId,
                "📭 Nessuna sessione chat trovata per <b>" . htmlspecialchars($hint) . "</b>$rangeStr.\n\n" .
                "<i>(Le sessioni vengono taggate automaticamente quando il bot identifica il cliente. Per vedere lo storico ordini classico usa: <code>storico " . htmlspecialchars($hint) . "</code>)</i>"
            );
            return;
        }

        // Aggregazione per data
        $clienteFound = '';
        $byDate = [];
        foreach ($sessions as $s) {
            $d = substr($s['ended_at'], 0, 10);
            $byDate[$d][] = $s;
            if (!$clienteFound && $s['cliente_name']) $clienteFound = $s['cliente_name'];
        }
        $clienteDisplay = $clienteFound ?: $hint;

        $msg = "📚 <b>Storico chat per " . htmlspecialchars($clienteDisplay) . "</b>\n";
        if ($dateFrom || $dateTo) {
            $msg .= "<i>📅 Periodo: " . ($dateFrom ?: '...') . " → " . ($dateTo ?: 'oggi') . " · " . count($sessions) . " sessioni</i>\n\n";
        } else {
            $msg .= "<i>Ultime " . count($sessions) . " sessioni di conversazione col bot</i>\n\n";
        }

        $totEstrai = 0; $totStat = 0; $totStorico = 0;
        foreach ($sessions as $s) {
            $a = $s['action_type'] ?? '';
            if ($a === 'estrai') $totEstrai++;
            elseif ($a === 'stat') $totStat++;
            elseif ($a === 'storico') $totStorico++;
        }
        $msg .= "📊 <b>Riepilogo</b>: " . count($sessions) . " sessioni · "
              . "📥 $totEstrai estrazioni · 📊 $totStat stat · 📦 $totStorico storico\n\n";

        $shown = 0;
        foreach ($byDate as $date => $list) {
            if ($shown >= 30) break;
            $msg .= "━━━ <b>" . $date . "</b> ━━━\n";
            foreach ($list as $s) {
                if ($shown >= 30) break;
                $time = substr($s['started_at'], 11, 5);
                $action = $s['action_type'] ? '['. strtoupper($s['action_type']) . ']' : '[?]';
                $by = $s['user_name'] ? ' · ' . $s['user_name'] : '';
                $msgIn = (int)$s['msg_in'];
                $msg .= "$time $action ($msgIn msg)$by\n";
                $shown++;
            }
            $msg .= "\n";
        }

        if (count($sessions) > 30) {
            $msg .= "<i>...e altre " . (count($sessions) - 30) . " sessioni precedenti.</i>\n\n";
        }
        $url = "http://localhost:8899/ai/conversazioni.php?cliente=" . urlencode($clienteDisplay);
        if ($dateFrom) $url .= "&from=" . urlencode($dateFrom);
        if ($dateTo)   $url .= "&to="   . urlencode($dateTo);
        $msg .= "🔗 Per vedere il dettaglio: <a href=\"$url\">archivio web</a>";

        TG::sendMessage($chatId, $msg);
    }

    private static function apiKey(): ?string
    {
        $pdo = remoteDb('ai_laboratory');
        $s = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'anthropic_api_key'");
        $s->execute();
        return $s->fetchColumn() ?: null;
    }

    private static function logCall(array $history, int $inTok, int $outTok, string $status): void
    {
        try {
            $pdo = remoteDb('ai_laboratory');
            $cost = ($inTok / 1_000_000) * 3.0 + ($outTok / 1_000_000) * 15.0;
            $lastUser = '';
            foreach (array_reverse($history) as $h) if (($h['role'] ?? '') === 'user') { $lastUser = $h['content']; break; }
            $stmt = $pdo->prepare("INSERT INTO queries (user_name, user_prompt, model, input_tokens, output_tokens, cost_usd, status, interpretation, created_at)
                                   VALUES ('tg_bot:agent', ?, 'claude-sonnet-4-5-20250929', ?, ?, ?, 'interpreted', ?, NOW())");
            $stmt->execute([mb_substr($lastUser, 0, 2000), $inTok, $outTok, $cost, "status=$status · history=" . count($history)]);
        } catch (\Throwable $e) {
            error_log('Agent logCall error: ' . $e->getMessage());
        }
    }

    private static function saveState(int $chatId, array $user, string $state, array $data): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("REPLACE INTO tg_conversations (chat_id, user_id, flow, state, data) VALUES (?, ?, 'agent', ?, ?)")
            ->execute([$chatId, $user['id'], $state, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    public static function clearState(int $chatId): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("DELETE FROM tg_conversations WHERE chat_id = ? AND flow = 'agent'")->execute([$chatId]);
    }
}
