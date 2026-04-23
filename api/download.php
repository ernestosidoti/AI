<?php
/**
 * API — Download file
 */
define('AILAB', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

aiSecurityHeaders();

if (!aiIsAuthenticated()) {
    http_response_code(403);
    exit('Non autorizzato');
}

$queryId = (int)($_GET['id'] ?? 0);
if (!$queryId) { http_response_code(400); exit('ID mancante'); }

$db = aiDb();
$stmt = $db->prepare("SELECT file_path FROM queries WHERE id = ?");
$stmt->execute([$queryId]);
$filename = $stmt->fetchColumn();

if (!$filename) { http_response_code(404); exit('File non trovato'); }

$filepath = AI_DOWNLOADS_DIR . '/' . basename($filename);
if (!file_exists($filepath)) { http_response_code(404); exit('File cancellato'); }

$db->prepare("UPDATE queries SET status = 'downloaded' WHERE id = ?")->execute([$queryId]);

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mime = $ext === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
