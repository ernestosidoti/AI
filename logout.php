<?php
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/auth.php';
aiLogout();
header('Location: ' . AI_BASE_URL . '/login.php');
exit;
