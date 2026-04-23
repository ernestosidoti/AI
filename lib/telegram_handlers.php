<?php
/**
 * Telegram — handlers per i comandi e la registrazione utenti
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram_flow_estrai.php';

class TGState
{
    // Stato conversazionale semplice (in memoria — per ora)
    // chat_id => ['step' => 'awaiting_email', 'data' => [...]]
    private static array $state = [];

    public static function set(int $chatId, string $step, array $data = []): void {
        self::$state[$chatId] = ['step' => $step, 'data' => $data, 'ts' => time()];
    }
    public static function get(int $chatId): ?array { return self::$state[$chatId] ?? null; }
    public static function clear(int $chatId): void { unset(self::$state[$chatId]); }
}

class TGHandler
{
    /** Entry point per ogni update Telegram */
    public static function handleUpdate(array $update): void
    {
        if (isset($update['message'])) {
            self::handleMessage($update['message']);
        }
        // callback_query gestiti in futuro per pulsanti inline
    }

    private static function handleMessage(array $msg): void
    {
        $chatId   = (int)$msg['chat']['id'];
        $text     = trim($msg['text'] ?? '');
        $from     = $msg['from'] ?? [];
        $username = $from['username'] ?? null;
        $fromName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));

        if ($text === '') return;

        // 1. Utente già registrato?
        $user = self::findUserByChatId($chatId);

        // 2. Comando /start sempre permesso
        if (str_starts_with($text, '/start')) {
            self::cmdStart($chatId, $user, $fromName, $username);
            return;
        }

        // 3. In attesa di email? (conversazione di registrazione)
        $state = TGState::get($chatId);
        if ($state && $state['step'] === 'awaiting_email') {
            self::completeRegistration($chatId, $text, $username);
            return;
        }

        // 4. Utente non registrato → richiedi /start
        if (!$user) {
            TG::sendMessage($chatId, "Non sei ancora registrato. Scrivi /start per iniziare.");
            return;
        }

        // 5. Conversazione attiva?
        $conv = FlowEstrai::getConv($chatId);
        if ($conv && !str_starts_with($text, '/estrai')) {
            if ($conv['flow'] === 'estrai') {
                FlowEstrai::handleReply($chatId, $user, $text, $conv);
                return;
            }
            if ($conv['flow'] === 'stats') {
                FlowStats::handleReply($chatId, $user, $text, $conv);
                return;
            }
            if ($conv['flow'] === 'magazzino') {
                FlowMagazzino::handleReply($chatId, $user, $text, $conv);
                return;
            }
            if ($conv['flow'] === 'newclient') {
                require_once __DIR__ . '/telegram_flow_new_client.php';
                FlowNewClient::handleReply($chatId, $user, $text, $conv);
                return;
            }
            if ($conv['flow'] === 'agent') {
                require_once __DIR__ . '/telegram_flow_agent.php';
                FlowAgent::handleReply($chatId, $user, $text, $conv);
                return;
            }
        }

        // 6. Utente registrato — dispatcha comandi
        self::dispatch($chatId, $user, $text);
    }

    private static function cmdStart(int $chatId, ?array $user, string $fromName, ?string $username): void
    {
        if ($user) {
            TG::sendMessage($chatId, "Ciao <b>" . htmlspecialchars($user['name']) . "</b> 👋\nSei già registrato. Scrivi /help per vedere cosa posso fare.");
            return;
        }

        TG::sendMessage(
            $chatId,
            "👋 Benvenut* nel bot di <b>Liste Telemarketing AI</b>.\n\n"
          . "Per attivare il tuo account, rispondi a questo messaggio con la tua <b>email aziendale</b>."
          . (($fromName !== '') ? "\n\n(Nome Telegram: <i>" . htmlspecialchars($fromName) . "</i>)" : '')
        );
        TGState::set($chatId, 'awaiting_email', ['tg_username' => $username, 'tg_name' => $fromName]);
    }

    private static function completeRegistration(int $chatId, string $email, ?string $username): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            TG::sendMessage($chatId, "❌ Email non valida. Riprova.");
            return;
        }

        $pdo = remoteDb('backoffice');
        $stmt = $pdo->prepare("SELECT id, name, role, active FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$u) {
            TG::sendMessage($chatId, "❌ Email <b>" . htmlspecialchars($email) . "</b> non trovata nel sistema. Contatta un amministratore.");
            self::notifyAdmins("🚨 Tentativo registrazione Telegram con email sconosciuta: <b>" . htmlspecialchars($email) . "</b>\nchat_id: $chatId");
            TGState::clear($chatId);
            return;
        }
        if ((int)$u['active'] !== 1) {
            TG::sendMessage($chatId, "❌ Il tuo account è disattivato. Contatta un amministratore.");
            TGState::clear($chatId);
            return;
        }

        // Verifica che nessun altro abbia già registrato questa chat
        $check = $pdo->prepare("SELECT id, email FROM users WHERE telegram_chat_id = ? AND id != ?");
        $check->execute([$chatId, $u['id']]);
        if ($check->fetch()) {
            TG::sendMessage($chatId, "❌ Questo account Telegram risulta già associato a un altro utente.");
            TGState::clear($chatId);
            return;
        }

        $pdo->prepare("UPDATE users SET telegram_chat_id = ?, telegram_username = ?, telegram_registered_at = NOW() WHERE id = ?")
            ->execute([$chatId, $username, $u['id']]);

        TGState::clear($chatId);
        $welcome = "✅ Registrazione completata!\n\n"
                 . "Nome: <b>" . htmlspecialchars($u['name']) . "</b>\n"
                 . "Role: <b>" . htmlspecialchars($u['role']) . "</b>\n\n"
                 . "Scrivi /help per vedere i comandi disponibili.";
        TG::sendMessage($chatId, $welcome);

        self::notifyAdmins("✅ Nuova registrazione Telegram:\n<b>" . htmlspecialchars($u['name']) . "</b> (" . htmlspecialchars($email) . ", role=" . $u['role'] . ")\nchat_id: $chatId · @" . ($username ?: '—'));
    }

    private static function dispatch(int $chatId, array $user, string $text): void
    {
        // Parsing comandi
        if (preg_match('/^\/(\w+)(?:\s+(.*))?$/s', $text, $m)) {
            $cmd  = strtolower($m[1]);
            $args = trim($m[2] ?? '');

            switch ($cmd) {
                case 'help':    self::cmdHelp($chatId, $user); return;
                case 'stato':   self::cmdStato($chatId, $user); return;
                case 'chi':     self::cmdChi($chatId, $user); return;
                case 'logout':  self::cmdLogout($chatId, $user); return;
                case 'utenti':  self::cmdUtenti($chatId, $user); return;
                case 'estrai':  FlowEstrai::start($chatId, $user, $args); return;
                case 'stat':
                case 'stats':
                case 'statistica':
                case 'statistiche':
                    FlowEstrai::start($chatId, $user, 'statistica ' . $args); return;
                case 'storico':
                case 'ordini':
                case 'acquisti':
                    FlowEstrai::start($chatId, $user, 'storico ' . $args); return;
                case 'statsalvate':
                case 'statsaved':
                case 'stat_list':
                    FlowStats::listStats($chatId, $args ?: null, 15); return;
                case 'costi':
                case 'tokens':
                case 'cost':
                    self::cmdCosti($chatId, $user, $args); return;
                case 'vedistat':
                case 'stat_view':
                case 'showstat':
                    $id = (int)trim($args);
                    if ($id > 0) FlowStats::viewStat($chatId, $id);
                    else TG::sendMessage($chatId, "Uso: <code>/vedistat &lt;id&gt;</code>");
                    return;
                case 'annulla': FlowEstrai::clearState($chatId); TG::sendMessage($chatId, "❎ Annullato."); return;
                case 'magazzino_reset':
                case 'magazzinoreset':
                case 'magreset':
                    require_once __DIR__ . '/telegram_flow_magazzino.php';
                    FlowMagazzino::opReset($chatId, $user, trim($args));
                    return;
                case 'magazzino_change':
                case 'magazzinochange':
                case 'cambiamagazzino':
                    require_once __DIR__ . '/telegram_flow_magazzino.php';
                    FlowMagazzino::opChange($chatId, $user, trim($args));
                    return;
                case 'magazzini':
                    require_once __DIR__ . '/telegram_flow_magazzino.php';
                    FlowMagazzino::opList($chatId);
                    return;
            }
        }

        // Testo libero → AGENT CONVERSAZIONALE (AI-driven)
        require_once __DIR__ . '/telegram_flow_agent.php';
        FlowAgent::start($chatId, $user, $text);
    }

    private static function cmdHelp(int $chatId, array $user): void
    {
        $isAdmin = ($user['role'] === 'admin');
        $msg  = "📋 <b>Comandi disponibili</b>\n\n";
        $msg .= "/chi — info sul tuo account\n";
        $msg .= "/stato — ultime consegne registrate\n";
        $msg .= "/estrai &lt;richiesta&gt; — nuova estrazione lista (es. <code>/estrai 2000 depurazione Cerullo Milano</code>)\n";
        $msg .= "/stat &lt;richiesta&gt; — statistica disponibilità (es. <code>/stat lombardia per provincia per cerullo depurazione</code>)\n";
        $msg .= "/storico &lt;cliente&gt; — cosa ha acquistato il cliente (es. <code>/storico ediwater</code>)\n";
        $msg .= "/statsalvate [cliente] — elenco stat salvate (filtrabile per cliente)\n";
        $msg .= "/vedistat &lt;id&gt; — richiama una stat salvata per ID\n";
        $msg .= "/annulla — annulla una conversazione in corso\n";
        $msg .= "/magazzini — lista mappature magazzino memorizzate\n";
        $msg .= "/magazzino_reset &lt;cliente&gt; — rimuove la mappatura salvata (al prossimo giro richiedo A/B/C)\n";
        $msg .= "/magazzino_change &lt;cliente&gt; — cambia interattivamente la tabella magazzino associata\n";
        $msg .= "<i>Oppure in linguaggio naturale: «cambia magazzino di Cerullo» · «togli il magazzino di Ediwater»</i>\n";
        $msg .= "/logout — disattiva Telegram per questo account\n";
        if ($isAdmin) {
            $msg .= "\n👑 <b>Admin</b>\n";
            $msg .= "/utenti — elenco utenti registrati su Telegram\n";
        }
        TG::sendMessage($chatId, $msg);
    }

    private static function cmdChi(int $chatId, array $user): void
    {
        $msg  = "👤 <b>" . htmlspecialchars($user['name']) . "</b>\n";
        $msg .= "Email: " . htmlspecialchars($user['email']) . "\n";
        $msg .= "Role: " . htmlspecialchars($user['role']) . "\n";
        $msg .= "User ID: " . $user['id'];
        TG::sendMessage($chatId, $msg);
    }

    private static function cmdLogout(int $chatId, array $user): void
    {
        $pdo = remoteDb('backoffice');
        $pdo->prepare("UPDATE users SET telegram_chat_id = NULL, telegram_username = NULL WHERE id = ?")
            ->execute([$user['id']]);
        TG::sendMessage($chatId, "👋 Fatto. Il tuo account Telegram è stato disattivato. Scrivi /start per riassociarti.");
    }

    private static function cmdStato(int $chatId, array $user): void
    {
        $pdo = remoteDb('ai_laboratory');
        $isAdmin = ($user['role'] === 'admin');
        $sql = "SELECT id, sent_at, cliente_nome, prodotto, contatti_inviati, prezzo_eur FROM deliveries ORDER BY id DESC LIMIT 10";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { TG::sendMessage($chatId, "Nessuna consegna registrata."); return; }

        $msg = "📊 <b>Ultime 10 consegne</b>\n\n";
        foreach ($rows as $r) {
            $msg .= "#" . $r['id'] . " · " . substr($r['sent_at'], 0, 16) . "\n";
            $msg .= "  " . htmlspecialchars($r['cliente_nome']) . " → " . (int)$r['contatti_inviati'] . " · " . $r['prodotto'] . " · €" . number_format($r['prezzo_eur'], 2) . "\n";
        }
        TG::sendMessage($chatId, $msg);
    }

    private static function cmdUtenti(int $chatId, array $user): void
    {
        if ($user['role'] !== 'admin') { TG::sendMessage($chatId, "❌ Solo admin."); return; }
        $pdo = remoteDb('backoffice');
        $rows = $pdo->query("SELECT id, name, email, role, telegram_chat_id, telegram_username, telegram_registered_at FROM users WHERE telegram_chat_id IS NOT NULL ORDER BY role DESC, id")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { TG::sendMessage($chatId, "Nessun utente registrato via Telegram."); return; }
        $msg = "👥 <b>Utenti Telegram (" . count($rows) . ")</b>\n\n";
        foreach ($rows as $r) {
            $msg .= ($r['role'] === 'admin' ? '👑 ' : '👤 ') . htmlspecialchars($r['name']) . "\n";
            $msg .= "   " . htmlspecialchars($r['email']) . "\n";
            $msg .= "   chat: " . $r['telegram_chat_id'] . ($r['telegram_username'] ? ' · @' . htmlspecialchars($r['telegram_username']) : '') . "\n";
        }
        TG::sendMessage($chatId, $msg);
    }

    private static function cmdCosti(int $chatId, array $user, string $args): void
    {
        $pdo = remoteDb('ai_laboratory');
        $msg = "💰 <b>Costi Claude API</b>\n\n";

        // Oggi
        $today = $pdo->query("SELECT COUNT(*) n, SUM(input_tokens) in_tok, SUM(output_tokens) out_tok, ROUND(SUM(cost_usd),4) cost FROM queries WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC);
        $msg .= "<b>Oggi</b>: " . ($today['n'] ?: 0) . " chiamate · in " . number_format((int)($today['in_tok'] ?: 0)) . " / out " . number_format((int)($today['out_tok'] ?: 0)) . " tokens · <b>\$" . number_format((float)($today['cost'] ?: 0), 4) . "</b>\n\n";

        // Ultimi 7 giorni
        $msg .= "<b>Ultimi 7 giorni</b>:\n";
        $rows = $pdo->query("SELECT DATE(created_at) d, COUNT(*) n, ROUND(SUM(cost_usd),4) cost FROM queries WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY d ORDER BY d DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $msg .= "  " . $r['d'] . " · " . $r['n'] . " call · \$" . number_format((float)$r['cost'], 4) . "\n";
        if (!$rows) $msg .= "  <i>nessun dato</i>\n";

        // Breakdown per tipo oggi
        $msg .= "\n<b>Per tipo (oggi)</b>:\n";
        $byType = $pdo->query("SELECT user_name, COUNT(*) n, ROUND(SUM(cost_usd),4) cost FROM queries WHERE DATE(created_at) = CURDATE() GROUP BY user_name ORDER BY cost DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($byType as $r) $msg .= "  " . $r['user_name'] . " · " . $r['n'] . " · \$" . number_format((float)$r['cost'], 4) . "\n";
        if (!$byType) $msg .= "  <i>nessuna chiamata oggi</i>\n";

        TG::sendMessage($chatId, $msg);
    }

    private static function cmdMagazzinoReset(int $chatId, array $user, string $args): void
    {
        require_once __DIR__ . '/estrai_engine.php';
        // args può essere: <id_cliente> oppure <hint> testuale
        $arg = trim($args);
        if ($arg === '') {
            TG::sendMessage($chatId, "Uso: <code>/magazzino_reset &lt;nome_o_PIVA_cliente&gt;</code>\nEsempio: <code>/magazzino_reset cerullo</code>");
            return;
        }
        if (ctype_digit($arg)) {
            EstraiEngine::resetMagazzinoSalvato((int)$arg);
            TG::sendMessage($chatId, "✓ Reset magazzino per cliente ID $arg. La prossima estrazione chiederà di nuovo.");
            return;
        }
        $matches = EstraiEngine::findClienti($arg, 5);
        if (!$matches) { TG::sendMessage($chatId, "Nessun cliente trovato per \"" . htmlspecialchars($arg) . "\""); return; }
        if (count($matches) > 1) {
            $m = "Trovati più clienti, specifica l'ID:\n";
            foreach ($matches as $c) $m .= "• " . $c['id'] . " — " . htmlspecialchars($c['ragione_sociale'] ?: ($c['nome'].' '.$c['cognome'])) . "\n";
            TG::sendMessage($chatId, $m . "\nUsa <code>/magazzino_reset &lt;id&gt;</code>");
            return;
        }
        EstraiEngine::resetMagazzinoSalvato((int)$matches[0]['id']);
        TG::sendMessage($chatId, "✓ Reset magazzino per <b>" . htmlspecialchars($matches[0]['ragione_sociale'] ?: ($matches[0]['nome'].' '.$matches[0]['cognome'])) . "</b>. La prossima estrazione chiederà di nuovo.");
    }

    private static function cmdMagazziniList(int $chatId, array $user): void
    {
        $pdo = remoteDb('ai_laboratory');
        $rows = $pdo->query("SELECT cm.cliente_id, cm.magazzino_tabella, cm.chosen_at
                             FROM cliente_magazzino cm
                             ORDER BY cm.chosen_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { TG::sendMessage($chatId, "Nessuna mappatura memorizzata."); return; }

        $back = remoteDb('backoffice');
        $msg = "🗄 <b>Mappature magazzino memorizzate</b>\n\n";
        foreach ($rows as $r) {
            $c = $back->prepare("SELECT ragione_sociale, nome, cognome FROM clientes WHERE id=?");
            $c->execute([$r['cliente_id']]);
            $cli = $c->fetch(PDO::FETCH_ASSOC);
            $nome = $cli['ragione_sociale'] ?: trim(($cli['nome'] ?? '').' '.($cli['cognome'] ?? ''));
            $mag  = $r['magazzino_tabella'] ? '<code>'.htmlspecialchars($r['magazzino_tabella']).'</code>' : '<i>nessuno</i>';
            $msg .= "• <b>" . htmlspecialchars($nome ?: ('ID '.$r['cliente_id'])) . "</b> → " . $mag . "\n";
        }
        TG::sendMessage($chatId, $msg);
    }

    private static function cmdEstraiPlaceholder(int $chatId, array $user, string $args): void
    {
        TG::sendMessage($chatId,
            "🛠 Il comando <b>/estrai</b> sarà collegato a Claude nella prossima iterazione.\n\n"
          . "Esempio futuro:\n<code>/estrai 2000 depurazione Cerullo provincia Milano no stranieri</code>"
        );
    }

    // === Utility ===
    private static function findUserByChatId(int $chatId): ?array
    {
        $pdo = remoteDb('backoffice');
        $s = $pdo->prepare("SELECT id, name, email, role, active FROM users WHERE telegram_chat_id = ? LIMIT 1");
        $s->execute([$chatId]);
        $u = $s->fetch(PDO::FETCH_ASSOC);
        return $u ?: null;
    }

    private static function notifyAdmins(string $msg): void
    {
        $pdo = remoteDb('backoffice');
        $q = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE role='admin' AND telegram_chat_id IS NOT NULL");
        $q->execute();
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $adminChat) {
            TG::sendMessage((int)$adminChat, "🔔 <b>[ADMIN]</b>\n" . $msg);
        }
    }
}
