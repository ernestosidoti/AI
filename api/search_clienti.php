<?php
/**
 * API — Ricerca clienti per autocomplete
 */
define('AILAB', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

header('Content-Type: application/json');
aiSecurityHeaders();

if (!aiIsAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'clienti' => []]);
    exit;
}

try {
    $backDb = remoteDb(AI_BACKOFFICE_DB);
    $like = '%' . $q . '%';
    $stmt = $backDb->prepare("
        SELECT id, ragione_sociale, nome, cognome, partita_iva, codice_fiscale, comune, provincia, email
        FROM clientes
        WHERE ragione_sociale LIKE ? OR nome LIKE ? OR cognome LIKE ?
           OR partita_iva LIKE ? OR codice_fiscale LIKE ? OR comune LIKE ? OR email LIKE ?
        ORDER BY ragione_sociale
        LIMIT 20
    ");
    $stmt->execute([$like, $like, $like, $like, $like, $like, $like]);
    echo json_encode(['success' => true, 'clienti' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
