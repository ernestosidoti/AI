<?php
/**
 * Flow /estrai — conversazione multi-turno via Telegram.
 * Stato persistente in ai_laboratory.tg_conversations.
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/estrai_parser.php';
require_once __DIR__ . '/estrai_engine.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/telegram_flow_stats.php';
require_once __DIR__ . '/telegram_flow_storico.php';
require_once __DIR__ . '/telegram_flow_magazzino.php';
require_once __DIR__ . '/explanations.php';

class FlowEstrai
{
    // States
    const S_CHECKING     = 'checking';          // appena parsato
    const S_MISSING      = 'await_missing';     // manca almeno un campo obbligatorio
    const S_CLIENTE      = 'await_cliente';     // scegliere tra più match
    const S_MAGAZZINO    = 'await_magazzino';   // A/B/C
    const S_PREZZO       = 'await_prezzo';      // numero in €
    const S_CONFIRM      = 'await_confirm';     // si/no per ESTRAZIONE
    const S_AWAIT_SEND   = 'await_send';        // si/no per INVIO email (dopo estrazione)
    const S_RUNNING      = 'running';
    const S_POST         = 'post_delivery';     // ascolta feedback / nota finale

    public static function start(int $chatId, array $user, string $args, ?array $previousContext = null): void
    {
        if ($args === '') {
            TG::sendMessage($chatId,
                "🔍 <b>Nuova estrazione</b>\n\n" .
                "Descrivimi cosa ti serve, esempio:\n" .
                "<code>/estrai 2000 depurazione Cerullo provincia Milano non stranieri</code>\n\n" .
                "Oppure scrivilo in linguaggio naturale:\n" .
                "<code>mi servono 3000 numeri per cessione del quinto in Campania per cliente Lopez</code>"
            );
            return;
        }

        TG::sendChatAction($chatId, 'typing');
        try {
            $intent = EstraiParser::parse($args, $previousContext);
        } catch (\Throwable $e) {
            TG::sendMessage($chatId, "❌ Errore parsing: " . $e->getMessage());
            return;
        }

        self::runWithIntent($chatId, $user, $intent, $previousContext);
    }

    /**
     * Entry-point per intent già strutturato (es. raccolto dal FlowAgent).
     * Salta EstraiParser e va direttamente alla risoluzione cliente + filtri.
     */
    public static function startWithIntent(int $chatId, array $user, array $intent, ?array $previousContext = null): void
    {
        // Normalizza "tutti" / quantità vuota
        $q = $intent['quantita'] ?? null;
        if (is_string($q)) {
            $ql = strtolower(trim($q));
            if (in_array($ql, ['tutti','tutto','tutte','massimo','max','illimitato','senza limite','all'], true)) {
                $intent['quantita']  = 500000;
                $intent['qty_tutti'] = true;
            } else {
                $clean = preg_replace('/[^\d]/', '', $q);
                $intent['quantita'] = ctype_digit($clean) && (int)$clean > 0 ? (int)$clean : 0;
            }
        }
        if (!empty($intent['qty_tutti']) && (int)($intent['quantita'] ?? 0) < 500000) {
            $intent['quantita'] = 500000;
        }

        self::runWithIntent($chatId, $user, $intent, $previousContext);
    }

    private static function runWithIntent(int $chatId, array $user, array $intent, ?array $previousContext): void
    {
        // Se manca il prodotto ma il cliente è noto, tento di ereditarlo
        if (empty($intent['prodotto']) && !empty($intent['cliente_hint'])) {
            $filters = self::buildClientFiltersFromIntent($intent);
            $cands = EstraiEngine::findClienti($intent['cliente_hint'], $filters, 1);
            if (count($cands) === 1) {
                $info = EstraiEngine::getLastProdottoInfo((int)$cands[0]['id']);
                if ($info) {
                    $intent['prodotto'] = $info['prodotto'];
                    $src = $info['source'] === 'orders' ? '📦 dagli ordini commerciali' : '🤖 dalle consegne AI Lab';
                    TG::sendMessage($chatId, "💡 Categoria <b>" . htmlspecialchars($info['prodotto']) . "</b> ereditato $src (" . ($info['nome_originale']) . ", " . $info['data'] . ")");
                }
            }
        }

        // Routing per action
        $action = $intent['action'] ?? 'estrai';
        if ($action === 'stat') {
            FlowStats::run($chatId, $user, $intent);
            return;
        }
        if ($action === 'storico') {
            FlowStorico::run($chatId, $user, $intent);
            return;
        }
        if ($action === 'list_stats') {
            FlowStats::listStats($chatId, $intent['cliente_hint'] ?? null, 30, $intent['date_from'] ?? null, $intent['date_to'] ?? null);
            return;
        }
        if ($action === 'view_stat') {
            $id = (int)($intent['stat_id'] ?? 0);
            if ($id > 0) FlowStats::viewStat($chatId, $id);
            else TG::sendMessage($chatId, "Indica l'ID della stat: es. <i>mostrami stat 7</i>");
            return;
        }
        if ($action === 'help') {
            self::mainMenu($chatId);
            TG::sendMessage($chatId, "💡 Per una spiegazione dettagliata di un comando scrivi <i>«spiegami X»</i> (es. <i>spiegami le stat salvate</i>, <i>cosa significa ripeti l'ultima spedizione</i>).");
            return;
        }
        if ($action === 'repeat_last') {
            // Cerca l'ultima spedizione effettuata
            $last = EstraiEngine::getLastDelivery();
            if (!$last) {
                TG::sendMessage($chatId, "📭 Nessuna spedizione recente trovata. Fai una nuova richiesta.");
                self::mainMenu($chatId);
                return;
            }
            $msg  = "🔁 <b>Ultima spedizione registrata</b>\n\n";
            $msg .= "Cliente: <b>" . htmlspecialchars($last['cliente_nome']) . "</b>\n";
            $msg .= "Categoria: " . htmlspecialchars($last['prodotto']) . "\n";
            $msg .= "Area: " . htmlspecialchars($last['area'] ?: '-') . "\n";
            $msg .= "Quantità: " . number_format((int)$last['contatti_inviati']) . " · Prezzo: €" . number_format((float)$last['prezzo_eur'], 2) . "\n";
            $msg .= "Data: " . substr($last['sent_at'], 0, 16) . "\n\n";
            $msg .= "Ricostruisco una nuova richiesta con gli stessi parametri.\n";
            $msg .= "Rispondi con la <b>nuova quantità</b> (o <b>stessa</b> per rifare identica, o <code>/annulla</code>).";
            TG::sendMessage($chatId, $msg);
            self::saveState($chatId, $user, 'await_repeat_qty', ['last_delivery' => $last]);
            return;
        }
        if ($action === 'magazzino_manage') {
            // Se manca il cliente ma c'è contesto precedente, usa quello
            if (empty($intent['cliente_hint']) && $previousContext) {
                $hint = $previousContext['cliente_hint'] ?? null;
                if (!$hint && !empty($previousContext['cliente'])) {
                    $c = $previousContext['cliente'];
                    $hint = $c['ragione_sociale'] ?: trim(($c['nome'] ?? '') . ' ' . ($c['cognome'] ?? ''));
                }
                if ($hint) $intent['cliente_hint'] = $hint;
            }
            FlowMagazzino::run($chatId, $user, $intent);
            return;
        }
        if ($action === 'explain') {
            $topic = $intent['explain_topic'] ?? null;
            if (!$topic) {
                TG::sendMessage($chatId, Explanations::get('tutto'));
            } else {
                TG::sendMessage($chatId, Explanations::get($topic));
            }
            return;
        }
        if ($action === 'unknown') {
            self::mainMenu($chatId, "🤔 <b>Non ho capito — ecco cosa posso fare:</b>");
            return;
        }

        // Validazione minima — se manca qualcosa, CHIEDO invece di errorare
        $missing = self::whichFieldsMissing($intent);
        if ($missing) {
            self::askMissing($chatId, $user, $intent, $missing);
            return;
        }

        // Scorciatoia: se abbiamo un contesto precedente E il cliente_hint coincide con quello,
        // riusa direttamente il cliente senza chiedere (niente search)
        if ($previousContext && !empty($previousContext['cliente'])) {
            $prevCliente = $previousContext['cliente'];
            $sameCliente = false;
            if (empty($intent['cliente_hint'])) {
                $sameCliente = true;
            } else {
                $hint = strtolower($intent['cliente_hint']);
                $hay  = strtolower(($prevCliente['ragione_sociale'] ?? '') . ' ' . ($prevCliente['nome'] ?? '') . ' ' . ($prevCliente['cognome'] ?? '') . ' ' . ($prevCliente['partita_iva'] ?? ''));
                if (str_contains($hay, $hint)) $sameCliente = true;
            }
            if ($sameCliente) {
                TG::sendMessage($chatId, "🧠 Proseguo per <b>" . htmlspecialchars($prevCliente['ragione_sociale'] ?: (($prevCliente['nome'] ?? '') . ' ' . ($prevCliente['cognome'] ?? ''))) . "</b> (stesso cliente della consegna precedente).");
                self::setCliente($chatId, $user, $intent, $prevCliente);
                return;
            }
        }

        // Risolvi cliente — passa eventuali filtri (regione/zona/provincia/mesi_ultimo_ordine)
        $filters = self::buildClientFiltersFromIntent($intent);
        $candidates = EstraiEngine::findClienti($intent['cliente_hint'], $filters, 5);
        if (!$candidates) {
            // Cliente non trovato → avvia direttamente il blob-paste del nuovo cliente
            TG::sendMessage($chatId,
                "❌ Cliente <b>\"" . htmlspecialchars($intent['cliente_hint']) . "\"</b> non trovato.\n"
              . "<i>Procedo con la creazione del nuovo cliente — scrivi «annulla» per fermare.</i>"
            );
            require_once __DIR__ . '/telegram_flow_new_client.php';
            FlowNewClient::start($chatId, $user, ['flow'=>'estrai', 'intent'=>$intent]);
            return;
        }
        if (!$candidates) {
            TG::sendMessage($chatId, "❌ Nessun cliente trovato per \"" . htmlspecialchars($intent['cliente_hint']) . "\". Verifica il nome/PIVA.");
            return;
        }

        if (count($candidates) === 1) {
            self::setCliente($chatId, $user, $intent, $candidates[0]);
            return;
        }

        // Ambiguo → chiedi scelta
        $msg = "🔎 Ho trovato <b>" . count($candidates) . "</b> clienti possibili:\n\n";
        foreach ($candidates as $i => $c) {
            $msg .= ($i+1) . ". <b>" . htmlspecialchars($c['ragione_sociale'] ?: ($c['nome'].' '.$c['cognome'])) . "</b>";
            if ($c['partita_iva']) $msg .= " · " . htmlspecialchars($c['partita_iva']);
            if ($c['comune'])      $msg .= " · " . htmlspecialchars($c['comune']);
            $msg .= "\n";
        }
        $msg .= "\nRispondi col numero (1-" . count($candidates) . "), oppure <code>/annulla</code>.";
        TG::sendMessage($chatId, $msg);
        self::saveState($chatId, $user, self::S_CLIENTE, [
            'intent' => $intent, 'candidates' => $candidates,
        ]);
    }

    /** Chiamato dall'handler quando l'utente ha una conversazione attiva su questo flow */
    public static function handleReply(int $chatId, array $user, string $text, array $conv): void
    {
        // Universal stop intent (chiede conferma)
        if (self::checkStopIntent($chatId, $user, $text, $conv)) return;

        $state = $conv['state'];
        $data  = $conv['data'] ?? [];

        $t = strtolower(trim($text));
        if ($t === '/annulla') {
            self::clearState($chatId);
            TG::sendMessage($chatId, "❎ Estrazione annullata.");
            self::mainMenu($chatId);
            return;
        }

        // Comandi di restart in linguaggio naturale (valgono in QUALSIASI stato tranne S_POST)
        if ($state !== self::S_POST && preg_match('/^(ho sbagliato|ricomincia|rifacciamo|rifai|rifaccio|restart|da capo|ricomincio|torno indietro|indietro|cambio idea)\b/iu', $t)) {
            self::clearState($chatId);
            TG::sendMessage($chatId, "🔄 OK, ricominciamo. Scrivi la nuova richiesta (estrazione, stat, storico…).");
            self::mainMenu($chatId);
            return;
        }

        // Interrupt: comandi magazzino/stat/storico/spiegami in stati intermedi del flusso estrazione
        // Non applicare in S_POST (ha già il suo handler) né in S_AWAIT_SEND (è una conferma)
        $intermediateStates = [self::S_CLIENTE, self::S_MAGAZZINO, self::S_PREZZO, self::S_CONFIRM, self::S_MISSING];
        if (in_array($state, $intermediateStates, true)) {
            $interrupt = self::detectInterruptCommand($t);
            if ($interrupt) {
                $prevIntent  = $data['intent']  ?? null;
                $prevCliente = $data['cliente'] ?? null;
                $prevCtx = [
                    'cliente'      => $prevCliente,
                    'cliente_hint' => $prevIntent['cliente_hint'] ?? null,
                    'prodotto'     => $prevIntent['prodotto']     ?? null,
                    'area'         => $prevIntent['area']         ?? null,
                ];
                self::clearState($chatId);
                TG::sendMessage($chatId, "↪️ Interrompo il flusso corrente per il nuovo comando.");
                self::start($chatId, $user, $text, $prevCtx);
                return;
            }
        }

        switch ($state) {
            case self::S_MISSING:    self::stepAwaitMissing($chatId, $user, $text, $data); return;
            case 'await_repeat_qty':
                $last = $data['last_delivery'];
                $qty = preg_match('/^(stessa|uguale)/iu', $t) ? (int)$last['contatti_inviati'] : (int)preg_replace('/[^\d]/', '', $t);
                if ($qty <= 0) { TG::sendMessage($chatId, "Scrivi un numero valido o «stessa»."); return; }
                self::clearState($chatId);

                // Usa intent_json se disponibile (replay fedele), altrimenti fallback
                $newIntent = null;
                if (!empty($last['intent_json'])) {
                    $dec = json_decode($last['intent_json'], true);
                    if (is_array($dec)) $newIntent = $dec;
                }
                if (!$newIntent) {
                    // Fallback: ricostruzione di base (perde filtri data ecc.)
                    $newIntent = [
                        'action'       => 'estrai',
                        'cliente_hint' => $last['cliente_nome'],
                        'prodotto'     => $last['prodotto'],
                        'area'         => ['tipo'=>'regione','valori'=>[trim($last['area'])]],
                        'filtri'       => ['only_mobile'=>true,'no_stranieri'=>false],
                    ];
                    TG::sendMessage($chatId, "⚠️ Questa spedizione è precedente al salvataggio dell'intent completo — ricostruisco solo cliente+prodotto+area. Eventuali filtri data andranno reinseriti.");
                }
                $newIntent['action']   = 'estrai';
                $newIntent['quantita'] = $qty;
                unset($newIntent['sheets']); // eventuali sheets vecchi li scartiamo

                $filtInfo = [];
                if (!empty($newIntent['filtri']['data_att_mese_anno']))     $filtInfo[] = 'mesi: ' . implode(', ', $newIntent['filtri']['data_att_mese_anno']);
                if (!empty($newIntent['filtri']['data_att_max_anno_mese'])) $filtInfo[] = 'fino a: ' . $newIntent['filtri']['data_att_max_anno_mese'];
                if (!empty($newIntent['filtri']['data_att_min_anno_mese'])) $filtInfo[] = 'da: ' . $newIntent['filtri']['data_att_min_anno_mese'];
                $filtStr = $filtInfo ? ' · filtro data: ' . implode(' | ', $filtInfo) : '';

                TG::sendMessage($chatId, "🔁 Rilancio estrazione: <b>" . htmlspecialchars($last['cliente_nome']) . "</b> · " . htmlspecialchars($last['prodotto']) . " · " . number_format($qty) . " record · " . htmlspecialchars($last['area']) . $filtStr);
                self::resumeAfterMissing($chatId, $user, $newIntent);
                return;
            case 'estrai_client_nf':
                $ans = strtoupper(trim($text));
                if ($ans === 'B' || preg_match('/(nuov|crea)/iu', $text)) {
                    self::clearState($chatId);
                    FlowNewClient::start($chatId, $user, ['flow'=>'estrai', 'intent'=>$data['intent']]);
                    return;
                }
                if ($ans === 'C' || preg_match('/(annull|stop|no)/iu', $text)) {
                    self::clearState($chatId);
                    TG::sendMessage($chatId, "❎ Annullato.");
                    self::mainMenu($chatId);
                    return;
                }
                TG::sendMessage($chatId, "Rispondi <b>B</b> (crea nuovo) o <b>C</b> (annulla).");
                return;
            case self::S_CLIENTE:    self::stepCliente($chatId, $user, $text, $data); return;
            case self::S_MAGAZZINO:  self::stepMagazzino($chatId, $user, $text, $data); return;
            case self::S_PREZZO:     self::stepPrezzo($chatId, $user, $text, $data); return;
            case self::S_CONFIRM:    self::stepConfirm($chatId, $user, $text, $data); return;
            case self::S_AWAIT_SEND: self::stepAwaitSend($chatId, $user, $text, $data); return;
            case 'await_price_post_ext':
                $norm = str_replace(',', '.', trim($text));
                if (!preg_match('/(\d+(?:\.\d+)?)/', $norm, $m)) {
                    TG::sendMessage($chatId, "Prezzo non valido. Scrivi solo il numero (es. 1500).");
                    return;
                }
                $prezzo = (float)$m[1];
                // Ora rientro nel flusso "post-estrazione" con prezzo impostato
                $intent = $data['intent']; $cliente = $data['cliente']; $magTable = $data['magazzino_table'];
                $ext = $data['ext']; $actualRecipient = $data['actual_recipient']; $isTestRedirect = $data['is_test_redirect'];
                $nomeClnt = $cliente['ragione_sociale'] ?: ($cliente['nome'].' '.$cliente['cognome']);
                $clienteEmail = $cliente['email'];
                $sizeKb = round(filesize($ext['path']) / 1024, 1);
                $reportMsg  = "📋 <b>Pronta da inviare</b>\n\n";
                $reportMsg .= "👤 Cliente: <b>" . htmlspecialchars($nomeClnt) . "</b>\n";
                $reportMsg .= "📦 Categoria: " . htmlspecialchars($intent['prodotto']) . "\n";
                $reportMsg .= "🗺 Area: " . htmlspecialchars(implode(', ', $intent['area']['valori'] ?? [])) . "\n";
                $reportMsg .= "📊 Record estratti: <b>" . number_format($ext['count']) . "</b>\n";
                $reportMsg .= "🏘 Comuni: " . $ext['comuni'] . "\n";
                $reportMsg .= "🗄 Magazzino: " . ($magTable ? '<code>'.htmlspecialchars($magTable).'</code>' : '<i>nessuno</i>') . "\n";
                $reportMsg .= "💰 Prezzo: <b>€" . number_format($prezzo, 2) . "</b>\n";
                $reportMsg .= "📄 File: <code>" . htmlspecialchars($ext['filename']) . "</code> ({$sizeKb} KB)\n";
                $reportMsg .= "✉️ Email cliente: " . htmlspecialchars($clienteEmail ?: '—');
                if ($isTestRedirect) $reportMsg .= " 🧪 <i>redirect a " . htmlspecialchars(AI_EMAIL_TEST_OVERRIDE) . "</i>";
                $reportMsg .= "\n\n❓ <b>Procedo con l'invio?</b>\n• <b>SI</b> = invia\n• <b>NO</b> = tieni file senza inviare\n• <b>RIFAI</b> = ricomincio";
                TG::sendMessage($chatId, $reportMsg);
                TG::sendDocument($chatId, $ext['path'], "📋 Preview — " . htmlspecialchars($ext['filename']));
                self::saveState($chatId, $user, self::S_AWAIT_SEND, [
                    'intent' => $intent, 'cliente' => $cliente, 'magazzino_table' => $magTable,
                    'prezzo_eur' => $prezzo, 'ext' => $ext,
                    'actual_recipient' => $actualRecipient, 'is_test_redirect' => $isTestRedirect,
                ]);
                return;
            case self::S_POST:       self::stepPost($chatId, $user, $text, $data); return;
        }
        TG::sendMessage($chatId, "Stato inatteso: $state — uso /annulla per ricominciare.");
    }

    private static function stepCliente(int $chatId, array $user, string $text, array $data): void
    {
        $n = (int)trim($text);
        $candidates = $data['candidates'];
        if ($n < 1 || $n > count($candidates)) {
            TG::sendMessage($chatId, "Numero non valido. Scegli tra 1 e " . count($candidates) . " o /annulla.");
            return;
        }
        self::setCliente($chatId, $user, $data['intent'], $candidates[$n-1]);
    }

    /** Setta il cliente scelto e passa alla scelta magazzino (o la salta se c'è mappatura) */
    private static function setCliente(int $chatId, array $user, array $intent, array $cliente): void
    {
        $msg = "✅ Cliente: <b>" . htmlspecialchars($cliente['ragione_sociale'] ?: ($cliente['nome'].' '.$cliente['cognome'])) . "</b>\n";
        if ($cliente['partita_iva']) $msg .= "P.IVA: " . htmlspecialchars($cliente['partita_iva']) . "\n";
        if ($cliente['email'])       $msg .= "Email: " . htmlspecialchars($cliente['email']) . "\n";

        // 1) C'è una scelta persistente per questo cliente?
        $saved = EstraiEngine::getMagazzinoSalvato((int)$cliente['id']);
        if ($saved !== null) {
            $chosen = $saved['magazzino_tabella'] ?: null; // null = scelta "nessun magazzino"
            if ($chosen) {
                $msg .= "\n🗄 Magazzino memorizzato: <code>" . htmlspecialchars($chosen) . "</code> <i>(usato automaticamente)</i>\n";
                $msg .= "Se vuoi cambiarlo: /magazzino_reset";
            } else {
                $msg .= "\n🗄 Nessun magazzino (scelta memorizzata). Per cambiare: /magazzino_reset";
            }
            TG::sendMessage($chatId, $msg);
            // Salta direttamente alla richiesta prezzo
            self::askPrezzo($chatId, $user, $intent, $cliente, $chosen);
            return;
        }

        // 2) Nessuna scelta salvata — chiedi A/B/C
        $mag = EstraiEngine::findMagazzini($cliente);
        $msg .= "\n";

        if ($mag) {
            $msg .= "🗄 <b>Magazzini storici trovati</b> (più recenti in alto):\n";
            foreach ($mag as $i => $m) {
                $msg .= ($i+1) . ". <code>" . htmlspecialchars($m['table_name']) . "</code> · " . number_format((int)$m['table_rows']) . " record · creata " . substr($m['create_time'], 0, 10) . "\n";
            }
            $msg .= "\nScegli (la scelta verrà memorizzata per le prossime estrazioni di questo cliente):\n";
            $msg .= "<b>A</b> = usa magazzino <code>" . htmlspecialchars($mag[0]['table_name']) . "</code> (il più recente)\n";
            $msg .= "<b>B</b> = <i>nessun dedup</i> (estrazione cold)\n";
            $msg .= "<b>C</b> = altra tabella (scrivi il numero 1-" . count($mag) . ")\n";
        } else {
            $msg .= "🗄 Nessun magazzino storico trovato per questo cliente.\n";
            $msg .= "Rispondi <b>B</b> per procedere (cold, scelta memorizzata), o /annulla.";
        }

        TG::sendMessage($chatId, $msg);
        self::saveState($chatId, $user, self::S_MAGAZZINO, [
            'intent' => $intent, 'cliente' => $cliente, 'magazzini' => $mag,
        ]);
    }

    /** Detecta se il testo è un comando che deve interrompere il flusso corrente */
    private static function detectInterruptCommand(string $t): ?string
    {
        if (preg_match('/\b(rimuovi|togli|cambia|resetta|dimentica|riscegl|sostituisci|scegli\s+un\s+altro|modifica|disattiva|cancella)\s*(la\s+|il\s+|lo\s+)?(magazzin|dedup|anti[-\s]?join|deduplica)/iu', $t)) return 'magazzino';
        if (preg_match('/^(magazzin[io]|dedup|deduplica)\s+(cambia|togli|rimuovi|resetta|riscegli)/iu', $t)) return 'magazzino';
        if (preg_match('/\b(voglio\s+ris?cegl|non\s+voglio\s+(usare\s+)?(il\s+)?magazzin)/iu', $t)) return 'magazzino';

        if (preg_match('/\b(stat|statistica|statistiche|conteggio|disponibil|quanti\s+ne\s+abbiamo)\b/iu', $t)) return 'stat';
        if (preg_match('/\b(storic|ordin\s+(di|del)|cronolog|cosa\s+ha\s+(compr|acquist))/iu', $t)) return 'storico';
        if (preg_match('/\b(mostrami|elenca|fammi\s+vedere|recupera)\s+(la\s+|le\s+)?stat/iu', $t)) return 'list_stats';
        if (preg_match('/\b(vedi|mostrami|richiama)\s+stat\s+\d/iu', $t)) return 'view_stat';
        if (preg_match('/\b(spiegami|come\s+funziona|cosa\s+significa|a\s+cosa\s+serve|fammi\s+capire)\b/iu', $t)) return 'explain';
        if (preg_match('/^(aiuto|help|cosa\s+sai\s+fare|comandi)$/iu', $t)) return 'help';

        return null;
    }

    /** Quali campi obbligatori mancano in un intent di estrazione */
    private static function whichFieldsMissing(array $intent): array
    {
        $missing = [];
        if (empty($intent['cliente_hint'])) $missing[] = 'cliente';
        if (empty($intent['prodotto']))     $missing[] = 'prodotto';
        // Se ci sono sheets, la quantita outer può essere assente (ogni sheet ha la sua)
        $hasSheets = !empty($intent['sheets']) && is_array($intent['sheets']);
        if (!$hasSheets && empty($intent['quantita'])) $missing[] = 'quantita';
        $hasArea = !empty($intent['area']['valori']) || (($intent['area']['tipo'] ?? '') === 'nazionale');
        if (!$hasArea) $missing[] = 'area';
        return $missing;
    }

    /** Chiede all'utente il PRIMO campo mancante e mette in stato S_MISSING */
    private static function askMissing(int $chatId, array $user, array $intent, array $missing): void
    {
        $first = $missing[0];
        $prompts = [
            'cliente'  => "👤 Per quale <b>cliente</b>? Scrivi nome, ragione sociale o P.IVA.\n<i>Esempi: «Cerullo» · «Stile Acqua» · «04572140160»</i>",
            'prodotto' => "📦 Quale <b>prodotto</b>? Es. energia, fotovoltaico, depurazione, telefonia, cessione_quinto, finanziarie, email, generiche…",
            'quantita' => "🔢 Quanti <b>record</b> vuoi estrarre? Scrivi solo il numero, es. <code>2000</code>.",
            'area'     => "🗺 Per quale <b>area geografica</b>?\n<i>Esempi: «Lombardia» (regione) · «provincia di Milano» · «Napoli» (comune) · «tutta Italia» (nazionale)</i>",
        ];
        $prefix = count($missing) > 1
            ? "⚠️ Mi mancano " . count($missing) . " info (" . implode(', ', $missing) . "). Procedo una per volta.\n\n"
            : "";
        TG::sendMessage($chatId, $prefix . ($prompts[$first] ?? "Mi manca: $first"));
        self::saveState($chatId, $user, self::S_MISSING, [
            'intent' => $intent,
            'missing_field' => $first,
        ]);
    }

    /** L'utente ha risposto al prompt per un campo mancante: lo fondo nell'intent e riparto */
    private static function stepAwaitMissing(int $chatId, array $user, string $text, array $data): void
    {
        $intent = $data['intent'];
        $field  = $data['missing_field'];
        $t = trim($text);

        // Parsing leggero per ciascun tipo di campo
        switch ($field) {
            case 'quantita':
                $tl = strtolower(trim($t));
                if (in_array($tl, ['tutti','tutto','tutte','massimo','max','illimitato','senza limite','all'])) {
                    $intent['quantita']  = 500000;   // cap
                    $intent['qty_tutti'] = true;
                } else {
                    $clean = preg_replace('/[^\d]/', '', $t);
                    if (!ctype_digit($clean) || (int)$clean <= 0) {
                        TG::sendMessage($chatId, "Numero non valido. Scrivi un numero (es. 2000) oppure <b>tutti</b> per estrarre tutto il disponibile.");
                        return;
                    }
                    $intent['quantita'] = (int)$clean;
                }
                break;

            case 'prodotto':
                // Accetta slug o nome italiano
                $map = [
                    'energia'=>'energia','luce'=>'energia','gas'=>'energia',
                    'fotovoltaico'=>'fotovoltaico','pannelli'=>'fotovoltaico','solare'=>'fotovoltaico',
                    'depurazione'=>'depurazione','acqua'=>'depurazione','purificatore'=>'depurazione',
                    'telefonia'=>'telefonia','telefono'=>'telefonia','tel'=>'telefonia',
                    'cessione'=>'cessione_quinto','cessione_quinto'=>'cessione_quinto','cessione del quinto'=>'cessione_quinto',
                    'finanziarie'=>'finanziarie','finanziaria'=>'finanziarie','prestiti'=>'finanziarie',
                    'immobiliari'=>'immobiliari','casa'=>'immobiliari',
                    'alimentari'=>'alimentari','cibo'=>'alimentari',
                    'cosmetica'=>'cosmetica','cosmetici'=>'cosmetica',
                    'generiche'=>'generiche','generica'=>'generiche',
                    'email'=>'email','newsletter'=>'email','mail'=>'email','sky'=>'email',
                    'lead_voip'=>'lead_voip','voip'=>'lead_voip',
                    'gdpr'=>'gdpr',
                    'digital_mkt'=>'digital_mkt','digital marketing'=>'digital_mkt',
                ];
                $key = strtolower($t);
                $intent['prodotto'] = $map[$key] ?? $map[str_replace(' ', '_', $key)] ?? 'generiche';
                break;

            case 'cliente':
                $intent['cliente_hint'] = $t;
                break;

            case 'area':
                // Uso il parser Claude solo per l'area — robusto su "lombardia" vs "provincia di MI" vs "tutta italia"
                try {
                    $sub = EstraiParser::parse("area: " . $t, null);
                    if (!empty($sub['area']['valori']) || (($sub['area']['tipo'] ?? '') === 'nazionale')) {
                        $intent['area'] = $sub['area'];
                    } else {
                        // fallback: tratta come regione singola
                        $intent['area'] = ['tipo' => 'regione', 'valori' => [$t]];
                    }
                } catch (\Throwable $e) {
                    $intent['area'] = ['tipo' => 'regione', 'valori' => [$t]];
                }
                break;
        }

        // Re-validazione: se manca ancora qualcosa, chiedi il prossimo
        $stillMissing = self::whichFieldsMissing($intent);
        if ($stillMissing) {
            self::askMissing($chatId, $user, $intent, $stillMissing);
            return;
        }

        // Tutto OK → riprendo dal cliente resolution
        TG::sendMessage($chatId, "👍 Ho tutte le info, procedo.");
        self::clearState($chatId);
        self::resumeAfterMissing($chatId, $user, $intent);
    }

    /** Riavvia il flow completo dopo che abbiamo completato l'intent */
    private static function resumeAfterMissing(int $chatId, array $user, array $intent): void
    {
        $filters = self::buildClientFiltersFromIntent($intent);
        $candidates = EstraiEngine::findClienti($intent['cliente_hint'], $filters, 5);
        if (!$candidates) {
            TG::sendMessage($chatId,
                "❌ Cliente <b>\"" . htmlspecialchars($intent['cliente_hint']) . "\"</b> non trovato.\n"
              . "<i>Procedo con la creazione — scrivi «annulla» per fermare.</i>"
            );
            require_once __DIR__ . '/telegram_flow_new_client.php';
            FlowNewClient::start($chatId, $user, ['flow'=>'estrai', 'intent'=>$intent]);
            return;
        }
        if (count($candidates) === 1) {
            self::setCliente($chatId, $user, $intent, $candidates[0]);
            return;
        }
        $msg = "🔎 Ho trovato <b>" . count($candidates) . "</b> clienti possibili:\n\n";
        foreach ($candidates as $i => $c) {
            $msg .= ($i+1) . ". <b>" . htmlspecialchars($c['ragione_sociale'] ?: ($c['nome'].' '.$c['cognome'])) . "</b>";
            if ($c['partita_iva']) $msg .= " · " . htmlspecialchars($c['partita_iva']);
            if ($c['comune'])      $msg .= " · " . htmlspecialchars($c['comune']);
            $msg .= "\n";
        }
        $msg .= "\nRispondi col numero (1-" . count($candidates) . "), oppure /annulla.";
        TG::sendMessage($chatId, $msg);
        self::saveState($chatId, $user, self::S_CLIENTE, [
            'intent' => $intent, 'candidates' => $candidates,
        ]);
    }

    /** Detecta intent di interruzione — naturale o esplicito (ma /annulla è immediato, no confirm) */
    public static function isStopIntent(string $text): bool
    {
        $t = strtolower(trim($text));
        // Parole secche di stop
        if (preg_match('/^(basta|stop|fermati|ferma|interrompi|annullo|annullare|blocca|cancella|esci|quit|aborto|mi\s+fermo)$/iu', $t)) return true;
        // Frasi di stop
        if (preg_match('/\b(non\s+voglio\s+pi[uù]|lascia\s+(stare|perdere)|scordiamolo|scordatelo|scordalo|non\s+fa\s+niente|lasciamo\s+stare|chiudi\s+tutto|ferma\s+tutto|blocca\s+tutto|voglio\s+uscire)\b/iu', $t)) return true;
        return false;
    }

    /** Salva stato preservando flow name (usato da checkStopIntent per setflag senza cambiare flow) */
    public static function saveStateRaw(int $chatId, array $user, string $flow, string $state, array $data): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("REPLACE INTO tg_conversations (chat_id, user_id, flow, state, data) VALUES (?, ?, ?, ?, ?)")
            ->execute([$chatId, $user['id'], $flow, $state, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Universal stop handler — chiamato dalla cima di ogni handleReply in tutti i flussi.
     * Ritorna true se il messaggio è stato gestito (ask confirm / stop / resume).
     */
    public static function checkStopIntent(int $chatId, array $user, string $text, array $conv): bool
    {
        $data = $conv['data'] ?? [];
        $flow = $conv['flow'];
        $state = $conv['state'];
        $t = trim($text);

        // Già chiesto "sei sicuro?" → ora arriva la risposta
        if (!empty($data['stop_pending'])) {
            if (preg_match('/^(si|sì|sí|yes|y|ok|confermo|certo|sicuro)$/iu', $t)) {
                // Stop confermato
                $pdo = remoteDb('ai_laboratory');
                $pdo->prepare("DELETE FROM tg_conversations WHERE chat_id = ?")->execute([$chatId]);
                TG::sendMessage($chatId, "❎ OK, fermo tutto. Torniamo al menu.");
                self::mainMenu($chatId);
                return true;
            }
            if (preg_match('/^(no|non|continua|riprendi|prosegui|vai|avanti)$/iu', $t)) {
                unset($data['stop_pending']);
                self::saveStateRaw($chatId, $user, $flow, $state, $data);
                TG::sendMessage($chatId, "✓ Continuo da dove eravamo.");
                return true;
            }
            TG::sendMessage($chatId, "Rispondi <b>SI</b> per interrompere e tornare al menu, <b>NO</b> per continuare.");
            return true;
        }

        // Detect nuovo stop intent
        if (self::isStopIntent($text)) {
            $data['stop_pending'] = true;
            self::saveStateRaw($chatId, $user, $flow, $state, $data);
            TG::sendMessage($chatId, "⏸ <b>Vuoi interrompere l'attività corrente?</b>\n\nRispondi <b>SI</b> per fermare e tornare al menu, <b>NO</b> per continuare.");
            return true;
        }

        return false;
    }

    /** Controlla se l'utente ha l'auto-menu abilitato */
    public static function isMenuEnabled(int $chatId): bool
    {
        try {
            $pdo = remoteDb('backoffice');
            $s = $pdo->prepare("SELECT tg_menu_enabled FROM users WHERE telegram_chat_id = ? LIMIT 1");
            $s->execute([$chatId]);
            $val = $s->fetchColumn();
            return $val === false ? true : (bool)$val;
        } catch (\Throwable $e) { return true; }
    }

    public static function setMenuEnabled(int $chatId, bool $enabled): void
    {
        $pdo = remoteDb('backoffice');
        $pdo->prepare("UPDATE users SET tg_menu_enabled = ? WHERE telegram_chat_id = ?")
            ->execute([$enabled ? 1 : 0, $chatId]);
    }

    /** Menu "cosa vuoi fare?" — chiamato alla fine di ogni azione (skippato se disabilitato) */
    public static function mainMenu(int $chatId, string $intro = "💬 <b>Cosa vuoi fare ora?</b>"): void
    {
        if (!self::isMenuEnabled($chatId)) return;
        $msg  = $intro . "\n\n";
        $msg .= "📥 <b>Nuova estrazione</b>\n<i>es. «estrai 2000 depurazione Cerullo Milano»</i>\n\n";
        $msg .= "📊 <b>Statistica disponibilità</b>\n<i>es. «stat lombardia per provincia per Cerullo»</i>\n\n";
        $msg .= "📋 <b>Storico cliente (ordini + consegne)</b>\n<i>es. «fammi vedere cosa ha acquistato Ediwater»</i>\n\n";
        $msg .= "💾 <b>Stat salvate</b>\n<i>es. «mostrami le stat di ieri» · «stat della settimana» · «stat salvate di Cerullo»</i>\n\n";
        $msg .= "♻️ <b>Richiama una stat per ID</b>\n<i>es. «mostrami stat 7»</i>\n\n";
        $msg .= "🔁 <b>Ripeti l'ultima spedizione</b>\n<i>es. «altri 100» (solo se siamo in post-consegna)</i>\n\n";
        $msg .= "🔧 <b>Comandi tecnici</b>\n<i>/chi /utenti /magazzini /magazzino_reset /annulla</i>\n\n";
        $msg .= "❓ <b>Spiegazione dettagliata di un comando</b>\n<i>es. «spiegami le stat salvate» · «cosa significa ripeti ultima spedizione» · «come funziona il magazzino»</i>\n\n";
        $msg .= "🔄 <b>Ricomincia da capo</b>\n<i>es. «ho sbagliato» · «rifacciamo» · «ricomincia» · «/annulla»</i>\n\n";
        $msg .= "👋 Per chiudere scrivi <b>ok / grazie / basta</b>.";
        TG::sendMessage($chatId, $msg);
    }

    /** Helper: chiede il prezzo partendo direttamente da cliente+magazzino noti */
    private static function askPrezzo(int $chatId, array $user, array $intent, array $cliente, ?string $magTable): void
    {
        // Se qty è "tutti" (cap 500K o flag) → salta prezzo upfront, lo chiedo dopo estrazione
        $isQtyTutti = !empty($intent['qty_tutti']) || ((int)($intent['quantita'] ?? 0) >= 500000);
        if ($isQtyTutti) {
            TG::sendMessage($chatId, "🔢 Estraggo tutto il disponibile — ti chiedo il prezzo dopo che so il conteggio esatto.");
            $data = [
                'intent' => $intent, 'cliente' => $cliente, 'magazzino_table' => $magTable,
                'prezzo_eur' => 0, 'ask_price_post' => true,
            ];
            // Vai direttamente a confirm (skip state prezzo)
            $msg = "📋 <b>Riepilogo</b>\n\nCliente: <b>" . htmlspecialchars($cliente['ragione_sociale'] ?: ($cliente['nome'].' '.$cliente['cognome'])) . "</b>\n";
            $msg .= "Categoria: " . htmlspecialchars($intent['prodotto']) . "\n";
            $msg .= "Quantità: <b>tutti i disponibili</b> (il prezzo lo chiedo dopo)\n";
            $msg .= "Area: " . ($intent['area']['tipo'] ?? '-') . " · " . implode(', ', $intent['area']['valori'] ?? []) . "\n";
            $msg .= "Magazzino: " . ($magTable ? '<code>'.htmlspecialchars($magTable).'</code>' : '<i>nessuno</i>') . "\n\n";
            $msg .= "Confermi? Rispondi <b>SI</b> per estrarre, altro per annullare.";
            TG::sendMessage($chatId, $msg);
            self::saveState($chatId, $user, self::S_CONFIRM, $data);
            return;
        }

        TG::sendMessage($chatId,
            "💰 Qual è il <b>prezzo di vendita</b> in euro? (scrivi il numero — es. <code>0</code>, <code>120</code>, <code>59.50</code>)"
        );
        self::saveState($chatId, $user, self::S_PREZZO, [
            'intent' => $intent, 'cliente' => $cliente, 'magazzino_table' => $magTable,
        ]);
    }

    private static function stepMagazzino(int $chatId, array $user, string $text, array $data): void
    {
        $mag  = $data['magazzini'];
        $ans  = strtoupper(trim($text));
        $chosenTable = null;

        if ($ans === 'A' && $mag)              { $chosenTable = $mag[0]['table_name']; }
        elseif ($ans === 'B')                   { $chosenTable = null; }
        elseif ($ans === 'C' || ctype_digit($ans)) {
            // se C o numero, chiedi/interpreta numero
            if (ctype_digit($ans)) {
                $n = (int)$ans;
                if ($n >= 1 && $n <= count($mag)) $chosenTable = $mag[$n-1]['table_name'];
            } else {
                TG::sendMessage($chatId, "Ok, scrivi il numero della tabella da usare (1-" . count($mag) . ").");
                return;
            }
        } else {
            TG::sendMessage($chatId, "Scegli A / B / C o numero tabella (o /annulla).");
            return;
        }

        // Salva scelta permanente
        $clienteId = (int)$data['cliente']['id'];
        EstraiEngine::setMagazzinoSalvato($clienteId, $chosenTable, (int)$user['id']);

        $data['magazzino_table'] = $chosenTable;
        $msg = $chosenTable
            ? "✅ Magazzino selezionato: <code>" . htmlspecialchars($chosenTable) . "</code>.\n💾 <i>Scelta memorizzata — non chiederò più per questo cliente.</i>\n\n"
            : "✅ Nessun magazzino.\n💾 <i>Scelta memorizzata — non chiederò più per questo cliente.</i>\n\n";
        TG::sendMessage($chatId, $msg);

        // Se qty è "tutti" → salta prezzo upfront, va a riepilogo+conferma estrazione
        $intent = $data['intent'];
        $isQtyTutti = !empty($intent['qty_tutti']) || ((int)($intent['quantita'] ?? 0) >= 500000);
        if ($isQtyTutti) {
            $cliente = $data['cliente'];
            $data['prezzo_eur']    = 0;
            $data['ask_price_post'] = true;
            $m = "🔢 Estraggo tutto il disponibile — ti chiedo il prezzo dopo che so il conteggio esatto.\n\n";
            $m .= "📋 <b>Riepilogo</b>\n\nCliente: <b>" . htmlspecialchars($cliente['ragione_sociale'] ?: ($cliente['nome'].' '.$cliente['cognome'])) . "</b>\n";
            $m .= "Categoria: " . htmlspecialchars($intent['prodotto']) . "\n";
            $m .= "Quantità: <b>tutti i disponibili</b> (il prezzo lo chiedo dopo)\n";
            $m .= "Area: " . ($intent['area']['tipo'] ?? '-') . " · " . implode(', ', $intent['area']['valori'] ?? []) . "\n";
            $m .= "Magazzino: " . ($chosenTable ? '<code>'.htmlspecialchars($chosenTable).'</code>' : '<i>nessuno</i>') . "\n\n";
            $m .= "Confermi? Rispondi <b>SI</b> per estrarre, altro per annullare.";
            TG::sendMessage($chatId, $m);
            self::saveState($chatId, $user, self::S_CONFIRM, $data);
            return;
        }

        TG::sendMessage($chatId, "💰 Qual è il <b>prezzo di vendita</b> in euro? (scrivi il numero — es. <code>0</code>, <code>120</code>, <code>59.50</code>)");
        self::saveState($chatId, $user, self::S_PREZZO, $data);
    }

    private static function stepPrezzo(int $chatId, array $user, string $text, array $data): void
    {
        // Estrai il primo numero dal testo (accetta frasi tipo "1000 euro per tutte e due")
        $norm = str_replace(',', '.', trim($text));
        if (!preg_match('/(\d+(?:\.\d+)?)/', $norm, $m)) {
            TG::sendMessage($chatId, "Prezzo non valido. Scrivi un numero (es. 0, 120, 59.50) — anche una frase con un numero va bene.");
            return;
        }
        $data['prezzo_eur'] = (float)$m[1];

        // Build summary
        $intent    = $data['intent'];
        $cliente   = $data['cliente'];
        $magTable  = $data['magazzino_table'];
        $source    = EstraiEngine::pickSource($intent['prodotto']);

        $msg  = "📋 <b>Riepilogo</b>\n\n";
        $msg .= "Cliente: <b>" . htmlspecialchars($cliente['ragione_sociale'] ?: ($cliente['nome'].' '.$cliente['cognome'])) . "</b>\n";
        $msg .= "Categoria: " . htmlspecialchars($intent['prodotto']) . "\n";

        if (!empty($intent['sheets']) && is_array($intent['sheets'])) {
            $totQ = array_sum(array_column($intent['sheets'], 'quantita'));
            $msg .= "Quantità totale: <b>" . number_format($totQ) . "</b> in " . count($intent['sheets']) . " fogli\n";
            foreach ($intent['sheets'] as $i => $s) {
                $msg .= "  " . ($i+1) . ". " . htmlspecialchars($s['label'] ?? ('Foglio ' . ($i+1))) . " → " . number_format((int)($s['quantita'] ?? 0)) . " record\n";
            }
        } else {
            $msg .= "Quantità: <b>" . number_format((int)($intent['quantita'] ?? 0)) . "</b>\n";
        }

        $areaStr = implode(', ', $intent['area']['valori'] ?? []);
        $areaTipo = $intent['area']['tipo'] ?? '-';
        $msg .= "Area: " . $areaTipo . ($areaStr ? ' · ' . $areaStr : '') . "\n";
        if (!empty($intent['filtri']['no_stranieri'])) $msg .= "No stranieri: sì\n";
        if (!empty($intent['filtri']['data_att_mese_anno'])) $msg .= "Data attivazione (mesi): " . implode(', ', $intent['filtri']['data_att_mese_anno']) . "\n";
        if (!empty($intent['filtri']['data_att_max_anno_mese'])) $msg .= "Attivazione fino a: " . $intent['filtri']['data_att_max_anno_mese'] . " (a ritroso)\n";
        if (!empty($intent['filtri']['data_att_min_anno_mese'])) $msg .= "Attivazione da: " . $intent['filtri']['data_att_min_anno_mese'] . " (in poi)\n";
        $etaMin = $intent['filtri']['eta_min'] ?? null;
        $etaMax = $intent['filtri']['eta_max'] ?? null;
        if ($etaMin !== null || $etaMax !== null) {
            $msg .= "Età: <b>" . ($etaMin ?? '?') . "-" . ($etaMax ?? '?') . " anni</b> (da CF)\n";
        }
        if (!empty($intent['filtri']['with_extra_numbers'])) {
            $msg .= "Numeri extra: <b>sì</b> (colonne Tel_Extra_* da master_cf_numeri)\n";
        }
        $magazzinoDisplay = $magTable ? '<code>'.htmlspecialchars($magTable).'</code>' : '<i>nessuno</i>';
        if ($magTable && defined('AI_MAGAZZINO_SKIP_INSERT') && AI_MAGAZZINO_SKIP_INSERT) {
            $magazzinoDisplay .= ' 🧪 <i>(solo dedup, nessun insert post-delivery)</i>';
        }
        $msg .= "Magazzino: " . $magazzinoDisplay . "\n";
        $msg .= "Prezzo: <b>€" . number_format($data['prezzo_eur'], 2) . "</b>\n";
        $msg .= "Fonte DB: <code>" . $source['db'] . "." . $source['table'] . "</code>\n";
        $msg .= "\n👉 Email cliente (anagrafica): <b>" . htmlspecialchars($cliente['email'] ?: 'NON PRESENTE') . "</b>\n";
        if (defined('AI_EMAIL_TEST_OVERRIDE') && AI_EMAIL_TEST_OVERRIDE) {
            $msg .= "🧪 <i>TEST MODE attivo — invio verrà redirezionato a <b>" . htmlspecialchars(AI_EMAIL_TEST_OVERRIDE) . "</b></i>\n";
        }
        $msg .= "\nConfermi? Rispondi <b>SI</b> per eseguire, altro per annullare.";

        TG::sendMessage($chatId, $msg);
        self::saveState($chatId, $user, self::S_CONFIRM, $data);
    }

    private static function stepConfirm(int $chatId, array $user, string $text, array $data): void
    {
        // Regex ampio per conferma (accetta varianti comuni)
        if (!preg_match('/\b(si|sì|sí|yes|y|ok|okay|confermo|conferma|procedi|vai|avanti|dai|ye[sh]*)\b/iu', trim($text))) {
            self::clearState($chatId);
            TG::sendMessage($chatId, "❎ Estrazione annullata (non hai confermato).");
            return;
        }

        $intent   = $data['intent'];
        $cliente  = $data['cliente'];
        $magTable = $data['magazzino_table'];
        $source   = EstraiEngine::pickSource($intent['prodotto']);
        $prezzo   = (float)$data['prezzo_eur'];

        TG::sendMessage($chatId, "⏳ Estrazione in corso (niente email, solo il file)...");
        TG::sendChatAction($chatId, 'upload_document');

        try {
            // 1. Estrai + xlsx (NO email, NO magazzino update qui)
            $ext = EstraiEngine::estrai($intent, $cliente, $source, $magTable);
            if ($ext['count'] === 0) {
                self::clearState($chatId);
                TG::sendMessage($chatId, "❌ Nessun record trovato con i filtri indicati. Prova ad ampliare l'area o rimuovere il magazzino.");
                return;
            }

            // 2. Salva dati estrazione nello stato — NON aggiorno ancora il magazzino, NON invio email
            $sizeKb = round(filesize($ext['path']) / 1024, 1);
            $clienteEmail = $cliente['email'];
            $isTestRedirect = (defined('AI_EMAIL_TEST_OVERRIDE') && AI_EMAIL_TEST_OVERRIDE);
            $actualRecipient = $isTestRedirect ? AI_EMAIL_TEST_OVERRIDE : $clienteEmail;

            // 2b. Se qty era "tutti", chiedo ora il prezzo prima di mostrare il report finale
            if (!empty($data['ask_price_post'])) {
                TG::sendMessage($chatId,
                    "✅ Estratti <b>" . number_format($ext['count']) . "</b> record su " . $ext['comuni'] . " comuni.\n\n"
                  . "💰 A che prezzo vuoi vendere questi " . number_format($ext['count']) . " record? Scrivi il numero (€ totali per la lista, es. <code>1500</code>, oppure <code>0</code> se omaggio)."
                );
                self::saveState($chatId, $user, 'await_price_post_ext', [
                    'intent' => $intent, 'cliente' => $cliente, 'magazzino_table' => $magTable,
                    'ext' => $ext, 'actual_recipient' => $actualRecipient, 'is_test_redirect' => $isTestRedirect,
                ]);
                return;
            }

            // 3. Mostra REPORT (pre-invio) — include breakdown per sheet se multi-sheet
            $nomeClnt = $cliente['ragione_sociale'] ?: ($cliente['nome'].' '.$cliente['cognome']);
            $reportMsg  = "✅ <b>Estrazione completata — pronta da inviare</b>\n\n";
            $reportMsg .= "👤 Cliente: <b>" . htmlspecialchars($nomeClnt) . "</b>\n";
            $reportMsg .= "📦 Categoria: <b>" . htmlspecialchars($intent['prodotto']) . "</b>\n";
            $reportMsg .= "🗺 Area: <b>" . htmlspecialchars(implode(', ', $intent['area']['valori'] ?? [])) . "</b>\n";

            // Breakdown sheet
            $isMulti = count($ext['sheets']) > 1;
            $totalReq = array_sum(array_column($ext['sheets'], 'requested'));
            $reportMsg .= "📊 Record estratti: <b>" . $ext['count'] . "</b> (richiesti " . number_format($totalReq) . ")\n";
            if ($isMulti) {
                $reportMsg .= "\n📑 <b>" . count($ext['sheets']) . " fogli nell'xlsx</b>:\n";
                foreach ($ext['sheets'] as $s) {
                    $status = $s['extracted'] >= $s['requested'] ? '✓' : '⚠️';
                    $reportMsg .= sprintf("  %s <b>%s</b> → %s su %s richiesti\n",
                        $status, htmlspecialchars($s['label']),
                        number_format($s['extracted']), number_format($s['requested']));
                }
                $reportMsg .= "\n";
            }

            // Verifica insufficient
            $insufficient = [];
            foreach ($ext['sheets'] as $s) {
                if ($s['extracted'] < $s['requested']) $insufficient[] = $s;
            }

            $reportMsg .= "🏘 Comuni distinti: " . $ext['comuni'] . "\n";
            $reportMsg .= "🗄 Magazzino anti-join: " . ($magTable ? '<code>'.htmlspecialchars($magTable).'</code>' : '<i>nessuno</i>') . "\n";
            $reportMsg .= "💰 Prezzo: €" . number_format($prezzo, 2) . "\n";
            $reportMsg .= "📄 File: <code>" . htmlspecialchars($ext['filename']) . "</code> ({$sizeKb} KB)\n";
            $reportMsg .= "✉️ Email cliente (anagrafica): " . htmlspecialchars($clienteEmail ?: '—') . "\n";
            if ($isTestRedirect) {
                $reportMsg .= "🧪 <i>TEST MODE: email verrà redirezionata a " . htmlspecialchars(AI_EMAIL_TEST_OVERRIDE) . "</i>\n";
            }

            // Distingui fogli VUOTI (0 record) da fogli PARZIALI (<richiesti ma >0)
            $empty = []; $partial = [];
            foreach ($insufficient as $s) {
                if ($s['extracted'] === 0) $empty[] = $s;
                else $partial[] = $s;
            }

            if ($empty) {
                $reportMsg .= "\n🚨 <b>" . count($empty) . " foglio/i completamente VUOTI</b>:\n";
                foreach ($empty as $e) $reportMsg .= "  • <b>" . htmlspecialchars($e['label']) . "</b> → 0 record (richiesti " . number_format($e['requested']) . ")\n";
                $reportMsg .= "\nProbabili cause: filtri troppo stretti, fonte senza dati per quel periodo/area, o richiesta non allineata col DB.\n";
                $reportMsg .= "\nOpzioni:\n";
                $reportMsg .= "• <b>STAT</b> = lancia una stat sui filtri di quel foglio per capire cosa c'è (consigliato)\n";
                $reportMsg .= "• <b>RIFAI</b> = ricomincio, riformuli (allarga area/date/rimuovi filtri)\n";
                $reportMsg .= "• <b>SI</b> = invio lo stesso (anche fogli vuoti)\n";
                $reportMsg .= "• <b>NO</b> = scarto, niente invio\n";
            } elseif ($partial) {
                $reportMsg .= "\n⚠️ <b>Attenzione</b>: " . count($partial) . " foglio/specifica sotto la quantità richiesta (ma non vuoti).\n";
                $reportMsg .= "Opzioni:\n";
                $reportMsg .= "• <b>SI</b> = invio lo stesso (quantità parziali)\n";
                $reportMsg .= "• <b>RIFAI</b> o <b>RIFORMULA</b> = ricomincio, modifichi la richiesta\n";
                $reportMsg .= "• <b>STAT</b> = lancia una stat per capire i volumi disponibili\n";
                $reportMsg .= "• <b>NO</b> = NON invio, tengo il file ma niente email/magazzino\n";
            } else {
                $reportMsg .= "\n❓ <b>Procedo con l'invio email al cliente + team e registrazione spedizione?</b>\n";
                $reportMsg .= "• <b>SI</b> = invia\n";
                $reportMsg .= "• <b>NO</b> = file resta senza invio\n";
                $reportMsg .= "• <b>RIFAI</b> = ricomincio da zero";
            }
            TG::sendMessage($chatId, $reportMsg);

            // 4. Invia preview xlsx direttamente su Telegram così lo puoi controllare
            TG::sendDocument($chatId, $ext['path'], "📋 Preview — " . htmlspecialchars($ext['filename']));

            // 5. Transizione a S_AWAIT_SEND
            self::saveState($chatId, $user, self::S_AWAIT_SEND, [
                'intent' => $intent,
                'cliente' => $cliente,
                'magazzino_table' => $magTable,
                'prezzo_eur' => $prezzo,
                'ext' => $ext,
                'actual_recipient' => $actualRecipient,
                'is_test_redirect' => $isTestRedirect,
            ]);
            return;

        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
            TG::sendMessage($chatId, "❌ <b>Errore durante estrazione</b>\n<code>" . htmlspecialchars($errMsg) . "</code>");
            error_log("FlowEstrai estrazione error: " . $errMsg);
            self::clearState($chatId);
            self::mainMenu($chatId);
            return;
        }
    }

    /** DOPO estrazione: utente conferma invio email */
    private static function stepAwaitSend(int $chatId, array $user, string $text, array $data): void
    {
        $t = strtolower(trim($text));

        // Riformulazione → ricomincia da capo
        if (preg_match('/^(rifai|riformula|ho sbagliato|ricomincia|torno indietro|da capo|rifacciamo)\b/iu', $t)) {
            self::clearState($chatId);
            TG::sendMessage($chatId, "🔄 OK, ricominciamo. Riscrivi la richiesta (puoi cambiare area, filtri, quantità, ecc.).");
            self::mainMenu($chatId);
            return;
        }

        // STAT = lancia una stat sui filtri dei fogli vuoti/parziali per capire i volumi
        if (preg_match('/^(stat|statistica|vediamo|controlla)\b/iu', $t)) {
            self::clearState($chatId);
            $intent = $data['intent'];
            $cliente = $data['cliente'];
            // Converti in intent stat
            $statIntent = $intent;
            $statIntent['action'] = 'stat';
            unset($statIntent['sheets'], $statIntent['quantita']);
            $statIntent['group_by'] = $statIntent['group_by'] ?? 'provincia';
            TG::sendMessage($chatId, "📊 Lancio una stat sui filtri della richiesta per capire cosa c'è...");
            FlowStats::run($chatId, $user, $statIntent);
            return;
        }

        if (preg_match('/^(no|non|annull|scart|negativ|stop|nulla)\b/iu', $t)) {
            self::clearState($chatId);
            TG::sendMessage($chatId,
                "❎ OK, NON invio nessuna email.\n"
              . "File estratto <code>" . htmlspecialchars($data['ext']['filename']) . "</code> resta sul server, ma niente magazzino aggiornato né spedizione registrata."
            );
            self::mainMenu($chatId);
            return;
        }

        if (!preg_match('/^(si|sì|sí|yes|y|ok|okay|confermo|conferma|invia|manda|procedi|vai|avanti|dai)\b/iu', $t)) {
            TG::sendMessage($chatId, "Rispondi <b>SI</b> per inviare, <b>NO</b> per tenere il file senza inviare, <b>RIFAI</b> per ricominciare.");
            return;
        }

        // === INVIO E REGISTRAZIONE ===
        $intent   = $data['intent'];
        $cliente  = $data['cliente'];
        $magTable = $data['magazzino_table'];
        $ext      = $data['ext'];
        $prezzo   = (float)$data['prezzo_eur'];
        $source   = EstraiEngine::pickSource($intent['prodotto']);
        $actualRecipient = $data['actual_recipient'];
        $isTestRedirect  = $data['is_test_redirect'];

        TG::sendMessage($chatId, "📤 Invio in corso...");
        TG::sendChatAction($chatId, 'typing');

        try {
            // 1. Insert magazzino (se scelto) — skip in TEST MODE
            $magInfo = null; $magSkipped = false;
            if ($magTable) {
                if (defined('AI_MAGAZZINO_SKIP_INSERT') && AI_MAGAZZINO_SKIP_INSERT) {
                    $magSkipped = true;
                } else {
                    $magInfo = EstraiEngine::insertMagazzino($magTable, $ext['mobiles']);
                }
            }

            // 3. Report interno + email cliente
            $clienteEmail = $cliente['email'];
            $contact      = trim(($cliente['nome'] ?? '') . ' ' . ($cliente['cognome'] ?? ''));
            if (!$contact) $contact = 'Gentile cliente';
            $area         = implode(', ', $intent['area']['valori'] ?? []);
            $report = [
                'cliente'     => $cliente['ragione_sociale'] ?: $contact,
                'cliente_id'  => $cliente['id'],
                'contatto'    => $contact,
                'piva'        => $cliente['partita_iva'] ?? '',
                'prodotto'    => $intent['prodotto'],
                'area'        => ucfirst($intent['area']['tipo'] ?? '') . ' ' . $area,
                'fonte_db'    => $source['db'] . '.' . $source['table'],
                'filtri'      => self::filtriToString($intent),
                'records'     => $ext['count'],
                'comuni'      => $ext['comuni'] . ' distinti',
                'magazzino'   => $magTable ?: 'nessuno',
                'dedup'       => $magTable ? 'anti-join pre-estrazione' : 'nessun dedup',
                'insert_info' => $magInfo
                    ? ($magInfo['inserted'] . ' mobile inseriti · data_lotto ' . $magInfo['data_lotto'] . ' · moo ' . $magInfo['moo_from'] . ' → ' . $magInfo['moo_to'])
                    : ($magSkipped ? '🧪 SKIP (test mode attivo)' : '—'),
                'send_to'     => ($clienteEmail ?: 'missing') . ' (' . $contact . ')',
                'prezzo_eur'  => $prezzo,
                'filename'    => $ext['filename'],
            ];

            // 4. Email interna (team)
            $teamEmails = self::adminEmails();
            foreach ($teamEmails as $te) aiSendInternalReport($te, 'Team', $report, $ext['path']);

            // 5. Email cliente (override già calcolato in stepConfirm → $data)
            $clientEmailOk = false;
            if ($actualRecipient) {
                $r = aiSendListDelivery($actualRecipient, $contact, [
                    'cliente'  => $report['cliente'],
                    'contatto' => $cliente['nome'] ?? $contact,
                    'prodotto' => $intent['prodotto'],
                    'area'     => $report['area'],
                    'records'  => $ext['count'],
                ], $ext['path']);
                $clientEmailOk = $r['success'];
            }

            // 6. Log delivery (include intent JSON completo per replay futuro)
            $deliveryId = EstraiEngine::logDelivery([
                'cliente_id'    => $cliente['id'],
                'cliente_nome'  => $report['cliente'],
                'cliente_email' => $clienteEmail,
                'prodotto'      => $intent['prodotto'],
                'query_ricerca' => trim(($data['intent_raw'] ?? '') ?: ($intent['cliente_hint'] . ' / ' . $intent['prodotto'] . ' / ' . $intent['quantita'] . ' / ' . $area)),
                'area'          => $report['area'],
                'fonte_db'      => $report['fonte_db'],
                'filtri'        => $report['filtri'],
                'records'       => $ext['count'],
                'magazzino'     => $magTable,
                'file_path'     => $ext['path'],
                'file_name'     => $ext['filename'],
                'prezzo_eur'    => $prezzo,
                'note'          => $magInfo ? ('Magazzino update: ' . $report['insert_info']) : null,
                'intent'        => $intent,
            ]);

            // 7. Report di successo — SEMPRE messaggio testo esplicito
            $emailLine = $clientEmailOk
                ? ("✓ inviata a <code>" . htmlspecialchars($actualRecipient) . "</code>" . ($isTestRedirect ? " 🧪 <i>redirect test, anagrafica: " . htmlspecialchars($clienteEmail ?: '—') . "</i>" : ""))
                : ($clienteEmail ? "✗ NON inviata (tentativo fallito)" : "⏭ non inviata (cliente senza email)");
            $magLine = $magInfo
                ? ("+" . $magInfo['inserted'] . " mobile aggiornati · data_lotto " . $magInfo['data_lotto'])
                : ($magSkipped ? "🧪 non aggiornato (test mode)" : "nessun magazzino");
            $sizeKb = round(filesize($ext['path']) / 1024, 1);

            $summary  = "✅ <b>Spedizione completata — #$deliveryId</b>\n\n";
            $summary .= "📄 File: <code>" . htmlspecialchars($ext['filename']) . "</code> ({$sizeKb} KB)\n";
            $summary .= "👤 Cliente: <b>" . htmlspecialchars($report['cliente']) . "</b>\n";
            $summary .= "📊 Record: <b>" . $ext['count'] . "</b> · Comuni: " . $ext['comuni'] . " · Prezzo: €" . number_format($prezzo, 2) . "\n";
            $summary .= "✉️ Email cliente: " . $emailLine . "\n";
            $summary .= "📨 Email team: ✓ inviata a " . count($teamEmails) . " admin\n";
            $summary .= "🗄 Magazzino: " . $magLine . "\n";
            $summary .= "💾 Registro: <code>ai_laboratory.deliveries #$deliveryId</code>\n\n";
            $summary .= "<i>Se hai note o problemi, scrivimeli ora — li allego alla spedizione.\nOppure inizia una nuova richiesta (<code>/annulla</code> per chiudere).</i>";

            TG::sendMessage($chatId, $summary);
            // (niente sendDocument qui — già inviato in stepConfirm come preview)
            self::mainMenu($chatId, "💬 <b>Spedizione completata. Cosa vuoi fare ora?</b>");

            // 9. Passa a stato post-delivery per ascoltare note + ricordare contesto
            self::saveState($chatId, $user, self::S_POST, [
                'delivery_id'   => $deliveryId,
                'cliente_nome'  => $report['cliente'],
                'records'       => $ext['count'],
                // Contesto completo per richieste successive ("altri 100", "stessa cosa per Roma", ecc.)
                'last_intent'   => $intent,
                'last_cliente'  => $cliente,
                'last_prezzo'   => $prezzo,
                'last_magazzino'=> $magTable,
            ]);

        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
            TG::sendMessage($chatId,
                "❌ <b>Errore durante l'esecuzione</b>\n\n"
              . "Dettaglio: <code>" . htmlspecialchars($errMsg) . "</code>\n\n"
              . "File: " . (!empty($ext['path']) && is_file($ext['path']) ? "✓ generato" : "✗ non generato") . "\n"
              . "Email cliente: " . (!empty($clientEmailOk) ? "✓ inviata" : "✗ non inviata") . "\n"
              . "Spedizione registrata: " . (!empty($deliveryId) ? "#$deliveryId" : "no") . "\n\n"
              . "Dimmi pure se vuoi che riprovi o indaghi."
            );
            error_log("FlowEstrai execute error: " . $errMsg . "\n" . $e->getTraceAsString());
            self::clearState($chatId);
            self::mainMenu($chatId);
        }
    }

    /** Stato post-consegna: ascolta feedback/nota e aggiorna la delivery */
    private static function stepPost(int $chatId, array $user, string $text, array $data): void
    {
        $t = strtolower(trim($text));

        // Segnali di chiusura
        if (preg_match('/^(ok|grazie|perfetto|bene|thanks|thx|ottimo|👍|ricevuto|tutto ok|basta|ciao|saluti)$/u', $t)) {
            TG::sendMessage($chatId, "🙌 Grazie, chiudo la spedizione <b>#" . $data['delivery_id'] . "</b>. Alla prossima!");
            self::clearState($chatId);
            self::mainMenu($chatId, "💬 <b>Pronto per un'altra richiesta:</b>");
            return;
        }

        // Comandi diversi (non-estrazione) che devono uscire dal post-delivery
        $isOtherCommand = preg_match('/\b(cambia|togli|rimuovi|resetta|dimentica|modifica|sostituisci|aggiorna|scegli)\s+(magazzino|dedup|il\s+magazzino)\b/iu', $t)
                       || preg_match('/\b(magazzin[io])\b/iu', $t)
                       || preg_match('/\b(stat|statistica|statistiche|conteggio|disponibil)\b/iu', $t)
                       || preg_match('/\b(storic|ordin|acquist|cronolog|cosa\s+ha\s+compr)\b/iu', $t)
                       || preg_match('/\b(mostrami|elenca|fammi\s+vedere|vedi|recupera|richiama)\b/iu', $t)
                       || preg_match('/\b(spiegami|come\s+funziona|cosa\s+significa|a\s+cosa\s+serve|fammi\s+capire)\b/iu', $t)
                       || preg_match('/\b(aiuto|help|cosa\s+sai\s+fare|comandi)\b/iu', $t);

        // Nuova richiesta di estrazione?
        $isFollowUp = preg_match('/\b(altri|ancora|stessa|medesim|uguale|bis|di nuovo|analog)\b/iu', $t) && preg_match('/\d/', $t);
        $isNewReq   = preg_match('/\b(estra|voglio|dammi|servon|mandami|prepara|fammi)\b/iu', $t) && preg_match('/\d{2,}/', $t);

        if ($isOtherCommand) {
            // Non è una nota, è un nuovo comando → chiudo post-delivery e passo a Claude
            // Passa il contesto cliente/prodotto della delivery appena fatta, così
            // "cambia magazzino" senza nome si risolve sul cliente di prima
            $prevCtx = [
                'cliente'       => $data['last_cliente']     ?? null,
                'cliente_hint'  => $data['last_intent']['cliente_hint'] ?? ($data['cliente_nome'] ?? null),
                'prodotto'      => $data['last_intent']['prodotto']     ?? null,
                'area'          => $data['last_intent']['area']         ?? null,
            ];
            self::clearState($chatId);
            self::start($chatId, $user, $text, $prevCtx);
            return;
        }

        if ($isFollowUp || $isNewReq || (preg_match('/^\d{2,}/', $t) && strlen($t) < 100)) {
            // Costruisci il contesto da passare al parser
            $prevCtx = [
                'cliente'       => $data['last_cliente'] ?? null,
                'cliente_hint'  => $data['last_intent']['cliente_hint'] ?? null,
                'prodotto'      => $data['last_intent']['prodotto'] ?? null,
                'quantita'      => $data['last_intent']['quantita'] ?? null,
                'area'          => $data['last_intent']['area'] ?? null,
                'filtri'        => $data['last_intent']['filtri'] ?? null,
            ];
            TG::sendMessage($chatId, "🔄 Chiudo spedizione #" . $data['delivery_id'] . " e apro una nuova richiesta <i>(ricordo il contesto precedente).</i>");
            self::clearState($chatId);
            self::start($chatId, $user, $text, $prevCtx);
            return;
        }

        // Altrimenti: è una nota → la aggiungo alla delivery
        try {
            $pdo = remoteDb('ai_laboratory');
            // Append alla note (preservando quello che c'era)
            $stmt = $pdo->prepare("UPDATE deliveries SET note = CONCAT_WS(' | ', note, ?) WHERE id = ?");
            $stmt->execute(['NOTA CLIENTE (' . date('H:i') . '): ' . $text, $data['delivery_id']]);
            TG::sendMessage($chatId,
                "📝 Nota registrata sulla spedizione <b>#" . $data['delivery_id'] . "</b>.\n"
              . "<i>" . htmlspecialchars($text) . "</i>\n\n"
              . "Se vuoi chiudere scrivi <code>ok</code>, oppure continua ad aggiungere note."
            );
            // Stato rimane S_POST per ulteriori note
        } catch (\Throwable $e) {
            TG::sendMessage($chatId, "⚠️ Errore salvando la nota: <code>" . htmlspecialchars($e->getMessage()) . "</code>");
        }
    }

    private static function filtriToString(array $intent): string
    {
        $parts = [];
        if (!empty($intent['filtri']['no_stranieri'])) $parts[] = 'no stranieri (CF)';
        if (!empty($intent['filtri']['only_mobile'])) $parts[] = 'mobile valido';
        if ($intent['filtri']['anno_min'] ?? null)    $parts[] = 'anno ≥ ' . $intent['filtri']['anno_min'];
        if ($intent['filtri']['anno_max'] ?? null)    $parts[] = 'anno ≤ ' . $intent['filtri']['anno_max'];
        return implode(' · ', $parts);
    }

    /** Ripresa del flusso estrazione con un cliente già risolto (usato da FlowNewClient dopo creazione) */
    public static function resumeWithResolvedCliente(int $chatId, array $user, array $intent, array $cliente): void
    {
        TG::sendMessage($chatId, "🔄 Proseguo l'estrazione per <b>" . htmlspecialchars($cliente['ragione_sociale'] ?: ($cliente['nome'].' '.$cliente['cognome'])) . "</b>.");
        self::setCliente($chatId, $user, $intent, $cliente);
    }

    /** Estrae i filtri ricerca cliente dall'intent parsato */
    private static function buildClientFiltersFromIntent(array $intent): array
    {
        $f = [];
        if (!empty($intent['cliente_regione']))  $f['regione']   = $intent['cliente_regione'];
        if (!empty($intent['cliente_zona']))     $f['zona']      = $intent['cliente_zona'];
        if (!empty($intent['cliente_provincia']))$f['provincia'] = $intent['cliente_provincia'];
        if (!empty($intent['cliente_mesi_ultimo_ordine'])) $f['mesi_ultimo_ordine'] = (int)$intent['cliente_mesi_ultimo_ordine'];
        return $f;
    }

    private static function adminEmails(): array
    {
        $pdo = remoteDb('backoffice');
        $r = $pdo->query("SELECT email FROM users WHERE role='admin' AND active=1")->fetchAll(PDO::FETCH_COLUMN);
        return $r ?: ['upselling@gmail.com'];
    }

    // === Stato persistente ===

    public static function getConv(int $chatId): ?array
    {
        $pdo = remoteDb('ai_laboratory');
        $s = $pdo->prepare("SELECT * FROM tg_conversations WHERE chat_id = ?");
        $s->execute([$chatId]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;
        $c['data'] = $c['data'] ? json_decode($c['data'], true) : [];
        return $c;
    }

    private static function saveState(int $chatId, array $user, string $state, array $data): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("REPLACE INTO tg_conversations (chat_id, user_id, flow, state, data) VALUES (?, ?, 'estrai', ?, ?)")
            ->execute([$chatId, $user['id'], $state, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    public static function clearState(int $chatId): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("DELETE FROM tg_conversations WHERE chat_id = ?")->execute([$chatId]);
    }

    /**
     * Timeout flussi inattivi — chiamato dal poller ogni ciclo.
     * Per ogni conversazione non toccata da più di $minutesIdle minuti:
     *   - invia un messaggio "flusso chiuso per inattività"
     *   - invia il menu principale
     *   - elimina la riga
     */
    public static function cleanupStaleConversations(int $minutesIdle = 5): int
    {
        $pdo = remoteDb('ai_laboratory');
        $stale = $pdo->prepare("SELECT chat_id, flow, state FROM tg_conversations WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $stale->execute([$minutesIdle]);
        $rows = $stale->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return 0;

        $del = $pdo->prepare("DELETE FROM tg_conversations WHERE chat_id = ?");
        foreach ($rows as $r) {
            $chatId = (int)$r['chat_id'];
            $flow   = $r['flow'];
            try {
                TG::sendMessage($chatId,
                    "⏳ <b>Flusso chiuso per inattività</b> (nessuna risposta da $minutesIdle min).\n"
                  . "<i>Flusso era: " . htmlspecialchars($flow) . " · stato: " . htmlspecialchars($r['state']) . "</i>\n\n"
                  . "Se eri nel mezzo di un'operazione puoi ricominciare — riparto da capo."
                );
                self::mainMenu($chatId, "💬 <b>Cosa vuoi fare ora?</b>");
            } catch (\Throwable $e) {
                error_log("cleanupStaleConversations notify error for $chatId: " . $e->getMessage());
            }
            $del->execute([$chatId]);
        }
        return count($rows);
    }
}
