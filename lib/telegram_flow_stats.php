<?php
/**
 * Flow /stat — statistiche totale vs magazzino per un cliente, su multiple fonti.
 * Logica: prime 3 fonti di qualità subito; poi chiede se approfondire sulle altre 4.
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/estrai_engine.php';
require_once __DIR__ . '/stats_sources.php';
require_once __DIR__ . '/telegram_flow_new_client.php';

class FlowStats
{
    const S_AWAIT_DEEPEN  = 'stats_await_deepen';
    const S_AWAIT_MAG     = 'stats_await_magazzino';
    const S_CLIENT_NF     = 'stats_client_not_found';    // scegli generico / crea nuovo / annulla
    const S_AWAIT_PROD    = 'stats_await_prodotto';   // chiede prodotto se manca e non c'è storico
    const S_AWAIT_AREA    = 'stats_await_area';       // chiede area se manca
    const S_AWAIT_EXCEL   = 'stats_await_excel';      // dopo la stat chiede se vuole xlsx
    const S_AWAIT_DATE    = 'stats_await_date';       // chiede range date esplicito
    const S_NEW_PIVA      = 'stats_new_piva';         // 1° step: chiedi PIVA/CF
    const S_EXIST_FOUND   = 'stats_exist_found';      // PIVA trovata: admin può cambiare commerciale
    const S_CHANGE_COMM   = 'stats_change_comm';      // admin sceglie nuovo commerciale
    const S_NEW_NAME      = 'stats_new_name';
    const S_NEW_EMAIL     = 'stats_new_email';
    const S_NEW_CONFIRM   = 'stats_new_confirm';
    const GENERIC_CLIENTE_ID = 610;

    public static function run(int $chatId, array $user, array $intent): void
    {
        // Validazione minima
        if (empty($intent['cliente_hint'])) {
            TG::sendMessage($chatId, "👤 <b>Per quale cliente</b> la stat? (nome, ragione sociale, P.IVA, o «generico»)");
            self::saveState($chatId, $user, 'stats_await_cliente', ['intent'=>$intent]);
            return;
        }
        if (empty($intent['area']['valori']) && (($intent['area']['tipo'] ?? '') !== 'nazionale')) {
            TG::sendMessage($chatId,
                "🗺 <b>Per quale area</b> la stat?\n"
              . "<i>Esempi: «Lombardia» · «provincia di Milano» · «Milano, Bergamo, Brescia» · «tutta Italia»</i>"
            );
            self::saveState($chatId, $user, self::S_AWAIT_AREA, ['intent'=>$intent]);
            return;
        }

        // Costruisci filtri cliente e tenta inferenza prodotto se manca
        $filters = [];
        if (!empty($intent['cliente_regione']))  $filters['regione']   = $intent['cliente_regione'];
        if (!empty($intent['cliente_zona']))     $filters['zona']      = $intent['cliente_zona'];
        if (!empty($intent['cliente_provincia']))$filters['provincia'] = $intent['cliente_provincia'];
        if (!empty($intent['cliente_mesi_ultimo_ordine'])) $filters['mesi_ultimo_ordine'] = (int)$intent['cliente_mesi_ultimo_ordine'];
        $candidates = EstraiEngine::findClienti($intent['cliente_hint'], $filters, 5);
        if (!$candidates) {
            // Cliente non trovato → offri opzioni A/B/C
            $hint = $intent['cliente_hint'];
            $msg  = "❌ Cliente <b>\"" . htmlspecialchars($hint) . "\"</b> non trovato in anagrafica.\n\n";
            $msg .= "Cosa vuoi fare?\n";
            $msg .= "<b>A</b> = fai la stat con <i>cliente generico</i> (verrà salvata sotto un placeholder, decidiamo dopo se assegnarla)\n";
            $msg .= "<b>B</b> = crea subito un nuovo cliente e poi procedi con la stat\n";
            $msg .= "<b>C</b> = annulla";
            TG::sendMessage($chatId, $msg);
            self::saveState($chatId, $user, self::S_CLIENT_NF, [
                'intent' => $intent, 'hint' => $hint,
            ]);
            return;
        }
        if (count($candidates) > 1) {
            $m = "Più clienti trovati — ripeti usando nome esatto o PIVA:\n";
            foreach ($candidates as $c) $m .= "• " . htmlspecialchars($c['ragione_sociale'] ?: ($c['nome'].' '.$c['cognome'])) . " (" . $c['partita_iva'] . ")\n";
            TG::sendMessage($chatId, $m);
            return;
        }
        $cliente = $candidates[0];

        // Se prodotto mancante, tento di inferirlo dalle consegne o dagli ordini
        if (empty($intent['prodotto'])) {
            $info = EstraiEngine::getLastProdottoInfo((int)$cliente['id']);
            if ($info) {
                $intent['prodotto'] = $info['prodotto'];
                $src = $info['source'] === 'orders' ? '📦 ordini commerciali' : '🤖 consegne AI';
                TG::sendMessage($chatId, "💡 Categoria <b>" . htmlspecialchars($info['prodotto']) . "</b> inferito da $src (" . htmlspecialchars($info['nome_originale']) . ", " . $info['data'] . ")");
            } else {
                TG::sendMessage($chatId,
                    "📦 <b>Quale categoria</b> per la statistica?\n"
                  . "<i>Es. energia, energia_business, fotovoltaico, depurazione, telefonia, cessione_quinto, finanziarie, email, generiche...</i>"
                );
                // Salvo l'intent parziale con cliente già risolto
                $intent['cliente_hint'] = $cliente['partita_iva'] ?: ($cliente['codice_fiscale'] ?: ($cliente['ragione_sociale'] ?: ($cliente['nome'].' '.$cliente['cognome'])));
                self::saveState($chatId, $user, self::S_AWAIT_PROD, ['intent'=>$intent]);
                return;
            }
        }

        // Magazzino: salvato? altrimenti chiedi (o proponi nessuno se non ci sono candidati)
        $saved = EstraiEngine::getMagazzinoSalvato((int)$cliente['id']);
        if ($saved !== null) {
            $magTable = $saved['magazzino_tabella'];
            $note = $magTable
                ? "🗄 Magazzino memorizzato: <code>" . htmlspecialchars($magTable) . "</code>"
                : "🗄 Nessun magazzino (scelta memorizzata)";
            TG::sendMessage($chatId, $note);
            self::executeStats($chatId, $user, $intent, $cliente, $magTable);
            return;
        }

        $mag = EstraiEngine::findMagazzini($cliente);
        if (!$mag) {
            // Nessun magazzino disponibile — salva "nessuno" e procedi
            TG::sendMessage($chatId, "🗄 Nessun magazzino storico trovato per <b>" . htmlspecialchars($cliente['ragione_sociale'] ?: ($cliente['nome'].' '.$cliente['cognome'])) . "</b>. Procedo senza dedup <i>(scelta memorizzata)</i>.");
            EstraiEngine::setMagazzinoSalvato((int)$cliente['id'], null, (int)$user['id']);
            self::executeStats($chatId, $user, $intent, $cliente, null);
            return;
        }

        // Ci sono candidati: chiedi A/B/C (la scelta viene salvata)
        $msg = "🗄 <b>Magazzini storici trovati per " . htmlspecialchars($cliente['ragione_sociale'] ?: ($cliente['nome'].' '.$cliente['cognome'])) . "</b>:\n\n";
        foreach ($mag as $i => $m) {
            $msg .= ($i+1) . ". <code>" . htmlspecialchars($m['table_name']) . "</code> · " . number_format((int)$m['table_rows']) . " record · creata " . substr($m['create_time'], 0, 10) . "\n";
        }
        $msg .= "\nScegli (la scelta verrà memorizzata per questo cliente):\n";
        $msg .= "<b>A</b> = usa magazzino <code>" . htmlspecialchars($mag[0]['table_name']) . "</code> (il più recente)\n";
        $msg .= "<b>B</b> = nessun dedup\n";
        $msg .= "<b>C</b> = altra tabella (scrivi il numero 1-" . count($mag) . ")";
        TG::sendMessage($chatId, $msg);

        self::saveState($chatId, $user, self::S_AWAIT_MAG, [
            'intent' => $intent, 'cliente' => $cliente, 'magazzini' => $mag,
        ]);
    }

    /**
     * Parse flessibile di un range date: accetta "da X a Y", "X / Y", "X - Y", singolo mese.
     * Ritorna [from_YYYY-MM, to_YYYY-MM] o null se non parse.
     */
    public static function parseDateRange(string $text): ?array
    {
        $text = strtolower(trim($text));
        $mesiIt = [
            'gen'=>1,'gennaio'=>1,'feb'=>2,'febbraio'=>2,'mar'=>3,'marzo'=>3,
            'apr'=>4,'aprile'=>4,'mag'=>5,'maggio'=>5,'giu'=>6,'giugno'=>6,
            'lug'=>7,'luglio'=>7,'ago'=>8,'agosto'=>8,'set'=>9,'settembre'=>9,
            'ott'=>10,'ottobre'=>10,'nov'=>11,'novembre'=>11,'dic'=>12,'dicembre'=>12,
        ];

        // Helper: trova uno (mese, anno) in una stringa
        $findMonthYear = function(string $s) use ($mesiIt): ?array {
            // "MM/YYYY" o "MM-YYYY"
            if (preg_match('/(\d{1,2})[\/\-](\d{4})/', $s, $m)) return [(int)$m[1], (int)$m[2]];
            // "YYYY-MM"
            if (preg_match('/(\d{4})-(\d{2})/', $s, $m)) return [(int)$m[2], (int)$m[1]];
            // "mese YYYY" o "mese dell'anno YYYY"
            foreach ($mesiIt as $nome => $num) {
                if (preg_match('/\b' . $nome . '\w*\s+(?:del(?:l\'anno)?\s+)?(\d{4})/iu', $s, $m)) {
                    return [$num, (int)$m[1]];
                }
                if (preg_match('/\b' . $nome . '\b/iu', $s)) {
                    // mese senza anno → anno corrente (o anno di un altro token)
                    if (preg_match('/\b(\d{4})\b/', $s, $m2)) return [$num, (int)$m2[1]];
                    return [$num, (int)date('Y')];
                }
            }
            // "mm anno breve" — es. "04/26"
            if (preg_match('/(\d{1,2})[\/\-](\d{2})\b/', $s, $m)) return [(int)$m[1], ((int)$m[2] <= 50 ? 2000 + (int)$m[2] : 1900 + (int)$m[2])];
            return null;
        };

        // Helper per ordinare (se l'utente inverte, swap)
        $orderPair = function(array $f, array $t): array {
            $aYM = sprintf('%04d-%02d', $f[1], $f[0]);
            $bYM = sprintf('%04d-%02d', $t[1], $t[0]);
            if ($aYM > $bYM) { [$aYM, $bYM] = [$bYM, $aYM]; }
            return [$aYM, $bYM];
        };

        // Caso 1: separatori "da X a/al Y", "dal X al Y"
        if (preg_match('/^(?:da|dal)\s+(.+?)\s+(?:a|al|ad|fino\s+a|fino\s+al)\s+(.+)$/iu', $text, $m)) {
            $f = $findMonthYear($m[1]); $t = $findMonthYear($m[2]);
            if ($f && $t) return $orderPair($f, $t);
        }
        // Split su / o -
        if (preg_match('/^(.+?)\s*[\/\-–—]\s*(.+)$/u', $text, $m)) {
            $f = $findMonthYear($m[1]); $t = $findMonthYear($m[2]);
            if ($f && $t) return $orderPair($f, $t);
        }

        // Caso 2: "solo X" o singolo mese
        if (preg_match('/^(?:solo\s+)?(.+)$/iu', $text, $m)) {
            $f = $findMonthYear($m[1]);
            if ($f) {
                $ym = sprintf('%04d-%02d', $f[1], $f[0]);
                return [$ym, $ym];
            }
        }
        return null;
    }

    /** Verifica se il testo originale contiene keywords data senza filtri parsati */
    private static function detectUnparsedDateFilter(array $intent): bool
    {
        $raw = strtolower($intent['_raw_text'] ?? '');
        $hasKeyword = preg_match('/\battivazione|\bentro\s+\d|\bultim\w+\s+mes|\ba\s+ritroso|\bda\s+\w+\s+\d{4}|\bfino\s+a\s+\w+/iu', $raw);
        if (!$hasKeyword) return false;
        $f = $intent['filtri'] ?? [];
        $hasFilter = !empty($f['data_att_mese_anno']) || !empty($f['data_att_max_anno_mese']) || !empty($f['data_att_min_anno_mese']);
        return $hasKeyword && !$hasFilter;
    }

    /** Esegue la stat su top 3 e invia risultato + eventuale prompt approfondimento */
    private static function executeStats(int $chatId, array $user, array $intent, array $cliente, ?string $magTable): void
    {
        // Safeguard: se keyword data nel testo ma filtri non parsati → chiedi range esplicito
        if (self::detectUnparsedDateFilter($intent)) {
            TG::sendMessage($chatId,
                "⚠️ Ho rilevato keyword di data attivazione nel tuo testo ma non sono riuscito a parsare un range univoco.\n\n"
              . "📅 <b>Dimmi data inizio e data fine</b> del range (il mese basta).\nFormati accettati:\n"
              . "• <i>«da ottobre 2025 ad aprile 2026»</i>\n"
              . "• <i>«dal 10/2025 al 04/2026»</i>\n"
              . "• <i>«2025-10 / 2026-04»</i>\n"
              . "• <i>«da gennaio a marzo 2026»</i>\n"
              . "• <i>«solo marzo 2026»</i>\n"
              . "• <i>«nessun filtro»</i> per procedere senza filtro data"
            );
            self::saveState($chatId, $user, self::S_AWAIT_DATE, ['intent' => $intent, 'cliente' => $cliente, 'magTable' => $magTable]);
            return;
        }
        // Multi-categoria: itera se ci sono più prodotti
        $prodotti = !empty($intent['prodotti']) && is_array($intent['prodotti']) && count($intent['prodotti']) > 1
            ? $intent['prodotti']
            : [$intent['prodotto']];

        $reasons = [];
        $results = [];
        foreach ($prodotti as $prod) {
            $subIntent = $intent;
            $subIntent['prodotto'] = $prod;
            unset($subIntent['prodotti']);
            [$subSources, $subReason, $subMeta] = StatsSources::pickForIntent($subIntent);
            if (!empty($subMeta['date_filter_ignored'])) {
                unset($subIntent['filtri']['data_att_mese_anno']);
                unset($subIntent['filtri']['data_att_max_anno_mese']);
                unset($subIntent['filtri']['data_att_min_anno_mese']);
            }
            $reasons[] = (count($prodotti) > 1 ? "• <b>$prod</b> → " : '') . $subReason;
            try {
                // Usa runUnifiedDedup → numeri univoci per mobile (no duplicati tra fonti)
                $groupBy = $subIntent['group_by'] ?? 'provincia';
                $subResults = self::runUnifiedDedup($subSources, $subIntent, $magTable, $groupBy);
            } catch (\Throwable $e) {
                TG::sendMessage($chatId, "❌ Errore query su $prod: <code>" . htmlspecialchars($e->getMessage()) . "</code>");
                return;
            }
            foreach ($subResults as &$r) $r['prodotto'] = $prod;
            unset($r);
            $results = array_merge($results, $subResults);
        }

        $reasonMsg = count($prodotti) > 1
            ? "📊 <b>" . count($prodotti) . " categorie</b>:\n" . implode("\n", $reasons)
            : $reasons[0];
        TG::sendMessage($chatId, $reasonMsg);
        TG::sendChatAction($chatId, 'typing');
        $effectiveIntent = $intent;
        $hasDateFilter = StatsSources::hasDateFilter($effectiveIntent);
        // Collezionare tutte le fonti effettivamente usate per display (dall'ultimo iter scorso)
        $sources = [];
        foreach ($prodotti as $prod) {
            $subIntent = $intent; $subIntent['prodotto'] = $prod;
            [$subSources, , ] = StatsSources::pickForIntent($subIntent);
            $sources = array_merge($sources, $subSources);
        }

        $totalSources = count($sources);
        $usedProductFiltering = !$hasDateFilter && count($sources) !== StatsSources::TOP_N_FAST;
        $isComprehensive = $hasDateFilter || $usedProductFiltering;  // in questi casi la selezione è già completa
        $msg = self::formatResults($intent, $cliente, $magTable, $results, $totalSources, $isComprehensive);

        // Salva nello storico stat
        $statId = self::saveStatRecord($user, $intent, $cliente, $magTable, $results, $sources, $msg, $isComprehensive);
        $msg .= "\n\n💾 <b>Stat salvata come #$statId</b> — per richiamarla: <code>/vedistat $statId</code>";

        // Approfondimento: solo se NON abbiamo già usato selezione completa
        $usedKeys  = array_column($sources, 'key');
        $allKeys   = array_column(StatsSources::all(), 'key');
        $restKeys  = array_values(array_diff($allKeys, $usedKeys));
        $restCount = $isComprehensive ? 0 : count($restKeys);
        if ($restCount > 0) {
            $msg .= "\n\n🔍 Vuoi che approfondisca anche sulle altre <b>$restCount fonti</b>? (⏱ più lento)\nRispondi <b>SI</b> per continuare, altro per chiudere.";
        }
        TG::sendMessage($chatId, $msg);

        if ($restCount > 0) {
            self::saveState($chatId, $user, self::S_AWAIT_DEEPEN, [
                'intent' => $intent, 'cliente' => $cliente, 'magTable' => $magTable, 'partial' => $results, 'stat_id' => $statId,
            ]);
        } else {
            // Chiedo se vuole xlsx di riepilogo
            TG::sendMessage($chatId, "📄 Vuoi un <b>Excel di riepilogo</b> di questa stat?\n<b>SI</b> per scaricarlo · <b>NO</b> per tornare al menu.");
            self::saveState($chatId, $user, self::S_AWAIT_EXCEL, ['stat_id' => $statId]);
        }
    }

    /** Gestisce le risposte multi-stato */
    public static function handleReply(int $chatId, array $user, string $text, array $conv): void
    {
        if (FlowEstrai::checkStopIntent($chatId, $user, $text, $conv)) return;

        $state = $conv['state'];
        $data  = $conv['data'];
        $text  = trim($text);

        if ($state === self::S_AWAIT_MAG) {
            self::handleMagazzinoPick($chatId, $user, $text, $data);
            return;
        }

        if ($state === self::S_AWAIT_PROD) {
            $intent = $data['intent'];
            $intent['prodotto'] = strtolower(trim($text));
            self::clearState($chatId);
            self::run($chatId, $user, $intent);
            return;
        }
        if ($state === self::S_AWAIT_DATE) {
            $intent = $data['intent'];
            $t = trim($text);
            if (preg_match('/nessun|senza|no filtr/iu', $t)) {
                // procedi senza filtro
            } else {
                $parsed = self::parseDateRange($t);
                if (!$parsed) {
                    TG::sendMessage($chatId, "❌ Non ho capito il range. Riprova con formato tipo «da ottobre 2025 ad aprile 2026» oppure «nessun filtro».");
                    return;
                }
                [$from, $to] = $parsed;
                if ($from === $to) {
                    // Singolo mese
                    [$y, $m] = explode('-', $from);
                    $mon = ['','JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'][(int)$m];
                    $intent['filtri']['data_att_mese_anno'] = [$mon . '-' . substr($y, 2, 2)];
                } else {
                    $intent['filtri']['data_att_min_anno_mese'] = $from;
                    $intent['filtri']['data_att_max_anno_mese'] = $to;
                }
                TG::sendMessage($chatId, "✓ Filtro date applicato: da <b>$from</b> a <b>$to</b>.");
            }
            self::clearState($chatId);
            self::executeStats($chatId, $user, $intent, $data['cliente'], $data['magTable']);
            return;
        }

        if ($state === self::S_AWAIT_EXCEL) {
            $t = trim($text);
            if (preg_match('/^(si|sì|yes|y|ok|sicuro|procedi|excel|scarica(lo)?)$/iu', $t)) {
                $statId = (int)($data['stat_id'] ?? 0);
                TG::sendMessage($chatId, "⏳ Genero l'Excel di riepilogo...");
                TG::sendChatAction($chatId, 'upload_document');
                try {
                    $path = self::generateStatExcel($statId);
                    if ($path && is_file($path)) {
                        TG::sendDocument($chatId, $path, "📊 Riepilogo stat #$statId");
                    } else {
                        TG::sendMessage($chatId, "❌ Generazione xlsx fallita.");
                    }
                } catch (\Throwable $e) {
                    TG::sendMessage($chatId, "❌ Errore: <code>" . htmlspecialchars($e->getMessage()) . "</code>");
                }
                self::clearState($chatId);
                FlowEstrai::mainMenu($chatId);
                return;
            }
            if (preg_match('/^(no|non voglio|niente|skip|nulla|basta)$/iu', $t)) {
                TG::sendMessage($chatId, "Ok, niente Excel.");
                self::clearState($chatId);
                FlowEstrai::mainMenu($chatId);
                return;
            }
            // Qualsiasi altro testo → considerato nuovo comando: chiudi stato + agente
            self::clearState($chatId);
            TG::sendMessage($chatId, "↪️ Lascio perdere l'Excel e gestisco la nuova richiesta.");
            require_once __DIR__ . '/telegram_flow_agent.php';
            FlowAgent::start($chatId, $user, $text);
            return;
        }
        if ($state === 'stats_await_cliente') {
            $intent = $data['intent'];
            $intent['cliente_hint'] = trim($text);
            self::clearState($chatId);
            self::run($chatId, $user, $intent);
            return;
        }
        if ($state === self::S_AWAIT_AREA) {
            $intent = $data['intent'];
            try {
                $sub = EstraiParser::parse("area: " . $text, null);
                $intent['area'] = $sub['area'] ?? ['tipo'=>'regione','valori'=>[$text]];
            } catch (\Throwable $e) {
                $intent['area'] = ['tipo'=>'regione','valori'=>[$text]];
            }
            self::clearState($chatId);
            self::run($chatId, $user, $intent);
            return;
        }

        if ($state === self::S_CLIENT_NF)    { self::handleClientNotFound($chatId, $user, $text, $data); return; }
        if ($state === self::S_NEW_PIVA)     { self::handleNewPiva($chatId, $user, $text, $data); return; }
        if ($state === self::S_EXIST_FOUND)  { self::handleExistFound($chatId, $user, $text, $data); return; }
        if ($state === self::S_CHANGE_COMM)  { self::handleChangeCommerciale($chatId, $user, $text, $data); return; }
        if ($state === self::S_NEW_NAME)     { self::handleNewName($chatId, $user, $text, $data); return; }
        if ($state === self::S_NEW_EMAIL)    { self::handleNewEmail($chatId, $user, $text, $data); return; }
        if ($state === self::S_NEW_CONFIRM)  { self::handleNewConfirm($chatId, $user, $text, $data); return; }

        if ($state === self::S_AWAIT_DEEPEN) {
            $t = trim($text);
            if (preg_match('/^(no|non|skip|basta|niente)$/iu', $t)) {
                TG::sendMessage($chatId, "Ok, chiudo la stat.");
                self::clearState($chatId);
                FlowEstrai::mainMenu($chatId);
                return;
            }
            if (!preg_match('/^(si|sì|yes|ok|approfondisci|continua|tutte?|altre)$/iu', $t)) {
                // Nuovo comando → route a agent
                self::clearState($chatId);
                TG::sendMessage($chatId, "↪️ Chiudo l'approfondimento e gestisco la nuova richiesta.");
                require_once __DIR__ . '/telegram_flow_agent.php';
                FlowAgent::start($chatId, $user, $text);
                return;
            }
            // Calcola le fonti non ancora usate
            $usedResults = $data['partial'];
            $usedKeys = array_column($usedResults, 'source');
            $restSources = array_values(array_filter(StatsSources::all(), fn($s) => !in_array($s['key'], $usedKeys, true)));
            TG::sendMessage($chatId, "⏳ Estendo alle altre " . count($restSources) . " fonti...");
            TG::sendChatAction($chatId, 'typing');
            try {
                $more = self::runOnSources($restSources, $data['intent'], $data['magTable']);
                $all  = array_merge($data['partial'], $more);
            } catch (\Throwable $e) {
                TG::sendMessage($chatId, "❌ Errore: <code>" . htmlspecialchars($e->getMessage()) . "</code>");
                self::clearState($chatId);
                return;
            }
            $msg = self::formatResults($data['intent'], $data['cliente'], $data['magTable'], $all, count(StatsSources::all()), true);

            // Aggiorna il record con dati approfonditi
            if (!empty($data['stat_id'])) {
                self::updateStatRecord((int)$data['stat_id'], $data['intent'], $data['cliente'], $data['magTable'], $all, StatsSources::all(), $msg, true);
                $msg .= "\n\n💾 Stat #" . $data['stat_id'] . " aggiornata con tutte le fonti.";
            }

            TG::sendMessage($chatId, $msg);
            // Chiedo Excel di riepilogo anche dopo l'approfondimento
            if (!empty($data['stat_id'])) {
                TG::sendMessage($chatId, "📄 Vuoi un <b>Excel di riepilogo</b> di questa stat?\n<b>SI</b> per scaricarlo · <b>NO</b> per tornare al menu.");
                self::saveState($chatId, $user, self::S_AWAIT_EXCEL, ['stat_id' => $data['stat_id']]);
            } else {
                self::clearState($chatId);
                FlowEstrai::mainMenu($chatId);
            }
            return;
        }

        self::clearState($chatId);
    }

    private static function handleMagazzinoPick(int $chatId, array $user, string $text, array $data): void
    {
        $mag = $data['magazzini'];
        $ans = strtoupper($text);
        $chosen = null;

        if ($ans === 'A' && $mag)      { $chosen = $mag[0]['table_name']; }
        elseif ($ans === 'B')           { $chosen = null; }
        elseif (ctype_digit($ans))      {
            $n = (int)$ans;
            if ($n < 1 || $n > count($mag)) {
                TG::sendMessage($chatId, "Numero non valido (1-" . count($mag) . ").");
                return;
            }
            $chosen = $mag[$n-1]['table_name'];
        } elseif ($ans === 'C')         {
            TG::sendMessage($chatId, "Ok, scrivi il numero della tabella (1-" . count($mag) . ").");
            return;
        } else {
            TG::sendMessage($chatId, "Scegli A / B / C o numero tabella.");
            return;
        }

        EstraiEngine::setMagazzinoSalvato((int)$data['cliente']['id'], $chosen, (int)$user['id']);
        $confirm = $chosen
            ? "✅ Magazzino salvato: <code>" . htmlspecialchars($chosen) . "</code>. Dedup attivo."
            : "✅ Nessun magazzino. Scelta memorizzata.";
        TG::sendMessage($chatId, $confirm);

        self::executeStats($chatId, $user, $data['intent'], $data['cliente'], $chosen);
    }

    /**
     * Esegue UNA sola query che fa UNION ALL di tutte le fonti, poi DEDUP per mobile,
     * poi GROUP BY group_col. Numeri univoci per persona (stesso mobile conta 1 sola volta
     * anche se presente in più fonti).
     *
     * Ritorna array nel formato runOnSources (per retrocompatibilità con formatResults):
     * [{source:'UNIFIED', label:'Dedup unificato (N fonti)', rows:[{g,totale,mobili,fissi,consegnati}], dur, err}]
     */
    private static function runUnifiedDedup(array $sources, array $intent, ?string $magTable, string $groupBy): array
    {
        $pdo = self::freshConn();
        $unions = []; $params = [];
        $skipped = [];

        foreach ($sources as $src) {
            $c = $src['cols'];
            $mobCol   = $c['mobile'];
            $fissoCol = $c['fisso'] ?? null;
            $cfCol    = $c['cf'] ?? null;
            $dateCol  = $c['date'] ?? null;
            $provCol  = $c['provincia'] ?? null;
            $regCol   = $c['regione']   ?? null;
            $comCol   = $c['comune']    ?? null;

            // Group expression per fonte
            $grpExpr = null;
            if ($groupBy === 'provincia') $grpExpr = $provCol ? "s.`$provCol`" : null;
            elseif ($groupBy === 'regione') $grpExpr = $regCol ? "s.`$regCol`" : null;
            elseif ($groupBy === 'comune')  $grpExpr = $comCol ? "s.`$comCol`" : null;
            if (!$grpExpr) { $skipped[] = $src['key']; continue; }

            // Where: ha almeno un telefono + filtri area + no_stranieri + date
            $hasPhone = "(s.`$mobCol` IS NOT NULL AND s.`$mobCol` != '')";
            if ($fissoCol) $hasPhone = "($hasPhone OR (s.`$fissoCol` IS NOT NULL AND s.`$fissoCol` != ''))";
            $where = [$hasPhone];

            $a = $intent['area'] ?? [];
            if (($a['tipo'] ?? '') === 'regione' && $provCol) {
                $ors = [];
                foreach ($a['valori'] as $v) {
                    $sigle = EstraiEngine::regionePerZona($v);
                    // alternativo: mapping sigle per regione (in StatsSources)
                    $sigleProv = self::regionSiglesFor($v);
                    if ($sigleProv) {
                        $ph = implode(',', array_fill(0, count($sigleProv), '?'));
                        $ors[] = "s.`$provCol` IN ($ph)";
                        foreach ($sigleProv as $sig) $params[] = $sig;
                    } else {
                        $ors[] = "s.`$regCol` LIKE ?"; $params[] = '%' . $v . '%';
                    }
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'provincia') {
                if (!$provCol) { $skipped[] = $src['key']; continue; }
                $ors = [];
                foreach ($a['valori'] as $v) {
                    $ors[] = "s.`$provCol` = ?"; $params[] = $v;
                    $ors[] = "s.`$provCol` = ?"; $params[] = EstraiEngine::provToSigla($v);
                }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'comune') {
                if (!$comCol) { $skipped[] = $src['key']; continue; }
                $ors = [];
                foreach ($a['valori'] as $v) { $ors[] = "s.`$comCol` = ?"; $params[] = $v; }
                $where[] = '(' . implode(' OR ', $ors) . ')';
            } elseif (($a['tipo'] ?? '') === 'cap') {
                [$capConds, $capParams] = EstraiEngine::buildCapClauses("s.`CAP`", $a['valori']);
                $where[] = '(' . implode(' OR ', $capConds) . ')';
                $params = array_merge($params, $capParams);
            }

            if (!empty($intent['filtri']['no_stranieri']) && $cfCol) {
                $where[] = "LENGTH(s.`$cfCol`) = 16 AND SUBSTRING(s.`$cfCol`, 12, 1) != 'Z'";
            }

            // Date filter (solo se fonte ha colonna data)
            if ($dateCol && StatsSources::hasDateFilter($intent)) {
                [$dConds, $dParams] = EstraiEngine::buildDateAttivazioneClauses("s.`$dateCol`", $intent['filtri'] ?? []);
                if ($dConds) {
                    $where[] = '(' . implode(' AND ', $dConds) . ')';
                    $params = array_merge($params, $dParams);
                }
            }

            // Magazzino JOIN
            $magJoin = ''; $consExpr = '0';
            if ($magTable) {
                $magJoin = " LEFT JOIN `clienti`.`" . $magTable . "` h ON h.mobile = s.`$mobCol`";
                $consExpr = "(CASE WHEN h.mobile IS NOT NULL THEN 1 ELSE 0 END)";
            }

            $mobileFlag = "(CASE WHEN s.`$mobCol` IS NOT NULL AND s.`$mobCol` != '' THEN 1 ELSE 0 END)";
            $fissoFlag  = $fissoCol ? "(CASE WHEN s.`$fissoCol` IS NOT NULL AND s.`$fissoCol` != '' THEN 1 ELSE 0 END)" : '0';

            $sub = "SELECT CONVERT(s.`$mobCol` USING utf8mb4) AS mobile, CONVERT($grpExpr USING utf8mb4) AS g, $mobileFlag AS has_mobile, $fissoFlag AS has_fisso, $consExpr AS consegnato FROM `" . $src['db'] . "`.`" . $src['table'] . "` s $magJoin WHERE " . implode(' AND ', $where);
            $unions[] = $sub;
        }

        if (!$unions) {
            return [['source'=>'UNIFIED', 'label'=>'Dedup unificato (0 fonti)', 'rows'=>[], 'dur'=>0, 'err'=>'Nessuna fonte compatibile col group_by']];
        }

        $unionSql = implode(' UNION ALL ', $unions);
        $outerSql = "SELECT g,
                            COUNT(*) AS totale,
                            SUM(has_mobile) AS mobili,
                            SUM(has_fisso)  AS fissi,
                            SUM(consegnato) AS consegnati
                     FROM (
                         SELECT mobile,
                                MAX(g) AS g,
                                MAX(has_mobile) AS has_mobile,
                                MAX(has_fisso)  AS has_fisso,
                                MAX(consegnato) AS consegnato
                         FROM ($unionSql) u
                         WHERE mobile IS NOT NULL AND mobile != ''
                         GROUP BY mobile
                     ) d
                     GROUP BY g
                     ORDER BY totale DESC";

        $t0 = microtime(true);
        $stmt = $pdo->prepare($outerSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dur = round(microtime(true)-$t0, 1);

        $label = '🔗 Dedup unificato (' . count($unions) . ' fonti · mobile univoci)';
        if ($skipped) $label .= ' · skipped: ' . implode(',', $skipped);
        return [[
            'source' => 'UNIFIED', 'label' => $label, 'rows' => $rows,
            'dur' => $dur, 'err' => null,
        ]];
    }

    /** Sigle province per regione — duplicato semplice per non creare dipendenza circolare */
    private static function regionSiglesFor(string $regione): array
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
        return $map[strtolower(trim($regione))] ?? [];
    }

    /** Itera le fonti — connessione FRESH per ogni query (evita "server has gone away") */
    private static function runOnSources(array $sources, array $intent, ?string $magTable): array
    {
        $groupBy = $intent['group_by'] ?? 'provincia';
        $out = [];
        foreach ($sources as $src) {
            $t0 = microtime(true);
            try {
                // Connessione dedicata con timeout lunghi per ogni fonte
                $pdo = self::freshConn();
                $rows = self::queryOne($pdo, $src, $intent, $magTable, $groupBy);
                $out[] = [
                    'source' => $src['key'], 'label' => $src['label'], 'rows' => $rows,
                    'dur' => round(microtime(true) - $t0, 1), 'err' => null,
                ];
            } catch (\Throwable $e) {
                $out[] = [
                    'source' => $src['key'], 'label' => $src['label'], 'rows' => [],
                    'dur' => round(microtime(true) - $t0, 1), 'err' => $e->getMessage(),
                ];
            }
        }
        return $out;
    }

    private static function freshConn(): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', AI_DB_HOST, AI_DB_PORT);
        $pdo = new PDO($dsn, AI_DB_USER, AI_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 300,  // connect timeout
        ]);
        // Timeout lato server: tetto duro 90s per query stat (MAX_EXECUTION_TIME in ms, MySQL 5.7+).
        // Se una stat non risponde in 90s → MySQL aborta la query, PHP riceve errore, l'utente vede il messaggio.
        $pdo->exec("SET SESSION wait_timeout = 120, net_read_timeout = 120, net_write_timeout = 120, max_execution_time = 90000");
        return $pdo;
    }

    private static function queryOne(PDO $pdo, array $src, array $intent, ?string $magTable, string $groupBy): array
    {
        $c = $src['cols'];
        $mobCol  = '`' . $c['mobile'] . '`';
        $fissoCol = $c['fisso'] ? '`' . $c['fisso'] . '`' : null;
        $provCol = '`' . $c['provincia'] . '`';
        $regCol  = '`' . $c['regione']   . '`';
        $comCol  = $c['comune'] ? '`' . $c['comune'] . '`' : null;
        $cfCol   = '`' . $c['cf'] . '`';

        $groupExpr = match ($groupBy) {
            'provincia' => "s.$provCol",
            'regione'   => "s.$regCol",
            'comune'    => $comCol ? "s.$comCol" : null,
            default     => "s.$provCol",
        };
        if (!$groupExpr) throw new RuntimeException("Fonte {$src['key']} non supporta group_by=$groupBy");

        // Filtro "ha almeno un telefono" (mobile o fisso se esiste)
        $hasPhoneExpr = "(s.$mobCol IS NOT NULL AND s.$mobCol != '')";
        if ($fissoCol) $hasPhoneExpr = "($hasPhoneExpr OR (s.$fissoCol IS NOT NULL AND s.$fissoCol != ''))";
        $where = [$hasPhoneExpr];
        $params = [];

        $a = $intent['area'];
        if (($a['tipo'] ?? '') === 'regione') {
            // Preferisci PROVINCIA IN (sigle) — usa l'indice — fallback a LIKE regione se non mappata
            $ors = [];
            foreach ($a['valori'] as $v) {
                $sigle = self::sigleProvincePerRegione($v);
                if ($sigle) {
                    $placeholders = implode(',', array_fill(0, count($sigle), '?'));
                    $ors[] = "s.$provCol IN ($placeholders)";
                    foreach ($sigle as $sig) $params[] = $sig;
                } else {
                    $ors[] = "s.$regCol LIKE ?"; $params[] = '%' . $v . '%';
                }
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        } elseif (($a['tipo'] ?? '') === 'provincia') {
            $ors = [];
            foreach ($a['valori'] as $v) {
                $ors[] = "s.$provCol = ?"; $params[] = $v;
                $ors[] = "s.$provCol = ?"; $params[] = EstraiEngine::provToSigla($v);
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        } elseif (($a['tipo'] ?? '') === 'comune') {
            if (!$comCol) throw new RuntimeException("Fonte {$src['key']} non ha colonna comune");
            $ors = [];
            foreach ($a['valori'] as $v) { $ors[] = "s.$comCol = ?"; $params[] = $v; }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        } elseif (($a['tipo'] ?? '') === 'cap') {
            // Usa colonna CAP (tutte le fonti la hanno)
            [$capConds, $capParams] = EstraiEngine::buildCapClauses('s.CAP', $a['valori']);
            $where[] = '(' . implode(' OR ', $capConds) . ')';
            $params = array_merge($params, $capParams);
        }
        if (!empty($intent['filtri']['no_stranieri'])) {
            $where[] = "LENGTH(s.$cfCol) = 16 AND SUBSTRING(s.$cfCol, 12, 1) != 'Z'";
        }

        // Filtro date attivazione/decorrenza — SOLO se la fonte supporta una colonna data
        $dateCol = $c['date'] ?? null;
        if ($dateCol && StatsSources::hasDateFilter($intent)) {
            [$dateConds, $dateParams] = EstraiEngine::buildDateAttivazioneClauses("s.`$dateCol`", $intent['filtri'] ?? []);
            if ($dateConds) {
                $where[] = '(' . implode(' AND ', $dateConds) . ')';
                $params = array_merge($params, $dateParams);
            }
        }
        $whereExpr = 'WHERE ' . implode(' AND ', $where);

        // Espressioni per mobili / fissi / consegnati
        $sumMobili = "SUM(CASE WHEN s.$mobCol IS NOT NULL AND s.$mobCol != '' THEN 1 ELSE 0 END)";
        $sumFissi  = $fissoCol
            ? "SUM(CASE WHEN s.$fissoCol IS NOT NULL AND s.$fissoCol != '' THEN 1 ELSE 0 END)"
            : "0";

        if ($magTable) {
            $sql = "SELECT $groupExpr AS g,
                           COUNT(*) AS totale,
                           $sumMobili AS mobili,
                           $sumFissi AS fissi,
                           SUM(CASE WHEN h.mobile IS NOT NULL THEN 1 ELSE 0 END) AS consegnati
                    FROM `" . $src['db'] . "`.`" . $src['table'] . "` s
                    LEFT JOIN `clienti`.`" . $magTable . "` h ON h.mobile = s.$mobCol
                    $whereExpr
                    GROUP BY $groupExpr
                    ORDER BY totale DESC";
        } else {
            $sql = "SELECT $groupExpr AS g,
                           COUNT(*) AS totale,
                           $sumMobili AS mobili,
                           $sumFissi AS fissi,
                           0 AS consegnati
                    FROM `" . $src['db'] . "`.`" . $src['table'] . "` s
                    $whereExpr
                    GROUP BY $groupExpr
                    ORDER BY totale DESC";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function formatResults(array $intent, array $cliente, ?string $magTable, array $results, int $totalSources, bool $isDeep): string
    {
        $groupBy  = $intent['group_by'] ?? 'provincia';
        $areaStr  = implode(', ', $intent['area']['valori']);
        $nomeClnt = $cliente['ragione_sociale'] ?: ($cliente['nome'] . ' ' . $cliente['cognome']);

        // Aggrega totali per group_by + totali assoluti + totali per prodotto (multi-categoria)
        $totalsByGroup = [];
        $sourcesBreakdown = [];
        $prodottiBreakdown = [];    // nuovo: aggrega per prodotto
        $grandTot = 0; $grandCons = 0; $grandMob = 0; $grandFis = 0;

        foreach ($results as $res) {
            $srcTot = 0; $srcCons = 0; $srcMob = 0; $srcFis = 0;
            $currProd = $res['prodotto'] ?? ($intent['prodotto'] ?? 'n/d');
            foreach ($res['rows'] as $r) {
                $g = $r['g'] ?? '(vuoto)';
                $totale = (int)$r['totale']; $cons = (int)$r['consegnati'];
                $mob  = (int)($r['mobili'] ?? 0); $fis = (int)($r['fissi'] ?? 0);
                $srcTot += $totale; $srcCons += $cons; $srcMob += $mob; $srcFis += $fis;
                if (!isset($totalsByGroup[$g])) $totalsByGroup[$g] = ['totale'=>0,'cons'=>0,'mob'=>0,'fis'=>0];
                $totalsByGroup[$g]['totale'] += $totale;
                $totalsByGroup[$g]['cons'] += $cons;
                $totalsByGroup[$g]['mob']  += $mob;
                $totalsByGroup[$g]['fis']  += $fis;
            }
            // Breakdown per source (unico per fonte; se stessa fonte appare per più prodotti, somma)
            $srcKey = $res['source'] . '@' . $currProd;
            $sourcesBreakdown[$srcKey] = [
                'label' => $res['label'] . (count(array_unique(array_column($results, 'prodotto'))) > 1 ? ' [' . $currProd . ']' : ''),
                'totale' => $srcTot, 'cons' => $srcCons, 'mob' => $srcMob, 'fis' => $srcFis,
                'dur' => $res['dur'], 'err' => $res['err'],
            ];
            // Breakdown per prodotto
            if (!isset($prodottiBreakdown[$currProd])) {
                $prodottiBreakdown[$currProd] = ['totale'=>0,'cons'=>0,'mob'=>0,'fis'=>0,'fonti'=>[]];
            }
            $prodottiBreakdown[$currProd]['totale'] += $srcTot;
            $prodottiBreakdown[$currProd]['cons']   += $srcCons;
            $prodottiBreakdown[$currProd]['mob']    += $srcMob;
            $prodottiBreakdown[$currProd]['fis']    += $srcFis;
            $prodottiBreakdown[$currProd]['fonti'][$res['source']] = $res['label'];
            $grandTot += $srcTot; $grandCons += $srcCons; $grandMob += $srcMob; $grandFis += $srcFis;
        }
        uasort($totalsByGroup, fn($a,$b) => $b['totale'] - $a['totale']);

        $header = $isDeep ? "📊 <b>Statistica completa ($totalSources fonti)</b>" : "📊 <b>Statistica rapida (3 fonti)</b>";
        $msg  = "$header\n";
        $msg .= "Cliente: <b>" . htmlspecialchars($nomeClnt) . "</b> · Categoria: <b>" . htmlspecialchars($intent['prodotto']) . "</b>\n";
        $msg .= "Area: <b>" . htmlspecialchars($areaStr) . "</b> · Magazzino: " . ($magTable ? "<code>".htmlspecialchars($magTable)."</code>" : "<i>nessuno</i>") . "\n";

        $msg .= "\n🔢 <b>Totali aggregati</b>\n";
        $msg .= "  Totale: " . number_format($grandTot, 0, ',', '.') . " record\n";
        $msg .= "  📱 Mobili: " . number_format($grandMob, 0, ',', '.') . "\n";
        $msg .= "  ☎️ Fissi: " . number_format($grandFis, 0, ',', '.') . "\n";
        if ($magTable) {
            $disp = $grandTot - $grandCons;
            $msg .= "  🗄 Già consegnati: " . number_format($grandCons, 0, ',', '.') . "\n";
            $msg .= "  ✅ Disponibili: <b>" . number_format($disp, 0, ',', '.') . "</b>\n";
        }

        // Se multi-categoria, mostra breakdown per prodotto
        if (count($prodottiBreakdown) > 1) {
            $msg .= "\n🏷 <b>Per categoria</b>\n";
            foreach ($prodottiBreakdown as $p => $v) {
                $msg .= "  • <b>" . htmlspecialchars($p) . "</b> → totale " . number_format($v['totale']) . " · 📱 " . number_format($v['mob']) . " · ☎️ " . number_format($v['fis']);
                if ($magTable) $msg .= " · disp " . number_format($v['totale'] - $v['cons']);
                $msg .= "\n      <i>fonti: " . implode(', ', array_values($v['fonti'])) . "</i>\n";
            }
        }

        $msg .= "\n📚 <b>Per fonte</b>\n";
        foreach ($sourcesBreakdown as $key => $s) {
            if ($s['err']) {
                $msg .= "  ❗ " . htmlspecialchars($s['label']) . " — errore: <code>" . htmlspecialchars(substr($s['err'],0,80)) . "</code>\n";
                continue;
            }
            $line = "  • " . htmlspecialchars($s['label']) . "\n";
            $line .= "      totale " . number_format($s['totale']) . " · 📱 " . number_format($s['mob']) . " · ☎️ " . number_format($s['fis']);
            if ($magTable) $line .= " · ✅ disp " . number_format($s['totale'] - $s['cons']);
            $line .= " <i>(" . $s['dur'] . "s)</i>\n";
            $msg .= $line;
        }

        $msg .= "\n🗺 <b>Per " . $groupBy . "</b> (top 20)\n";
        $shown = 0;
        foreach ($totalsByGroup as $g => $v) {
            if ($v['totale'] === 0) continue;
            $label = htmlspecialchars($g);
            $phoneTxt = "📱 " . number_format($v['mob']) . " · ☎️ " . number_format($v['fis']);
            if ($magTable) {
                $disp = $v['totale'] - $v['cons'];
                $msg .= sprintf("  <b>%s</b> → ✅ %s disp  <i>(%s, totale %s)</i>\n",
                    $label, number_format($disp), $phoneTxt, number_format($v['totale']));
            } else {
                $msg .= sprintf("  <b>%s</b> → %s  <i>(totale %s)</i>\n", $label, $phoneTxt, number_format($v['totale']));
            }
            if (++$shown >= 20) {
                $rem = count($totalsByGroup) - $shown;
                if ($rem > 0) $msg .= "  <i>… e altri $rem " . $groupBy . "</i>\n";
                break;
            }
        }
        return $msg;
    }

    /** Sigle province italiane per regione (per sfruttare indici sulla colonna provincia) */
    private static function sigleProvincePerRegione(string $regione): array
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
        return $map[strtolower(trim($regione))] ?? [];
    }

    // === Gestione cliente non trovato → opzioni A/B/C ===

    private static function handleClientNotFound(int $chatId, array $user, string $text, array $data): void
    {
        $a = strtoupper(trim($text));
        if ($a === 'A' || preg_match('/generic/iu', $text)) {
            self::clearState($chatId);
            FlowNewClient::useGeneric($chatId, $user, ['flow'=>'stat', 'intent'=>$data['intent']]);
            return;
        }
        if ($a === 'B' || preg_match('/(nuov|crea)/iu', $text)) {
            self::clearState($chatId);
            FlowNewClient::start($chatId, $user, ['flow'=>'stat', 'intent'=>$data['intent']]);
            return;
        }
        if ($a === 'C' || preg_match('/(annull|stop|no)/iu', $text)) {
            self::clearState($chatId);
            TG::sendMessage($chatId, "❎ Annullato.");
            FlowEstrai::mainMenu($chatId);
            return;
        }
        TG::sendMessage($chatId, "Rispondi <b>A</b> (generico) / <b>B</b> (nuovo) / <b>C</b> (annulla).");
    }

    private static function handleNewName(int $chatId, array $user, string $text, array $data): void
    {
        $name = trim($text);
        if (strlen($name) < 2) { TG::sendMessage($chatId, "Nome troppo corto. Riprova."); return; }
        $isCompany = (bool)preg_match('/\b(s\.?r\.?l\.?s?|s\.?p\.?a\.?|s\.?a\.?s\.?|s\.?n\.?c\.?|srl|spa|srls|sas|snc)\b/iu', $name);
        $data['new_ragione_sociale'] = $isCompany ? $name : '';
        if ($isCompany) {
            $data['new_nome'] = ''; $data['new_cognome'] = '';
        } else {
            $parts = preg_split('/\s+/', $name, 2);
            $data['new_nome']    = $parts[0];
            $data['new_cognome'] = $parts[1] ?? '';
        }
        TG::sendMessage($chatId, "✓ Nome salvato.\n\n📧 <b>Email del cliente</b> (opzionale, «skip» se non ce l'hai).");
        self::saveState($chatId, $user, self::S_NEW_EMAIL, $data);
    }

    private static function handleNewPiva(int $chatId, array $user, string $text, array $data): void
    {
        $t = trim($text);
        $piva = ''; $cf = '';
        if (preg_match('/^(skip|nessun|no)$/iu', $t)) {
            // Senza PIVA/CF: non possiamo controllare duplicati → vai diretto a nome
            $data['new_piva'] = ''; $data['new_cf'] = '';
            TG::sendMessage($chatId, "✓ OK senza PIVA/CF.\n\nOra il <b>nome completo</b> o la <b>ragione sociale</b>.");
            self::saveState($chatId, $user, self::S_NEW_NAME, $data);
            return;
        } elseif (preg_match('/^\d{11}$/', $t)) {
            $piva = $t; $cf = $t;
        } elseif (preg_match('/^[A-Za-z0-9]{16}$/', $t)) {
            $cf = strtoupper($t);
        } else {
            TG::sendMessage($chatId, "Formato non valido. Scrivi 11 cifre (PIVA) o 16 caratteri (CF) oppure «skip».");
            return;
        }

        // Check esistenza nel DB backoffice.clientes
        $pdo = remoteDb('backoffice');
        $q = $pdo->prepare("SELECT c.*, u.name AS commerciale_name, u.email AS commerciale_email
                            FROM clientes c LEFT JOIN users u ON u.id = c.user_id
                            WHERE (? != '' AND c.partita_iva = ?) OR (? != '' AND c.codice_fiscale = ?) LIMIT 1");
        $q->execute([$piva, $piva, $cf, $cf]);
        $found = $q->fetch(PDO::FETCH_ASSOC);

        if ($found) {
            // Cliente già esistente → mostra tutto + offri cambio commerciale se admin
            $nome = $found['ragione_sociale'] ?: trim(($found['nome'] ?? '') . ' ' . ($found['cognome'] ?? ''));
            $msg  = "⚠️ <b>Cliente GIÀ ESISTENTE in anagrafica</b> (id " . $found['id'] . ")\n\n";
            $msg .= "👤 <b>" . htmlspecialchars($nome) . "</b>\n";
            if ($found['partita_iva'])    $msg .= "P.IVA: " . htmlspecialchars($found['partita_iva']) . "\n";
            if ($found['codice_fiscale']) $msg .= "CF: " . htmlspecialchars($found['codice_fiscale']) . "\n";
            if ($found['email'])          $msg .= "Email: " . htmlspecialchars($found['email']) . "\n";
            if ($found['indirizzo'])      $msg .= "Indirizzo: " . htmlspecialchars($found['indirizzo']) . " " . ($found['civico'] ?? '') . "\n";
            if ($found['comune'])         $msg .= "Comune: " . htmlspecialchars($found['comune']) . " (" . ($found['provincia'] ?? '') . ")\n";
            if ($found['stato'])          $msg .= "Regione: " . htmlspecialchars($found['stato']) . "\n";
            $msg .= "\n👷 <b>Commerciale assegnato</b>: " . htmlspecialchars($found['commerciale_name'] ?? 'nessuno') . " <i>(user_id " . ($found['user_id'] ?? '-') . ")</i>\n\n";

            $data['existing_cliente'] = $found;

            $isAdmin = ($user['role'] === 'admin');
            if ($isAdmin) {
                $msg .= "Sei <b>admin</b>. Vuoi modificare il commerciale di riferimento?\n";
                $msg .= "<b>CAMBIA</b> = sì, scegli un altro\n";
                $msg .= "<b>OK</b> (o invia qualsiasi cosa) = tieni il commerciale attuale e procedi con la stat";
            } else {
                $msg .= "Procedo con la stat per questo cliente.\nRispondi <b>OK</b> per continuare, <b>ANNULLA</b> per fermarmi.";
            }
            TG::sendMessage($chatId, $msg);
            self::saveState($chatId, $user, self::S_EXIST_FOUND, $data);
            return;
        }

        // PIVA nuova → continua con la creazione
        $data['new_piva'] = $piva;
        $data['new_cf']   = $cf;
        TG::sendMessage($chatId, "✓ PIVA/CF nuovi — procedo con la creazione.\n\n📛 Dimmi il <b>nome completo</b> o la <b>ragione sociale</b>.\n<i>Esempio: «Mario Rossi» · «Rossi Impianti SRL»</i>");
        self::saveState($chatId, $user, self::S_NEW_NAME, $data);
    }

    /** Cliente esistente trovato via PIVA/CF: admin può cambiare commerciale */
    private static function handleExistFound(int $chatId, array $user, string $text, array $data): void
    {
        $t = strtolower(trim($text));
        $isAdmin = ($user['role'] === 'admin');

        if (preg_match('/^(annull|stop|no|basta)/iu', $t)) {
            self::clearState($chatId);
            TG::sendMessage($chatId, "❎ OK, fermo tutto.");
            FlowEstrai::mainMenu($chatId);
            return;
        }

        if ($isAdmin && preg_match('/^(cambi|modifi|riassegn)/iu', $t)) {
            // Lista commerciali disponibili
            $pdo = remoteDb('backoffice');
            $rows = $pdo->query("SELECT id, name, email, role, commerciale FROM users WHERE active = 1 ORDER BY role DESC, name")->fetchAll(PDO::FETCH_ASSOC);
            $msg = "👷 <b>Scegli il nuovo commerciale</b>\n\n";
            $data['commerciali_list'] = [];
            foreach ($rows as $i => $r) {
                $idx = $i + 1;
                $tag = $r['role'] === 'admin' ? '👑' : ($r['commerciale'] ? '💼' : '  ');
                $msg .= sprintf("<b>%d</b>. %s %s (%s)\n", $idx, $tag, htmlspecialchars($r['name']), htmlspecialchars($r['email']));
                $data['commerciali_list'][$idx] = (int)$r['id'];
            }
            $msg .= "\nScrivi il numero, oppure <b>SKIP</b> per mantenere l'attuale.";
            TG::sendMessage($chatId, $msg);
            self::saveState($chatId, $user, self::S_CHANGE_COMM, $data);
            return;
        }

        // OK o qualsiasi altro → procedi con la stat sul cliente trovato
        self::clearState($chatId);
        $existing = $data['existing_cliente'];
        $hint = $existing['partita_iva'] ?: ($existing['codice_fiscale'] ?: ($existing['ragione_sociale'] ?: ($existing['nome'] . ' ' . $existing['cognome'])));
        TG::sendMessage($chatId, "✓ Procedo con la stat per <b>" . htmlspecialchars($existing['ragione_sociale'] ?: ($existing['nome'] . ' ' . $existing['cognome'])) . "</b>.");
        $intent = $data['intent'];
        $intent['cliente_hint'] = $hint;
        self::run($chatId, $user, $intent);
    }

    /** Admin ha scelto di cambiare commerciale → aspetta indice */
    private static function handleChangeCommerciale(int $chatId, array $user, string $text, array $data): void
    {
        $t = trim($text);
        if (preg_match('/^(skip|no|annull)$/iu', $t)) {
            // Tieni commerciale attuale → procedi con stat
            TG::sendMessage($chatId, "✓ Commerciale invariato. Procedo.");
        } elseif (ctype_digit($t) && isset($data['commerciali_list'][(int)$t])) {
            $newCommId = $data['commerciali_list'][(int)$t];
            $pdo = remoteDb('backoffice');
            $pdo->prepare("UPDATE clientes SET user_id = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$newCommId, $data['existing_cliente']['id']]);
            // Leggi nuovo nome
            $nn = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $nn->execute([$newCommId]);
            $newName = $nn->fetchColumn() ?: ('id ' . $newCommId);
            TG::sendMessage($chatId, "✅ Commerciale aggiornato: ora <b>" . htmlspecialchars($newName) . "</b>.");
        } else {
            TG::sendMessage($chatId, "Scegli un numero valido (1-" . count($data['commerciali_list']) . ") o scrivi <b>SKIP</b>.");
            return;
        }
        self::clearState($chatId);
        $existing = $data['existing_cliente'];
        $hint = $existing['partita_iva'] ?: ($existing['codice_fiscale'] ?: ($existing['ragione_sociale'] ?: ($existing['nome'] . ' ' . $existing['cognome'])));
        $intent = $data['intent'];
        $intent['cliente_hint'] = $hint;
        self::run($chatId, $user, $intent);
    }

    private static function handleNewEmail(int $chatId, array $user, string $text, array $data): void
    {
        $t = trim($text);
        if (preg_match('/^(skip|nessun|no)$/iu', $t)) {
            $data['new_email'] = '';
        } elseif (filter_var($t, FILTER_VALIDATE_EMAIL)) {
            $data['new_email'] = strtolower($t);
        } else {
            TG::sendMessage($chatId, "Email non valida. Riprova o scrivi «skip».");
            return;
        }

        // Riepilogo + conferma
        $nome = $data['new_ragione_sociale'] ?: trim($data['new_nome'] . ' ' . $data['new_cognome']);
        $msg  = "📋 <b>Riepilogo nuovo cliente</b>\n\n";
        $msg .= "Nome: <b>" . htmlspecialchars($nome) . "</b>\n";
        $msg .= "P.IVA: " . ($data['new_piva'] ?: '<i>—</i>') . "\n";
        $msg .= "CF: "     . ($data['new_cf']   ?: '<i>—</i>') . "\n";
        $msg .= "Email: "  . ($data['new_email']?: '<i>—</i>') . "\n";
        $msg .= "Commerciale: user_id " . $user['id'] . " (<b>" . htmlspecialchars($user['name']) . "</b>)\n\n";
        $msg .= "<b>SI</b> per salvare e procedere con la stat.\n<b>NO</b> per annullare.";
        TG::sendMessage($chatId, $msg);
        self::saveState($chatId, $user, self::S_NEW_CONFIRM, $data);
    }

    private static function handleNewConfirm(int $chatId, array $user, string $text, array $data): void
    {
        if (!preg_match('/^(si|sì|yes|y|ok|confermo|procedi|vai)$/iu', trim($text))) {
            self::clearState($chatId);
            TG::sendMessage($chatId, "❎ Nuovo cliente scartato.");
            FlowEstrai::mainMenu($chatId);
            return;
        }

        try {
            $pdo = remoteDb('backoffice');
            $stmt = $pdo->prepare("INSERT INTO clientes (user_id, ragione_sociale, nome, cognome, partita_iva, codice_fiscale, email, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([
                (int)$user['id'],
                $data['new_ragione_sociale'] ?: '',
                $data['new_nome'] ?: '',
                $data['new_cognome'] ?: '',
                $data['new_piva'] ?: '',
                $data['new_cf'] ?: '',
                $data['new_email'] ?: '',
                'Creato via bot Telegram da ' . $user['name'],
            ]);
            $newId = (int)$pdo->lastInsertId();
        } catch (\Throwable $e) {
            TG::sendMessage($chatId, "❌ Errore creazione cliente: <code>" . htmlspecialchars($e->getMessage()) . "</code>");
            self::clearState($chatId);
            return;
        }

        $nome = $data['new_ragione_sociale'] ?: trim($data['new_nome'] . ' ' . $data['new_cognome']);
        TG::sendMessage($chatId, "✅ Cliente creato: <b>" . htmlspecialchars($nome) . "</b> (id $newId).\nProseguo con la stat...");
        self::clearState($chatId);

        // Rilancia stat con il nuovo cliente: usa P.IVA o nome come hint (match esatto)
        $intent = $data['intent'];
        $intent['cliente_hint'] = $data['new_piva'] ?: $nome;
        self::run($chatId, $user, $intent);
    }

    // === Salvataggio + richiamo stat ===

    private static function computeTotals(array $results): array
    {
        $tot = 0; $mob = 0; $fis = 0; $cons = 0;
        foreach ($results as $res) {
            foreach ($res['rows'] as $r) {
                $tot  += (int)($r['totale'] ?? 0);
                $mob  += (int)($r['mobili'] ?? 0);
                $fis  += (int)($r['fissi'] ?? 0);
                $cons += (int)($r['consegnati'] ?? 0);
            }
        }
        return ['tot'=>$tot, 'mob'=>$mob, 'fis'=>$fis, 'cons'=>$cons, 'disp'=>max(0, $tot-$cons)];
    }

    private static function saveStatRecord(array $user, array $intent, array $cliente, ?string $magTable, array $results, array $sources, string $messageHtml, bool $isDeep): int
    {
        $pdo = remoteDb('ai_laboratory');
        $tot = self::computeTotals($results);
        $srcKeys = implode(',', array_column($sources, 'key'));
        $area = implode(', ', $intent['area']['valori'] ?? []);
        $cliNome = $cliente['ragione_sociale'] ?: trim(($cliente['nome'] ?? '') . ' ' . ($cliente['cognome'] ?? ''));

        $stmt = $pdo->prepare("INSERT INTO stat_history
            (executed_by_user_id, cliente_id, cliente_nome, prodotto, area, group_by_col,
             magazzino_tabella, sources_queried, total_records, total_mobili, total_fissi,
             total_consegnati, total_disponibili, is_deep, message_html, raw_data_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            (int)$user['id'], (int)$cliente['id'], $cliNome,
            $intent['prodotto'], $area, $intent['group_by'] ?? 'provincia',
            $magTable, $srcKeys,
            $tot['tot'], $tot['mob'], $tot['fis'], $tot['cons'], $tot['disp'],
            $isDeep ? 1 : 0, $messageHtml,
            json_encode(['intent'=>$intent,'results'=>$results], JSON_UNESCAPED_UNICODE),
        ]);
        return (int)$pdo->lastInsertId();
    }

    private static function updateStatRecord(int $id, array $intent, array $cliente, ?string $magTable, array $results, array $sources, string $messageHtml, bool $isDeep): void
    {
        $pdo = remoteDb('ai_laboratory');
        $tot = self::computeTotals($results);
        $srcKeys = implode(',', array_column($sources, 'key'));
        $stmt = $pdo->prepare("UPDATE stat_history SET
            sources_queried=?, total_records=?, total_mobili=?, total_fissi=?,
            total_consegnati=?, total_disponibili=?, is_deep=?, message_html=?, raw_data_json=?
            WHERE id=?");
        $stmt->execute([
            $srcKeys, $tot['tot'], $tot['mob'], $tot['fis'], $tot['cons'], $tot['disp'],
            $isDeep ? 1 : 0, $messageHtml,
            json_encode(['intent'=>$intent,'results'=>$results], JSON_UNESCAPED_UNICODE),
            $id,
        ]);
    }

    /** Genera un file xlsx di riepilogo della stat — multi-sheet con totali, per categoria, per fonte, per group_by */
    public static function generateStatExcel(int $statId): ?string
    {
        $pdo = remoteDb('ai_laboratory');
        $s = $pdo->prepare("SELECT * FROM stat_history WHERE id = ?");
        $s->execute([$statId]);
        $rec = $s->fetch(PDO::FETCH_ASSOC);
        if (!$rec) return null;

        $raw = $rec['raw_data_json'] ? json_decode($rec['raw_data_json'], true) : [];
        $intent  = $raw['intent']  ?? [];
        $results = $raw['results'] ?? [];

        // Aggrega come in formatResults
        $totalsByGroup = []; $sourcesBreakdown = []; $prodotti = [];
        $grandTot = 0; $grandCons = 0; $grandMob = 0; $grandFis = 0;

        foreach ($results as $res) {
            $srcTot=0; $srcCons=0; $srcMob=0; $srcFis=0;
            $currProd = $res['prodotto'] ?? ($intent['prodotto'] ?? 'n/d');
            foreach ($res['rows'] as $r) {
                $g = $r['g'] ?? '(vuoto)';
                $tot=(int)$r['totale']; $cons=(int)$r['consegnati'];
                $mob=(int)($r['mobili']??0); $fis=(int)($r['fissi']??0);
                $srcTot+=$tot; $srcCons+=$cons; $srcMob+=$mob; $srcFis+=$fis;
                if (!isset($totalsByGroup[$g])) $totalsByGroup[$g]=['tot'=>0,'cons'=>0,'mob'=>0,'fis'=>0];
                $totalsByGroup[$g]['tot']+=$tot; $totalsByGroup[$g]['cons']+=$cons;
                $totalsByGroup[$g]['mob']+=$mob; $totalsByGroup[$g]['fis']+=$fis;
            }
            $sourcesBreakdown[] = ['fonte'=>$res['label'],'categoria'=>$currProd,'tot'=>$srcTot,'mob'=>$srcMob,'fis'=>$srcFis,'cons'=>$srcCons];
            if (!isset($prodotti[$currProd])) $prodotti[$currProd]=['tot'=>0,'cons'=>0,'mob'=>0,'fis'=>0];
            $prodotti[$currProd]['tot']+=$srcTot; $prodotti[$currProd]['cons']+=$srcCons;
            $prodotti[$currProd]['mob']+=$srcMob; $prodotti[$currProd]['fis']+=$srcFis;
            $grandTot+=$srcTot; $grandCons+=$srcCons; $grandMob+=$srcMob; $grandFis+=$srcFis;
        }
        uasort($totalsByGroup, fn($a,$b)=>$b['tot']-$a['tot']);

        // Build JSON per Python
        $spec = [
            'meta' => [
                'stat_id'       => $rec['id'],
                'executed_at'   => $rec['executed_at'],
                'cliente'       => $rec['cliente_nome'],
                'prodotto'      => $rec['prodotto'],
                'area'          => $rec['area'],
                'group_by'      => $rec['group_by_col'],
                'magazzino'     => $rec['magazzino_tabella'] ?: '(nessuno)',
                'sources_used'  => $rec['sources_queried'],
                'is_deep'       => (bool)$rec['is_deep'],
            ],
            'totali'    => ['tot'=>$grandTot,'mob'=>$grandMob,'fis'=>$grandFis,'cons'=>$grandCons,'disp'=>max(0,$grandTot-$grandCons)],
            'prodotti'  => array_map(fn($k,$v)=>array_merge(['categoria'=>$k], $v), array_keys($prodotti), $prodotti),
            'fonti'     => $sourcesBreakdown,
            'gruppi'    => array_map(fn($k,$v)=>array_merge(['g'=>$k], $v), array_keys($totalsByGroup), $totalsByGroup),
        ];

        $outDir = AI_ROOT . '/downloads/stats';
        @mkdir($outDir, 0775, true);
        $xlsxPath = $outDir . "/stat_{$statId}.xlsx";

        $jsonPath = tempnam(sys_get_temp_dir(), 'stat_') . '.json';
        file_put_contents($jsonPath, json_encode($spec, JSON_UNESCAPED_UNICODE));
        $scriptPath = tempnam(sys_get_temp_dir(), 'stat_xlsx_') . '.py';
        file_put_contents($scriptPath, self::pyStatXlsxScript());

        exec('python3 ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($jsonPath) . ' ' . escapeshellarg($xlsxPath) . ' 2>&1', $out, $rc);
        @unlink($jsonPath); @unlink($scriptPath);
        if ($rc !== 0) {
            error_log('generateStatExcel python error: ' . implode("\n", $out));
            return null;
        }
        return $xlsxPath;
    }

    private static function pyStatXlsxScript(): string
    {
        return <<<'PY'
import sys, json
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

spec_file, xlsx_path = sys.argv[1], sys.argv[2]
with open(spec_file, encoding='utf-8') as f:
    spec = json.load(f)

wb = Workbook()
header_fill = PatternFill("solid", fgColor="1E40AF")
header_font = Font(bold=True, color="FFFFFF", size=11)
thin = Side(border_style="thin", color="94A3B8")
border = Border(left=thin, right=thin, top=thin, bottom=thin)
center = Alignment(horizontal="center", vertical="center")
zebra = PatternFill("solid", fgColor="F8FAFC")

def style_header(ws, ncols):
    for c in range(1, ncols+1):
        cell = ws.cell(row=1, column=c)
        cell.fill=header_fill; cell.font=header_font; cell.border=border; cell.alignment=center
    ws.row_dimensions[1].height = 24

def zebrify(ws, ncols):
    for r in range(2, ws.max_row+1):
        if r % 2 == 0:
            for c in range(1, ncols+1): ws.cell(row=r, column=c).fill = zebra

# Sheet 1: Riepilogo
ws = wb.active
ws.title = "Riepilogo"
meta = spec['meta']; t = spec['totali']
rows = [
    ("Stat ID", meta['stat_id']),
    ("Eseguita", meta['executed_at']),
    ("Cliente", meta['cliente']),
    ("Categoria", meta['prodotto']),
    ("Area", meta['area']),
    ("Group by", meta['group_by']),
    ("Magazzino", meta['magazzino']),
    ("Fonti interrogate", meta['sources_used']),
    ("Approfondita", "Sì" if meta['is_deep'] else "No"),
    ("", ""),
    ("Totale record", t['tot']),
    ("Mobili", t['mob']),
    ("Fissi", t['fis']),
    ("Già consegnati", t['cons']),
    ("Disponibili", t['disp']),
]
for r in rows: ws.append(r)
for c in ws['A']: c.font = Font(bold=True)
ws.column_dimensions['A'].width = 22
ws.column_dimensions['B'].width = 50

# Sheet 2: Per categoria (se multi)
if len(spec['prodotti']) > 1:
    ws2 = wb.create_sheet("Per categoria")
    ws2.append(['Categoria','Totale','Mobili','Fissi','Consegnati','Disponibili'])
    for p in spec['prodotti']:
        disp = max(0, p['tot']-p['cons'])
        ws2.append([p['categoria'], p['tot'], p['mob'], p['fis'], p['cons'], disp])
    style_header(ws2, 6); zebrify(ws2, 6)
    for col,w in zip('ABCDEF',[20,14,14,14,14,14]): ws2.column_dimensions[col].width = w

# Sheet: Per group_by
ws4 = wb.create_sheet(f"Per {meta['group_by']}")
ws4.append([meta['group_by'].capitalize(),'Totale','Mobili','Fissi','Consegnati','Disponibili'])
for g in spec['gruppi']:
    disp = max(0, g['tot']-g['cons'])
    ws4.append([g['g'], g['tot'], g['mob'], g['fis'], g['cons'], disp])
style_header(ws4, 6); zebrify(ws4, 6)
for col,w in zip('ABCDEF',[24,14,14,14,14,14]): ws4.column_dimensions[col].width = w
ws4.auto_filter.ref = ws4.dimensions
ws4.freeze_panes = "A2"

wb.save(xlsx_path)
PY;
    }

    /** Richiama per ID e invia il messaggio salvato */
    public static function viewStat(int $chatId, int $statId): void
    {
        $pdo = remoteDb('ai_laboratory');
        $s = $pdo->prepare("SELECT * FROM stat_history WHERE id = ?");
        $s->execute([$statId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            TG::sendMessage($chatId, "❌ Stat #$statId non trovata.");
            return;
        }
        $header = "♻️ <b>Stat #" . $r['id'] . " richiamata</b> <i>(eseguita " . substr($r['executed_at'], 0, 16) . ")</i>\n\n";
        TG::sendMessage($chatId, $header . $r['message_html']);
    }

    /** Lista stat salvate — filtrabile per cliente e/o range di date */
    public static function listStats(int $chatId, ?string $clienteHint = null, int $limit = 30, ?string $dateFrom = null, ?string $dateTo = null): void
    {
        $pdo = remoteDb('ai_laboratory');
        $where = []; $params = []; $filtersDesc = [];

        if ($clienteHint) {
            $cands = EstraiEngine::findClienti($clienteHint, 1);
            if (!$cands) { TG::sendMessage($chatId, "❌ Cliente \"" . htmlspecialchars($clienteHint) . "\" non trovato."); return; }
            $cli = $cands[0];
            $where[] = 'cliente_id = ?'; $params[] = (int)$cli['id'];
            $filtersDesc[] = "cliente: " . htmlspecialchars($cli['ragione_sociale'] ?: ($cli['nome'] . ' ' . $cli['cognome']));
        }

        if ($dateFrom) {
            $where[] = 'DATE(executed_at) >= ?'; $params[] = $dateFrom;
            $filtersDesc[] = "da $dateFrom";
        }
        if ($dateTo) {
            $where[] = 'DATE(executed_at) <= ?'; $params[] = $dateTo;
            $filtersDesc[] = "a $dateTo";
        }

        $whereExpr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT id, executed_at, cliente_nome, prodotto, area, group_by_col, total_records, total_disponibili, is_deep
                FROM stat_history
                $whereExpr
                ORDER BY executed_at DESC
                LIMIT " . (int)$limit;
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);

        $titleFilter = $filtersDesc ? ' · ' . implode(' · ', $filtersDesc) : '';
        if (!$rows) { TG::sendMessage($chatId, "📋 Nessuna stat salvata" . $titleFilter . "."); return; }

        $msg = "💾 <b>" . count($rows) . " stat salvate</b>" . $titleFilter . "\n\n";
        foreach ($rows as $r) {
            $deep = $r['is_deep'] ? ' 🔬' : '';
            $msg .= "<b>#" . $r['id'] . "</b>$deep · " . substr($r['executed_at'], 0, 16) . "\n";
            $msg .= "  " . htmlspecialchars($r['cliente_nome']) . " · " . htmlspecialchars($r['prodotto']) . " · " . htmlspecialchars($r['area']) . "\n";
            $msg .= "  Totale " . number_format((int)$r['total_records']) . " · Disp " . number_format((int)$r['total_disponibili']) . "\n";
            $msg .= "  <code>/vedistat " . $r['id'] . "</code>\n\n";
        }
        TG::sendMessage($chatId, $msg);
    }

    // === state persistence (condivide tabella con FlowEstrai) ===
    private static function saveState(int $chatId, array $user, string $state, array $data): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("REPLACE INTO tg_conversations (chat_id, user_id, flow, state, data) VALUES (?, ?, 'stats', ?, ?)")
            ->execute([$chatId, $user['id'], $state, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }
    public static function clearState(int $chatId): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("DELETE FROM tg_conversations WHERE chat_id = ? AND flow = 'stats'")->execute([$chatId]);
    }
}
