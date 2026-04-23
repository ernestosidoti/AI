<?php
/**
 * Auth — login via email+password su backoffice.users
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

// Carica logger (opzionale: se fallisce, auth funziona lo stesso)
if (!function_exists('aiLog') && file_exists(__DIR__ . '/logger.php')) {
    @require_once __DIR__ . '/logger.php';
}

function aiInitSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)AI_SESSION_TIMEOUT);
    session_name('AILAB_SESS');
    session_start();
}

/**
 * Login: verifica credenziali e imposta sessione.
 * Ritorna ['success' => bool, 'error' => string?]
 */
function aiLogin(string $email, string $password): array
{
    $email = strtolower(trim($email));
    if ($email === '' || $password === '') {
        return ['success' => false, 'error' => 'Email e password obbligatori'];
    }

    try {
        $db = remoteDb(AI_BACKOFFICE_DB);
        $stmt = $db->prepare("SELECT id, name, email, password, role, commerciale, active FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => 'Errore di connessione'];
    }

    if (!$user) {
        if (function_exists('aiLogSecurity')) aiLogSecurity('login_failed', "Email inesistente: $email");
        sleep(1);
        return ['success' => false, 'error' => 'Credenziali non valide'];
    }
    if ((int)$user['active'] !== 1) {
        if (function_exists('aiLogSecurity')) aiLogSecurity('login_blocked', "Account disattivato: $email", ['uid' => $user['id']]);
        return ['success' => false, 'error' => 'Account disattivato. Contatta un amministratore.'];
    }
    if (!password_verify($password, $user['password'])) {
        if (function_exists('aiLogSecurity')) aiLogSecurity('login_failed', "Password errata: $email", ['uid' => $user['id']]);
        sleep(1);
        return ['success' => false, 'error' => 'Credenziali non valide'];
    }

    aiInitSession();
    session_regenerate_id(true);
    $_SESSION['ai_uid'] = (int)$user['id'];
    $_SESSION['ai_user'] = $user['email'];
    $_SESSION['ai_name'] = $user['name'];
    $_SESSION['ai_role'] = $user['role'];
    $_SESSION['ai_commerciale'] = (int)$user['commerciale'];
    $_SESSION['ai_expires'] = time() + AI_SESSION_TIMEOUT;
    $_SESSION['ai_csrf'] = bin2hex(random_bytes(32));

    // Aggiorna ultimo_login
    try {
        $db->prepare("UPDATE users SET ultimo_login = NOW() WHERE id = ?")->execute([$user['id']]);
    } catch (\Throwable $e) {}

    if (function_exists('aiLog')) aiLog('auth', 'login_success', "Login OK: {$user['email']}", ['role' => $user['role']]);

    return ['success' => true];
}

function aiIsAuthenticated(): bool
{
    aiInitSession();
    if (empty($_SESSION['ai_uid'])) return false;
    if (empty($_SESSION['ai_expires']) || $_SESSION['ai_expires'] < time()) {
        aiLogout();
        return false;
    }
    $_SESSION['ai_expires'] = time() + AI_SESSION_TIMEOUT;
    return true;
}

function aiLogout(): void
{
    aiInitSession();
    if (!empty($_SESSION['ai_user']) && function_exists('aiLog')) {
        aiLog('auth', 'logout', "Logout: " . $_SESSION['ai_user']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function aiCsrfToken(): string
{
    aiInitSession();
    if (empty($_SESSION['ai_csrf'])) $_SESSION['ai_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['ai_csrf'];
}

function aiVerifyCsrf(string $token): bool
{
    aiInitSession();
    return !empty($_SESSION['ai_csrf']) && hash_equals($_SESSION['ai_csrf'], $token);
}

function aiRequireAuth(): void
{
    if (!aiIsAuthenticated()) {
        header('Location: ' . AI_BASE_URL . '/login.php');
        exit;
    }
}

function aiRequireAdmin(): void
{
    aiRequireAuth();
    if (aiCurrentUserRole() !== 'admin') {
        http_response_code(403);
        exit('Accesso riservato agli amministratori');
    }
}

function aiSecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function aiCurrentUserId(): int
{
    aiInitSession();
    return (int)($_SESSION['ai_uid'] ?? 0);
}

function aiCurrentUser(): string
{
    aiInitSession();
    return $_SESSION['ai_name'] ?? $_SESSION['ai_user'] ?? 'guest';
}

function aiCurrentUserEmail(): string
{
    aiInitSession();
    return $_SESSION['ai_user'] ?? '';
}

function aiCurrentUserRole(): string
{
    aiInitSession();
    return $_SESSION['ai_role'] ?? 'user';
}

function aiCurrentUserIsCommerciale(): bool
{
    aiInitSession();
    return (int)($_SESSION['ai_commerciale'] ?? 0) === 1;
}

/**
 * Crea token reset password (validità 1h)
 * Ritorna il token generato o null se email non trovata.
 */
function aiCreateResetToken(string $email): ?string
{
    $email = strtolower(trim($email));
    try {
        $db = remoteDb(AI_BACKOFFICE_DB);
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND active = 1");
        $stmt->execute([$email]);
        if (!$stmt->fetch()) return null;

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        $db->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE email = ?")
           ->execute([$token, $expires, $email]);
        if (function_exists('aiLogSecurity')) aiLogSecurity('password_reset_request', "Reset richiesto per: $email");
        return $token;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Verifica validità token reset
 * Ritorna array user o null
 */
function aiValidateResetToken(string $token): ?array
{
    try {
        $db = remoteDb(AI_BACKOFFICE_DB);
        $stmt = $db->prepare("SELECT id, name, email FROM users WHERE reset_token = ? AND reset_expires > NOW() AND active = 1");
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

function aiConsumeResetToken(int $userId, string $newPassword): bool
{
    try {
        $db = remoteDb(AI_BACKOFFICE_DB);
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?")
           ->execute([$hash, $userId]);
        if (function_exists('aiLogSecurity')) aiLogSecurity('password_reset_completed', "Password resettata per uid=$userId");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Genera password casuale leggibile (10 caratteri)
 */
function aiGenerateRandomPassword(int $length = 10): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pass = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}
