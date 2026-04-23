<?php
/**
 * Flow condiviso — creazione nuovo cliente via blob-paste + Claude parsing.
 * Usato sia da FlowEstrai che da FlowStats quando il cliente non viene trovato.
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/estrai_engine.php';
require_once __DIR__ . '/estrai_parser.php';

class FlowNewClient
{
    const S_ASK_BLOB      = 'nc_await_blob';       // chiede incollare tutti i dati
    const S_ASK_MISSING   = 'nc_await_missing';    // chiede un singolo campo mancante
    const S_EXIST_FOUND   = 'nc_exist_found';      // PIVA duplicata → ask admin
    const S_CHANGE_COMM   = 'nc_change_comm';      // admin cambia commerciale
    const S_CONFIRM       = 'nc_confirm';          // SI/NO salva

    const GENERIC_CLIENTE_ID = 610;

    /**
     * Punto di ingresso — chiamato da FlowStats/FlowEstrai quando cliente non trovato.
     * $resume: ['flow'=>'stats'|'estrai', 'intent'=>array]
     */
    public static function start(int $chatId, array $user, array $resume): void
    {
        TG::sendMessage($chatId,
            "📝 <b>Nuovo cliente</b>\n\n"
          . "Incolla qui i <b>dati del cliente</b> — puoi scriverli alla rinfusa, in qualsiasi ordine, anche su più righe. Io li sistemo.\n\n"
          . "<b>Dati raccomandati</b>:\n"
          . "• P.IVA\n"
          . "• Nome ditta (Ragione sociale)\n"
          . "• Persona di riferimento\n"
          . "• Indirizzo + civico\n"
          . "• Comune + provincia + CAP\n"
          . "• Email\n"
          . "• Cellulare\n\n"
          . "<i>Esempio: «Rossi Impianti SRL · piva 01234567890 · Mario Rossi · via Garibaldi 12 · 37121 Verona VR · mario@rossi.it · 3331234567»</i>\n\n"
          . "Oppure scrivi <b>annulla</b> per tornare indietro."
        );
        self::saveState($chatId, $user, self::S_ASK_BLOB, [
            'resume' => $resume,
            'blob'   => '',
            'parsed' => [],
        ]);
    }

    public static function handleReply(int $chatId, array $user, string $text, array $conv): void
    {
        require_once __DIR__ . '/telegram_flow_estrai.php';
        if (FlowEstrai::checkStopIntent($chatId, $user, $text, $conv)) return;

        $state = $conv['state'];
        $data  = $conv['data'];
        $t     = trim($text);

        if (preg_match('/^(annull|stop|basta|\/annulla)/iu', $t)) {
            self::clearState($chatId);
            TG::sendMessage($chatId, "❎ Creazione cliente annullata.");
            require_once __DIR__ . '/telegram_flow_estrai.php';
            FlowEstrai::mainMenu($chatId);
            return;
        }

        switch ($state) {
            case self::S_ASK_BLOB:     self::handleBlob($chatId, $user, $text, $data); return;
            case self::S_ASK_MISSING:  self::handleMissing($chatId, $user, $text, $data); return;
            case self::S_EXIST_FOUND:  self::handleExistFound($chatId, $user, $text, $data); return;
            case self::S_CHANGE_COMM:  self::handleChangeComm($chatId, $user, $text, $data); return;
            case self::S_CONFIRM:      self::handleConfirm($chatId, $user, $text, $data); return;
        }
        self::clearState($chatId);
    }

    private static function handleBlob(int $chatId, array $user, string $text, array $data): void
    {
        TG::sendChatAction($chatId, 'typing');
        try {
            $parsed = EstraiParser::parseClientBlob($text);
        } catch (\Throwable $e) {
            TG::sendMessage($chatId, "❌ Errore parsing: " . htmlspecialchars($e->getMessage()) . "\nRiprova.");
            return;
        }

        $data['blob']   = $text;
        $data['parsed'] = $parsed;

        // 1) Se abbiamo PIVA o CF, check duplicati
        if (!empty($parsed['piva']) || !empty($parsed['codice_fiscale'])) {
            $existing = self::lookupExisting($parsed['piva'] ?? '', $parsed['codice_fiscale'] ?? '');
            if ($existing) {
                self::showExistingClient($chatId, $user, $existing, $data);
                return;
            }
        }

        // 2) Campi obbligatori minimi: (ragione_sociale OR nome+cognome) + almeno 1 tra piva/cf/email/telefono
        $missing = self::missingFields($parsed);
        if ($missing) {
            self::askMissing($chatId, $user, $data, $missing[0]);
            return;
        }

        // 3) Tutto OK → recap + conferma
        self::showRecapAndAskConfirm($chatId, $user, $data);
    }

    private static function missingFields(array $p): array
    {
        $missing = [];
        $hasName = !empty($p['ragione_sociale']) || (!empty($p['nome']) && !empty($p['cognome']));
        if (!$hasName) $missing[] = 'nome';
        $hasId = !empty($p['piva']) || !empty($p['codice_fiscale']) || !empty($p['email']) || !empty($p['telefono']);
        if (!$hasId) $missing[] = 'contatto_o_piva';
        return $missing;
    }

    private static function askMissing(int $chatId, array $user, array $data, string $field): void
    {
        $prompts = [
            'nome'             => "📛 Mi manca il <b>nome</b>. Dimmi la ragione sociale (es. «Rossi SRL») oppure nome e cognome della persona (es. «Mario Rossi»).",
            'contatto_o_piva'  => "📞 Mi manca almeno un identificativo. Dimmi <b>P.IVA</b>, <b>CF</b>, <b>email</b> o <b>cellulare</b> (anche solo uno).",
        ];
        $data['ask_missing_field'] = $field;
        TG::sendMessage($chatId, $prompts[$field] ?? "Mi manca: $field");
        self::saveState($chatId, $user, self::S_ASK_MISSING, $data);
    }

    private static function handleMissing(int $chatId, array $user, string $text, array $data): void
    {
        // Riparsiamo: aggiungiamo il testo al blob e riproviamo
        $data['blob'] .= "\n" . $text;
        try {
            $parsed = EstraiParser::parseClientBlob($data['blob']);
        } catch (\Throwable $e) {
            TG::sendMessage($chatId, "❌ Parse errore: " . htmlspecialchars($e->getMessage()));
            return;
        }
        $data['parsed'] = $parsed;

        // Re-check duplicati dopo nuovo input
        if (!empty($parsed['piva']) || !empty($parsed['codice_fiscale'])) {
            $existing = self::lookupExisting($parsed['piva'] ?? '', $parsed['codice_fiscale'] ?? '');
            if ($existing) { self::showExistingClient($chatId, $user, $existing, $data); return; }
        }

        $missing = self::missingFields($parsed);
        if ($missing) { self::askMissing($chatId, $user, $data, $missing[0]); return; }

        self::showRecapAndAskConfirm($chatId, $user, $data);
    }

    private static function lookupExisting(string $piva, string $cf): ?array
    {
        if ($piva === '' && $cf === '') return null;
        $pdo = remoteDb('backoffice');
        $q = $pdo->prepare("SELECT c.*, u.name AS commerciale_name FROM clientes c LEFT JOIN users u ON u.id = c.user_id
            WHERE (? != '' AND c.partita_iva = ?) OR (? != '' AND c.codice_fiscale = ?) LIMIT 1");
        $q->execute([$piva, $piva, $cf, $cf]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function showExistingClient(int $chatId, array $user, array $found, array $data): void
    {
        $nome = $found['ragione_sociale'] ?: trim(($found['nome'] ?? '') . ' ' . ($found['cognome'] ?? ''));
        $msg  = "⚠️ <b>Cliente GIÀ ESISTENTE</b> (id " . $found['id'] . ")\n\n";
        $msg .= "👤 <b>" . htmlspecialchars($nome) . "</b>\n";
        if ($found['partita_iva'])    $msg .= "P.IVA: " . htmlspecialchars($found['partita_iva']) . "\n";
        if ($found['codice_fiscale']) $msg .= "CF: " . htmlspecialchars($found['codice_fiscale']) . "\n";
        if ($found['email'])          $msg .= "Email: " . htmlspecialchars($found['email']) . "\n";
        if ($found['indirizzo'])      $msg .= "Indirizzo: " . htmlspecialchars($found['indirizzo']) . " " . ($found['civico'] ?? '') . "\n";
        if ($found['comune'])         $msg .= "Comune: " . htmlspecialchars($found['comune']) . " (" . ($found['provincia'] ?? '') . ")\n";
        $msg .= "\n👷 <b>Commerciale</b>: " . htmlspecialchars($found['commerciale_name'] ?? '—') . " (id " . ($found['user_id'] ?? '-') . ")\n\n";
        $data['existing'] = $found;

        if ($user['role'] === 'admin') {
            $msg .= "Sei admin. Vuoi cambiare il commerciale?\n<b>CAMBIA</b> = sì · <b>OK</b> = tieni e procedi · <b>ANNULLA</b> = stop";
        } else {
            $msg .= "<b>OK</b> per procedere con questo cliente · <b>ANNULLA</b> per fermarti.";
        }
        TG::sendMessage($chatId, $msg);
        self::saveState($chatId, $user, self::S_EXIST_FOUND, $data);
    }

    private static function handleExistFound(int $chatId, array $user, string $text, array $data): void
    {
        $t = strtolower(trim($text));
        if (preg_match('/^cambi/iu', $t) && $user['role'] === 'admin') {
            $pdo = remoteDb('backoffice');
            $rows = $pdo->query("SELECT id, name, email, role, commerciale FROM users WHERE active = 1 ORDER BY role DESC, name")->fetchAll(PDO::FETCH_ASSOC);
            $msg = "👷 <b>Scegli nuovo commerciale</b>\n\n";
            $data['commerciali_list'] = [];
            foreach ($rows as $i => $r) {
                $idx = $i + 1;
                $tag = $r['role'] === 'admin' ? '👑' : ($r['commerciale'] ? '💼' : '  ');
                $msg .= sprintf("<b>%d</b>. %s %s\n", $idx, $tag, htmlspecialchars($r['name']));
                $data['commerciali_list'][$idx] = (int)$r['id'];
            }
            $msg .= "\nScrivi il numero o <b>SKIP</b>.";
            TG::sendMessage($chatId, $msg);
            self::saveState($chatId, $user, self::S_CHANGE_COMM, $data);
            return;
        }
        // OK → resume
        self::clearState($chatId);
        self::resumeAfterClient($chatId, $user, $data['existing'], $data['resume']);
    }

    private static function handleChangeComm(int $chatId, array $user, string $text, array $data): void
    {
        $t = trim($text);
        if (preg_match('/^skip$/iu', $t)) {
            // nothing to do
        } elseif (ctype_digit($t) && isset($data['commerciali_list'][(int)$t])) {
            $newId = $data['commerciali_list'][(int)$t];
            $pdo = remoteDb('backoffice');
            $pdo->prepare("UPDATE clientes SET user_id = ?, updated_at = NOW() WHERE id = ?")->execute([$newId, $data['existing']['id']]);
            TG::sendMessage($chatId, "✅ Commerciale aggiornato.");
        } else {
            TG::sendMessage($chatId, "Scrivi un numero (1-" . count($data['commerciali_list']) . ") o <b>SKIP</b>.");
            return;
        }
        self::clearState($chatId);
        self::resumeAfterClient($chatId, $user, $data['existing'], $data['resume']);
    }

    private static function showRecapAndAskConfirm(int $chatId, array $user, array $data): void
    {
        $p = $data['parsed'];
        $nome = $p['ragione_sociale'] ?: trim(($p['nome'] ?? '') . ' ' . ($p['cognome'] ?? ''));
        $msg  = "📋 <b>Recap nuovo cliente</b>\n\n";
        $msg .= "Nome/Ragione sociale: <b>" . htmlspecialchars($nome) . "</b>\n";
        if ($p['ragione_sociale'] && ($p['nome'] || $p['cognome'])) {
            $msg .= "Persona di riferimento: " . htmlspecialchars(trim($p['nome'] . ' ' . $p['cognome'])) . "\n";
        }
        foreach (['piva'=>'P.IVA','codice_fiscale'=>'CF','email'=>'Email','telefono'=>'Tel',
                  'indirizzo'=>'Indirizzo','civico'=>'Civico','comune'=>'Comune',
                  'provincia'=>'Provincia','cap'=>'CAP','regione'=>'Regione'] as $k=>$lbl) {
            if (!empty($p[$k])) $msg .= "$lbl: " . htmlspecialchars($p[$k]) . "\n";
        }
        $msg .= "\nCommerciale: <b>" . htmlspecialchars($user['name']) . "</b> (id " . $user['id'] . ")\n\n";
        $msg .= "<b>SI</b> per inserire in anagrafica e procedere, <b>NO</b> per annullare.\n";
        $msg .= "<i>Se vuoi correggere qualcosa, scrivi direttamente il dato da modificare (es. «email: pippo@x.it» oppure «civico 45»).</i>";
        TG::sendMessage($chatId, $msg);
        self::saveState($chatId, $user, self::S_CONFIRM, $data);
    }

    private static function handleConfirm(int $chatId, array $user, string $text, array $data): void
    {
        if (preg_match('/^(si|sì|yes|y|ok|confermo|inserisci|salva|procedi)$/iu', trim($text))) {
            // INSERT
            try {
                $p = $data['parsed'];
                $pdo = remoteDb('backoffice');
                $stmt = $pdo->prepare("INSERT INTO clientes (user_id, ragione_sociale, nome, cognome, partita_iva, codice_fiscale, indirizzo, civico, comune, provincia, cap, stato, numero_cellulare, email, note, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
                $stmt->execute([
                    (int)$user['id'],
                    $p['ragione_sociale'] ?: '',
                    $p['nome'] ?: '',
                    $p['cognome'] ?: '',
                    $p['piva'] ?: '',
                    $p['codice_fiscale'] ?: '',
                    $p['indirizzo'] ?: '',
                    $p['civico'] ?: '',
                    $p['comune'] ?: '',
                    $p['provincia'] ?: '',
                    $p['cap'] ?: '',
                    $p['regione'] ?: '',
                    $p['telefono'] ?: '',
                    $p['email'] ?: '',
                    'Creato via bot Telegram da ' . $user['name'],
                ]);
                $newId = (int)$pdo->lastInsertId();
                // Fetch per avere la riga completa
                $q = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
                $q->execute([$newId]);
                $cliente = $q->fetch(PDO::FETCH_ASSOC);

                $nome = $cliente['ragione_sociale'] ?: trim($cliente['nome'] . ' ' . $cliente['cognome']);
                TG::sendMessage($chatId, "✅ Cliente creato: <b>" . htmlspecialchars($nome) . "</b> (id $newId).");
                self::clearState($chatId);
                self::resumeAfterClient($chatId, $user, $cliente, $data['resume']);
            } catch (\Throwable $e) {
                TG::sendMessage($chatId, "❌ Errore INSERT: " . htmlspecialchars($e->getMessage()));
                self::clearState($chatId);
            }
            return;
        }

        // Input di correzione al volo: "email xxx", "civico nn", ecc. → re-parse con correzione
        $data['blob'] .= "\n" . $text;
        try {
            $data['parsed'] = EstraiParser::parseClientBlob($data['blob']);
        } catch (\Throwable $e) {
            TG::sendMessage($chatId, "❌ Parse errore: " . htmlspecialchars($e->getMessage()));
            return;
        }
        self::showRecapAndAskConfirm($chatId, $user, $data);
    }

    /** Resume del flusso originale dopo aver ottenuto un cliente valido */
    private static function resumeAfterClient(int $chatId, array $user, array $cliente, array $resume): void
    {
        $intent = $resume['intent'] ?? [];
        $intent['cliente_hint'] = $cliente['partita_iva'] ?: ($cliente['codice_fiscale'] ?: ($cliente['ragione_sociale'] ?: ($cliente['nome'] . ' ' . $cliente['cognome'])));
        switch ($resume['flow'] ?? 'stat') {
            case 'stat':
                require_once __DIR__ . '/telegram_flow_stats.php';
                FlowStats::run($chatId, $user, $intent);
                break;
            case 'estrai':
                require_once __DIR__ . '/telegram_flow_estrai.php';
                FlowEstrai::resumeWithResolvedCliente($chatId, $user, $intent, $cliente);
                break;
            default:
                TG::sendMessage($chatId, "Cliente pronto. Rifai la richiesta usando il nome o la P.IVA.");
        }
    }

    public static function useGeneric(int $chatId, array $user, array $resume): void
    {
        $pdo = remoteDb('backoffice');
        $q = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
        $q->execute([self::GENERIC_CLIENTE_ID]);
        $cli = $q->fetch(PDO::FETCH_ASSOC);
        TG::sendMessage($chatId, "ℹ️ Proseguo con <b>cliente generico</b> (id " . self::GENERIC_CLIENTE_ID . "). Puoi aggiungere una nota per richiamare la stat più tardi.");
        self::resumeAfterClient($chatId, $user, $cli, $resume);
    }

    private static function saveState(int $chatId, array $user, string $state, array $data): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("REPLACE INTO tg_conversations (chat_id, user_id, flow, state, data) VALUES (?, ?, 'newclient', ?, ?)")
            ->execute([$chatId, $user['id'], $state, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    public static function clearState(int $chatId): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("DELETE FROM tg_conversations WHERE chat_id = ? AND flow = 'newclient'")->execute([$chatId]);
    }
}
