<?php
/**
 * Telegram Long-Polling — lancia da CLI:
 *   /Applications/MAMP/bin/php/php8.3.14/bin/php /Applications/MAMP/htdocs/ai/tools/telegram_poller.php
 *
 * Ciclo infinito che chiama getUpdates con long-polling (25s).
 * Fermare con Ctrl+C.
 */

define('AILAB', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/telegram.php';
require_once __DIR__ . '/../lib/telegram.php';
require_once __DIR__ . '/../lib/telegram_handlers.php';
require_once __DIR__ . '/../lib/TGArchive.php';

// Assicuriamoci che non ci sia webhook attivo (interferisce con polling)
TG::deleteWebhook();

$offsetFile = __DIR__ . '/../storage/telegram_offset.txt';
$eventsLog  = __DIR__ . '/../storage/logs/poller-events.jsonl';
@mkdir(dirname($offsetFile), 0775, true);
@mkdir(dirname($eventsLog), 0775, true);
$offset = is_file($offsetFile) ? (int)trim(file_get_contents($offsetFile)) : 0;

/** Scrive un evento strutturato JSONL (una riga JSON per record) */
function pollerLog(string $path, array $event): void {
    $event['ts'] = date('c');
    @file_put_contents($path, json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

$start = time();
echo "[" . date('H:i:s') . "] 🤖 Telegram poller avviato (offset iniziale: $offset)\n";
echo "Ctrl+C per fermare.\n\n";
pollerLog($eventsLog, ['type'=>'startup', 'pid'=>getmypid(), 'offset'=>$offset]);

$lastCleanupAt = 0;
while (true) {
    if (TG_POLLER_MAX_SECONDS > 0 && (time() - $start) > TG_POLLER_MAX_SECONDS) {
        echo "\n[" . date('H:i:s') . "] ⏰ Timeout max raggiunto, esco.\n";
        break;
    }

    // Cleanup conversazioni stale ogni 60s
    if (time() - $lastCleanupAt >= 60) {
        try {
            $n = FlowEstrai::cleanupStaleConversations(5);
            if ($n > 0) echo "[" . date('H:i:s') . "] 🧹 Chiuse $n conversazioni inattive (>5 min)\n";
        } catch (\Throwable $e) {
            echo "[" . date('H:i:s') . "] 🔥 cleanup error: " . $e->getMessage() . "\n";
        }
        $lastCleanupAt = time();
    }

    $resp = TG::getUpdates($offset, TG_POLL_TIMEOUT);

    if (!($resp['ok'] ?? false)) {
        $err = $resp['error'] ?? json_encode($resp);
        echo "[" . date('H:i:s') . "] ⚠️  Errore getUpdates: $err\n";
        sleep(5);
        continue;
    }

    foreach ($resp['result'] as $update) {
        $uid = (int)$update['update_id'];
        $offset = max($offset, $uid + 1);

        $from   = $update['message']['from'] ?? [];
        $chatId = $update['message']['chat']['id'] ?? null;
        $fullTxt = trim($update['message']['text'] ?? '[non-text]');
        $txtLog = substr($fullTxt, 0, 80);
        $who    = !empty($from['username']) ? '@' . $from['username'] : ($from['first_name'] ?? '?');
        echo "[" . date('H:i:s') . "] 📨 update #$uid from $who: $txtLog\n";

        $t0 = microtime(true);
        $status = 'ok';
        $errInfo = null;
        // Hard cap PHP: se un update impiega >3min, lo script muore e il runner lo riavvia.
        set_time_limit(180);

        // Archivio conversazione: log inbound user→bot
        if ($chatId && $fullTxt !== '[non-text]') {
            $tgUser = null;
            try { $tgUser = TGHandler::findUserByChatId((int)$chatId); } catch (\Throwable $e) {}
            TGArchive::logIn(
                (int)$chatId,
                $tgUser['id'] ?? null,
                $tgUser['name'] ?? ($from['first_name'] ?? ''),
                $tgUser['email'] ?? null,
                $fullTxt,
                ['update_id' => $uid, 'tg_username' => $from['username'] ?? null]
            );
        }

        try {
            TGHandler::handleUpdate($update);
        } catch (\Throwable $e) {
            $status = 'exception';
            $errInfo = [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ];
            echo "[" . date('H:i:s') . "] 🔥 EXCEPTION: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            if ($chatId) {
                try { TGArchive::logSystem((int)$chatId, '🔥 ' . $e->getMessage(), [
                    'class' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine(),
                ]); } catch (\Throwable $e2) {}
            }
        }
        $duration = (int)((microtime(true) - $t0) * 1000);

        pollerLog($eventsLog, [
            'type' => 'update',
            'update_id' => $uid,
            'chat_id' => $chatId,
            'from' => ['id' => $from['id'] ?? null, 'username' => $from['username'] ?? null, 'name' => $from['first_name'] ?? null],
            'text' => mb_substr($fullTxt, 0, 500),
            'duration_ms' => $duration,
            'status' => $status,
            'error' => $errInfo,
        ]);

        file_put_contents($offsetFile, (string)$offset);
    }
}
