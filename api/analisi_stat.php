<?php
/**
 * POST /ai/api/analisi_stat.php
 * Body: JSON con i filtri normalizzati (vedi Analisi::normalizeFilters)
 * Risposta: { ok, total, business?:{}, consumer?:{}, breakdown:{} }
 */
define('AILAB', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Analisi.php';

try {
    $raw = file_get_contents('php://input');
    $f = json_decode($raw, true) ?: [];

    $t0 = microtime(true);
    $r = Analisi::stat($f);
    $r['ok'] = true;
    $r['elapsed_ms'] = (int)((microtime(true) - $t0) * 1000);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
