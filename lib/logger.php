<?php
/**
 * Logger applicativo — salva in app_logs + file rolling
 * Uso: aiLog('auth', 'login_success', 'Utente loggato', ['uid' => 5]);
 *      aiLog('query', 'claude_interpret', $prompt, $ctx, 'info');
 *      aiLog('system', 'error', $e->getMessage(), null, 'error');
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

function aiLog(string $category, string $action, string $message = '', ?array $context = null, string $level = 'info'): void
{
    $validLevels = ['debug','info','warning','error','critical','security'];
    $validCategories = ['auth','system','query','user','client','order','admin','api'];
    if (!in_array($level, $validLevels, true)) $level = 'info';
    if (!in_array($category, $validCategories, true)) $category = 'system';

    $uid = function_exists('aiCurrentUserId') ? aiCurrentUserId() : null;
    $email = function_exists('aiCurrentUserEmail') ? aiCurrentUserEmail() : null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    try {
        $db = aiDb();
        $stmt = $db->prepare("INSERT INTO app_logs
            (level, category, user_id, user_email, ip, user_agent, action, message, context)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $level, $category,
            $uid ?: null, $email ?: null,
            $ip, $ua,
            substr($action, 0, 100),
            substr($message, 0, 10000),
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (\Throwable $e) {
        // Fallback file se DB non disponibile
        $file = AI_ROOT . '/downloads/_applog_fallback.log';
        $line = sprintf("[%s] %s.%s uid=%s %s | %s\n",
            date('Y-m-d H:i:s'), $level, $category,
            $uid ?? '-', $action, $message);
        @file_put_contents($file, $line, FILE_APPEND);
    }
}

/**
 * Shortcuts
 */
function aiLogInfo(string $cat, string $action, string $msg = '', ?array $ctx = null): void {
    aiLog($cat, $action, $msg, $ctx, 'info');
}
function aiLogWarning(string $cat, string $action, string $msg = '', ?array $ctx = null): void {
    aiLog($cat, $action, $msg, $ctx, 'warning');
}
function aiLogError(string $cat, string $action, string $msg = '', ?array $ctx = null): void {
    aiLog($cat, $action, $msg, $ctx, 'error');
}
function aiLogSecurity(string $action, string $msg = '', ?array $ctx = null): void {
    aiLog('auth', $action, $msg, $ctx, 'security');
}
