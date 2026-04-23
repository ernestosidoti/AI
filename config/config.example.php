<?php
/**
 * LTM AI LABORATORY — Template configurazione
 *
 * COPIA questo file in config/config.php e compila i valori.
 * config/config.php è in .gitignore — NON committarlo mai.
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

// Database MySQL remoto
define('AI_DB_HOST', 'your.mysql.host');
define('AI_DB_PORT', 3306);
define('AI_DB_USER', 'username');
define('AI_DB_PASS', 'REPLACE_WITH_YOUR_PASSWORD');

// Database dedicato (settings, logs, queries del bot)
define('AI_DB_NAME', 'ai_laboratory');

// Auth admin — genera con: php -r "echo password_hash('TUA_PWD', PASSWORD_BCRYPT);"
define('AI_ADMIN_PASSWORD_HASH', 'REPLACE_WITH_BCRYPT_HASH');
define('AI_SESSION_TIMEOUT', 8 * 3600);

define('AI_ROOT', dirname(__DIR__));
define('AI_DOWNLOADS_DIR', AI_ROOT . '/downloads');

// Email mittente per comunicazioni ai clienti
define('AI_SENDER_EMAIL', 'info@your-domain.com');
define('AI_SENDER_NAME', 'Your Company Name');

// DB backoffice (ordini/fatture)
define('AI_BACKOFFICE_DB', 'backoffice');

// TEST MODE email: se set, tutte le email vanno a questo indirizzo.
// Stringa vuota / null per disattivare (produzione).
define('AI_EMAIL_TEST_OVERRIDE', 'test@your-domain.com');

// TEST MODE magazzino: true = legge storico ma NON inserisce dopo delivery.
// false in produzione.
define('AI_MAGAZZINO_SKIP_INSERT', true);

define('AI_BASE_URL', '/ai');
