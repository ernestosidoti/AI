<?php
/**
 * Flow gestione magazzino cliente — change / reset / list.
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/estrai_engine.php';
require_once __DIR__ . '/telegram_flow_estrai.php';

class FlowMagazzino
{
    const S_AWAIT_PICK = 'magazzino_await_pick';  // utente sceglie fra i candidati

    public static function run(int $chatId, array $user, array $intent): void
    {
        $op = $intent['magazzino_op'] ?? null;
        switch ($op) {
            case 'list':   self::opList($chatId); return;
            case 'reset':  self::opReset($chatId, $user, $intent['cliente_hint'] ?? ''); return;
            case 'change': self::opChange($chatId, $user, $intent['cliente_hint'] ?? ''); return;
            default:
                TG::sendMessage($chatId, "🗄 Gestione magazzino — esempi:\n• <i>cambia magazzino di Cerullo</i>\n• <i>togli il magazzino di Ediwater</i>\n• <i>mostrami i magazzini salvati</i>");
        }
    }

    public static function opList(int $chatId): void
    {
        $pdo = remoteDb('ai_laboratory');
        $rows = $pdo->query("SELECT cm.cliente_id, cm.magazzino_tabella, cm.chosen_at
                             FROM cliente_magazzino cm
                             ORDER BY cm.chosen_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { TG::sendMessage($chatId, "📋 Nessun magazzino memorizzato."); return; }

        $back = remoteDb('backoffice');
        $msg = "🗄 <b>Magazzini memorizzati per cliente</b> (" . count($rows) . ")\n\n";
        foreach ($rows as $r) {
            $c = $back->prepare("SELECT ragione_sociale, nome, cognome FROM clientes WHERE id=?");
            $c->execute([$r['cliente_id']]);
            $cli = $c->fetch(PDO::FETCH_ASSOC);
            $nome = $cli ? ($cli['ragione_sociale'] ?: trim(($cli['nome'] ?? '').' '.($cli['cognome'] ?? ''))) : ('ID '.$r['cliente_id']);
            $mag  = $r['magazzino_tabella'] ? '<code>'.htmlspecialchars($r['magazzino_tabella']).'</code>' : '<i>nessuno</i>';
            $msg .= "• <b>" . htmlspecialchars($nome ?: ('ID '.$r['cliente_id'])) . "</b> → " . $mag . "\n";
        }
        $msg .= "\n<i>Per cambiare:</i> <code>cambia magazzino di &lt;cliente&gt;</code>\n<i>Per rimuovere:</i> <code>togli il magazzino di &lt;cliente&gt;</code>";
        TG::sendMessage($chatId, $msg);
        FlowEstrai::mainMenu($chatId);
    }

    public static function opReset(int $chatId, array $user, string $hint): void
    {
        if ($hint === '') { TG::sendMessage($chatId, "Specifica il cliente: <i>togli il magazzino di &lt;nome&gt;</i>"); return; }
        $cands = EstraiEngine::findClienti($hint, 5);
        if (!$cands) { TG::sendMessage($chatId, "❌ Cliente \"" . htmlspecialchars($hint) . "\" non trovato."); return; }
        if (count($cands) > 1) {
            $m = "Più clienti trovati per \"$hint\":\n";
            foreach ($cands as $c) $m .= "• <b>" . htmlspecialchars($c['ragione_sociale'] ?: ($c['nome'].' '.$c['cognome'])) . "</b> · " . ($c['partita_iva'] ?? '-') . "\n";
            $m .= "\nScrivi il nome esatto o la P.IVA.";
            TG::sendMessage($chatId, $m);
            return;
        }
        $cli = $cands[0];
        $saved = EstraiEngine::getMagazzinoSalvato((int)$cli['id']);
        if (!$saved) {
            TG::sendMessage($chatId, "ℹ️ Questo cliente non ha nessuna scelta magazzino memorizzata. Nulla da fare.");
            return;
        }
        EstraiEngine::resetMagazzinoSalvato((int)$cli['id']);
        $nome = $cli['ragione_sociale'] ?: ($cli['nome'].' '.$cli['cognome']);
        $prev = $saved['magazzino_tabella'] ?: '(nessun magazzino)';
        TG::sendMessage($chatId,
            "✓ <b>Mappatura rimossa</b> per <b>" . htmlspecialchars($nome) . "</b>.\n"
          . "Scelta precedente: <code>" . htmlspecialchars($prev) . "</code>\n\n"
          . "La prossima estrazione/stat per questo cliente chiederà di nuovo A/B/C."
        );
        FlowEstrai::mainMenu($chatId);
    }

    public static function opChange(int $chatId, array $user, string $hint): void
    {
        if ($hint === '') { TG::sendMessage($chatId, "Specifica il cliente: <i>cambia magazzino di &lt;nome&gt;</i>"); return; }
        $cands = EstraiEngine::findClienti($hint, 5);
        if (!$cands) { TG::sendMessage($chatId, "❌ Cliente \"" . htmlspecialchars($hint) . "\" non trovato."); return; }
        if (count($cands) > 1) {
            $m = "Più clienti trovati:\n";
            foreach ($cands as $c) $m .= "• <b>" . htmlspecialchars($c['ragione_sociale'] ?: ($c['nome'].' '.$c['cognome'])) . "</b> · " . ($c['partita_iva'] ?? '-') . "\n";
            $m .= "\nScrivi il nome esatto o la P.IVA.";
            TG::sendMessage($chatId, $m);
            return;
        }
        $cli = $cands[0];
        $saved = EstraiEngine::getMagazzinoSalvato((int)$cli['id']);
        $mag   = EstraiEngine::findMagazzini($cli);

        $nome = $cli['ragione_sociale'] ?: ($cli['nome'].' '.$cli['cognome']);
        $msg  = "🗄 <b>" . htmlspecialchars($nome) . "</b>\n";
        if ($saved) {
            $cur = $saved['magazzino_tabella'] ?: '<i>nessuno</i>';
            $msg .= "Attuale: " . (is_string($saved['magazzino_tabella']) ? '<code>'.htmlspecialchars($saved['magazzino_tabella']).'</code>' : $cur) . "\n\n";
        } else {
            $msg .= "Attuale: <i>mai impostato</i>\n\n";
        }

        if (!$mag) {
            $msg .= "⚠️ Nessuna tabella candidata trovata nel DB <code>clienti</code>.\n";
            $msg .= "Scegli:\n<b>B</b> = salva «nessun magazzino»\n<b>X</b> = rimuovi qualsiasi mappatura";
        } else {
            $msg .= "Tabelle candidate (più recenti in alto):\n";
            foreach ($mag as $i => $m) {
                $msg .= ($i+1) . ". <code>" . htmlspecialchars($m['table_name']) . "</code> · " . number_format((int)$m['table_rows']) . " record · creata " . substr($m['create_time'], 0, 10) . "\n";
            }
            $msg .= "\nScegli:\n";
            $msg .= "<b>A</b> = usa la più recente (<code>" . htmlspecialchars($mag[0]['table_name']) . "</code>)\n";
            $msg .= "<b>B</b> = nessun magazzino (cold)\n";
            $msg .= "<b>X</b> = rimuovi qualsiasi mappatura\n";
            $msg .= "Oppure scrivi il <b>numero</b> (1-" . count($mag) . ")";
        }
        TG::sendMessage($chatId, $msg);

        self::saveState($chatId, $user, self::S_AWAIT_PICK, [
            'cliente' => $cli, 'magazzini' => $mag,
        ]);
    }

    /** Handler dello stato S_AWAIT_PICK */
    public static function handleReply(int $chatId, array $user, string $text, array $conv): void
    {
        if (FlowEstrai::checkStopIntent($chatId, $user, $text, $conv)) return;

        $data = $conv['data'];
        $mag = $data['magazzini'];
        $cli = $data['cliente'];
        $ans = strtoupper(trim($text));
        $nome = $cli['ragione_sociale'] ?: ($cli['nome'].' '.$cli['cognome']);

        if (preg_match('/^(annull|esci|stop|back|indietro|\/annulla)$/iu', trim($text))) {
            self::clearState($chatId);
            TG::sendMessage($chatId, "❎ Annullato. Nulla modificato.");
            FlowEstrai::mainMenu($chatId);
            return;
        }

        if ($ans === 'X') {
            EstraiEngine::resetMagazzinoSalvato((int)$cli['id']);
            TG::sendMessage($chatId, "✓ Mappatura magazzino rimossa per <b>" . htmlspecialchars($nome) . "</b>.");
            self::clearState($chatId);
            FlowEstrai::mainMenu($chatId);
            return;
        }

        $chosen = null;
        if ($ans === 'A' && $mag)            $chosen = $mag[0]['table_name'];
        elseif ($ans === 'B')                 $chosen = null;  // null = salva "nessun magazzino"
        elseif (ctype_digit($ans)) {
            $n = (int)$ans;
            if ($n >= 1 && $n <= count($mag)) $chosen = $mag[$n-1]['table_name'];
            else { TG::sendMessage($chatId, "Numero non valido (1-" . count($mag) . ")."); return; }
        } else {
            TG::sendMessage($chatId, "Scegli A / B / X o numero tabella.");
            return;
        }

        EstraiEngine::setMagazzinoSalvato((int)$cli['id'], $chosen, (int)$user['id']);
        $msg = $chosen
            ? "✅ Magazzino aggiornato per <b>" . htmlspecialchars($nome) . "</b>:\n<code>" . htmlspecialchars($chosen) . "</code>"
            : "✅ Salvato «nessun magazzino» per <b>" . htmlspecialchars($nome) . "</b>. Le prossime estrazioni/stat saranno cold.";
        TG::sendMessage($chatId, $msg);
        self::clearState($chatId);
        FlowEstrai::mainMenu($chatId);
    }

    private static function saveState(int $chatId, array $user, string $state, array $data): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("REPLACE INTO tg_conversations (chat_id, user_id, flow, state, data) VALUES (?, ?, 'magazzino', ?, ?)")
            ->execute([$chatId, $user['id'], $state, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    public static function clearState(int $chatId): void
    {
        $pdo = remoteDb('ai_laboratory');
        $pdo->prepare("DELETE FROM tg_conversations WHERE chat_id = ? AND flow = 'magazzino'")->execute([$chatId]);
    }
}
