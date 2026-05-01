<?php
/**
 * Telegram Bot API — wrapper minimale (curl)
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

require_once __DIR__ . '/../config/telegram.php';

class TG
{
    public static function call(string $method, array $params = [], ?string $filePath = null, string $fileField = 'document'): array
    {
        $url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/' . $method;
        $ch  = curl_init();

        // Normalizza a stringhe per http_build_query e per multipart
        $postFields = [];
        foreach ($params as $k => $v) {
            $postFields[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string)$v;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($filePath && is_file($filePath)) {
            // Multipart con file
            $postFields[$fileField] = new CURLFile($filePath, 'application/octet-stream', basename($filePath));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            // NON settare Content-Type manualmente: cURL sceglie multipart/form-data con boundary
        } else {
            // Post form-encoded
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        }

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) return ['ok' => false, 'error' => $err ?: 'curl_exec failed', 'http_code' => $code];
        $json = json_decode($raw, true);
        return $json ?: ['ok' => false, 'error' => 'JSON parse error', 'raw' => $raw, 'http_code' => $code];
    }

    public static function sendMessage(int $chatId, string $text, array $extra = []): array
    {
        $r = self::call('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
        if (class_exists('TGArchive')) TGArchive::logOut($chatId, $text, ['ok' => $r['ok'] ?? null]);
        return $r;
    }

    public static function sendDocument(int $chatId, string $filePath, string $caption = ''): array
    {
        $r = self::call('sendDocument', [
            'chat_id'    => $chatId,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ], $filePath, 'document');
        if (class_exists('TGArchive')) TGArchive::logOut($chatId, "📎 [Documento: " . basename($filePath) . "] " . $caption, ['file' => basename($filePath), 'ok' => $r['ok'] ?? null]);
        return $r;
    }

    public static function sendChatAction(int $chatId, string $action = 'typing'): array
    {
        return self::call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
    }

    /**
     * Long-polling: recupera updates con timeout
     */
    public static function getUpdates(int $offset = 0, int $timeout = 25): array
    {
        return self::call('getUpdates', [
            'offset'          => $offset,
            'timeout'         => $timeout,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ]);
    }

    /** Rimuovi webhook (necessario se si passa al polling) */
    public static function deleteWebhook(): array
    {
        return self::call('deleteWebhook', ['drop_pending_updates' => false]);
    }
}
