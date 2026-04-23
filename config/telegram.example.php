<?php
/**
 * Template configurazione Bot Telegram.
 * COPIA questo file in config/telegram.php e compila i valori.
 * config/telegram.php è in .gitignore — NON committarlo.
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

// Token ottenuto da @BotFather
define('TG_BOT_TOKEN',    'REPLACE_WITH_YOUR_BOT_TOKEN');
define('TG_BOT_USERNAME', 'your_bot_username');

// Email admin (match con backoffice.users.email dove role='admin')
define('TG_ADMIN_EMAILS', ['admin@your-domain.com']);

// Durata massima esecuzione poller (0 = infinito)
define('TG_POLLER_MAX_SECONDS', 0);

// Timeout long-polling Telegram (secondi)
define('TG_POLL_TIMEOUT', 25);
