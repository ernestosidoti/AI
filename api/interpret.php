<?php
/**
 * API — Interpret user prompt tramite Claude
 */
define('AILAB', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/TableRegistry.php';
require_once __DIR__ . '/../lib/IntelRegistry.php';
require_once __DIR__ . '/../lib/CostTracker.php';
require_once __DIR__ . '/../lib/ClaudeAPI.php';

header('Content-Type: application/json');
aiSecurityHeaders();

if (!aiIsAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];

if (!aiVerifyCsrf($payload['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'CSRF invalido']);
    exit;
}

$prompt = trim($payload['prompt'] ?? '');
$sources = $payload['sources'] ?? [];
$productCode = trim($payload['product_code'] ?? '');
$parentQueryId = (int)($payload['parent_query_id'] ?? 0);
$clienteId = (int)($payload['cliente_id'] ?? 0);

if (!$prompt) {
    echo json_encode(['success' => false, 'error' => 'Prompt obbligatorio']);
    exit;
}

$db = aiDb();

// Se viene passato un product_code ma non le sources, le risolviamo automaticamente
if ($productCode && empty($sources)) {
    $matching = IntelRegistry::getSourcesForProduct($db, $productCode);
    $sources = array_column($matching, 'source_id');
}

if (empty($sources)) {
    echo json_encode(['success' => false, 'error' => 'Nessuna fonte disponibile per questa richiesta']);
    exit;
}

$apiKey = ClaudeAPI::loadApiKey($db);
if (!$apiKey) {
    echo json_encode(['success' => false, 'error' => 'API key non configurata']);
    exit;
}

// Se è un affinamento, carica la query padre
$parentQuery = null;
if ($parentQueryId > 0) {
    $stmt = $db->prepare("SELECT user_prompt, generated_sql, tables_selected FROM queries WHERE id = ?");
    $stmt->execute([$parentQueryId]);
    $parentQuery = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    // Se le fonti non sono state ripassate, usa quelle del padre
    if ($parentQuery && empty($sources) && !empty($parentQuery['tables_selected'])) {
        $sources = explode(',', $parentQuery['tables_selected']);
    }
}

try {
    $api = new ClaudeAPI($apiKey);
    $response = $api->interpretQuery($prompt, $sources, $parentQuery, $productCode ?: null, $db);
    $result = $response['result'];

    if (isset($result['error'])) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }

    $sql = $result['sql'] ?? '';
    $interpretation = $result['interpretation'] ?? '';
    $estimatedRecords = $result['estimated_records'] ?? '?';

    // Pulizia: rimuovi markdown fence, commenti iniziali, whitespace
    $sql = trim($sql);
    $sql = preg_replace('/^```(?:sql)?\s*/i', '', $sql);
    $sql = preg_replace('/\s*```$/i', '', $sql);
    while (preg_match('/^\s*\/\*[\s\S]*?\*\/\s*/', $sql)) {
        $sql = preg_replace('/^\s*\/\*[\s\S]*?\*\/\s*/', '', $sql);
    }
    while (preg_match('/^\s*--[^\n]*\n/', $sql)) {
        $sql = preg_replace('/^\s*--[^\n]*\n/', '', $sql);
    }
    $sql = trim($sql);

    $sqlError = ClaudeAPI::validateSql($sql);
    if ($sqlError) {
        echo json_encode([
            'success' => false,
            'error' => 'SQL non valido: ' . $sqlError,
            'sql_preview' => substr($sql, 0, 300),
        ]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO queries
        (parent_query_id, user_name, cliente_id, product_code, user_prompt, tables_selected, generated_sql, interpretation, model,
         input_tokens, output_tokens, cost_usd, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'interpreted')");
    $stmt->execute([
        $parentQueryId > 0 ? $parentQueryId : null,
        aiCurrentUser(),
        $clienteId > 0 ? $clienteId : null,
        $productCode ?: null,
        $prompt,
        implode(',', $sources),
        $sql,
        $interpretation,
        $response['model'],
        $response['input_tokens'],
        $response['output_tokens'],
        $response['cost_usd'],
    ]);
    $queryId = $db->lastInsertId();

    aiLog('query', 'claude_interpret', "Claude ha generato SQL per: " . mb_substr($prompt, 0, 120), [
        'query_id' => $queryId,
        'product' => $productCode,
        'cliente_id' => $clienteId,
        'sources' => $sources,
        'tokens_in' => $response['input_tokens'],
        'tokens_out' => $response['output_tokens'],
        'cost_usd' => $response['cost_usd'],
    ]);

    echo json_encode([
        'success' => true,
        'query_id' => $queryId,
        'interpretation' => $interpretation,
        'sql' => $sql,
        'estimated_records' => $estimatedRecords,
        'input_tokens' => $response['input_tokens'],
        'output_tokens' => $response['output_tokens'],
        'cost_usd' => $response['cost_usd'],
    ]);

} catch (\Throwable $e) {
    aiLogError('query', 'claude_error', $e->getMessage(), ['prompt' => mb_substr($prompt, 0, 200)]);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
