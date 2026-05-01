<?php
/**
 * GET /ai/api/analisi_magazzini.php
 * Ritorna la lista dei magazzini (tabelle clienti.*) disponibili.
 * Per ora niente auth.
 */
define('AILAB', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Analisi.php';

try {
    $list = Analisi::listMagazzini(500);
    echo json_encode(['ok' => true, 'magazzini' => $list], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
