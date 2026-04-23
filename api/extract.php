<?php
/**
 * API — Esegue SQL e genera XLSX
 */
define('AILAB', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/logger.php';
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

$queryId = (int)($payload['query_id'] ?? 0);
if (!$queryId) {
    echo json_encode(['success' => false, 'error' => 'Query ID mancante']);
    exit;
}

$db = aiDb();
$stmt = $db->prepare("SELECT * FROM queries WHERE id = ?");
$stmt->execute([$queryId]);
$query = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$query) {
    echo json_encode(['success' => false, 'error' => 'Query non trovata']);
    exit;
}

$sql = $query['generated_sql'];
$sqlError = ClaudeAPI::validateSql($sql);
if ($sqlError) {
    echo json_encode(['success' => false, 'error' => 'SQL non valido: ' . $sqlError]);
    exit;
}

try {
    // Connessione raw (no DB specifico, query può essere cross-database)
    $sourceDb = rawDb();

    $startTime = microtime(true);
    $stmt = $sourceDb->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $elapsedMs = round((microtime(true) - $startTime) * 1000);

    // Genera CSV (poi lo convertiamo in XLSX se openpyxl disponibile)
    if (!is_dir(AI_DOWNLOADS_DIR)) mkdir(AI_DOWNLOADS_DIR, 0755, true);

    $basename = 'extract_' . $queryId . '_' . date('Ymd_His');
    $csvPath = AI_DOWNLOADS_DIR . '/' . $basename . '.csv';
    $xlsxPath = AI_DOWNLOADS_DIR . '/' . $basename . '.xlsx';

    // Scrivi CSV temporaneo
    $fp = fopen($csvPath, 'w');
    fwrite($fp, "\xEF\xBB\xBF"); // BOM UTF-8 per Excel
    if (!empty($rows)) {
        fputcsv($fp, array_keys($rows[0]), ';', '"');
        foreach ($rows as $row) {
            $cleaned = array_map(fn($v) => $v === null ? '' : (string)$v, $row);
            fputcsv($fp, $cleaned, ';', '"');
        }
    }
    fclose($fp);

    // Prova conversione a XLSX tramite Python
    $finalFile = $basename . '.csv';
    $escCsv = escapeshellarg($csvPath);
    $escXlsx = escapeshellarg($xlsxPath);
    $pythonScript = <<<PY
import csv, sys
try:
    from openpyxl import Workbook
    from openpyxl.styles import Font, PatternFill, Alignment
    from openpyxl.utils import get_column_letter
except ImportError:
    sys.exit(2)

wb = Workbook()
ws = wb.active
ws.title = "Estrazione"

with open($escCsv, 'r', encoding='utf-8-sig') as f:
    reader = csv.reader(f, delimiter=';')
    for row_idx, row in enumerate(reader, 1):
        for col_idx, cell in enumerate(row, 1):
            ws.cell(row=row_idx, column=col_idx, value=cell)

if ws.max_row > 0:
    header_fill = PatternFill(start_color="1E40AF", end_color="1E40AF", fill_type="solid")
    header_font = Font(bold=True, color="FFFFFF", size=11)
    for cell in ws[1]:
        cell.fill = header_fill
        cell.font = header_font
        cell.alignment = Alignment(horizontal="center", vertical="center")
    for col in ws.columns:
        max_length = 0
        col_letter = get_column_letter(col[0].column)
        for cell in col:
            try:
                if cell.value and len(str(cell.value)) > max_length:
                    max_length = len(str(cell.value))
            except:
                pass
        ws.column_dimensions[col_letter].width = min(max_length + 2, 40)
    ws.freeze_panes = "A2"

wb.save($escXlsx)
print("OK")
PY;

    $tmpPy = tempnam(sys_get_temp_dir(), 'ai_xlsx_');
    file_put_contents($tmpPy, $pythonScript);
    $out = @shell_exec('python3 ' . escapeshellarg($tmpPy) . ' 2>&1');
    @unlink($tmpPy);

    if (trim($out) === 'OK' && file_exists($xlsxPath)) {
        @unlink($csvPath);
        $finalFile = $basename . '.xlsx';
    }

    $stmt = $db->prepare("UPDATE queries SET status = 'executed', records_count = ?,
        file_path = ?, executed_at = NOW() WHERE id = ?");
    $stmt->execute([count($rows), $finalFile, $queryId]);

    // Preview: primi 100 record (+ intestazioni)
    $previewLimit = 100;
    $preview = array_slice($rows, 0, $previewLimit);
    // Sostituisci NULL con stringa vuota
    foreach ($preview as &$r) {
        foreach ($r as $k => $v) { $r[$k] = $v === null ? '' : (string)$v; }
    }
    unset($r);
    $columns = !empty($rows) ? array_keys($rows[0]) : [];

    aiLog('query', 'extraction_success', "Estratti " . count($rows) . " record", [
        'query_id' => $queryId,
        'records' => count($rows),
        'elapsed_ms' => $elapsedMs,
        'filename' => $finalFile,
    ]);

    echo json_encode([
        'success' => true,
        'records_count' => count($rows),
        'elapsed_ms' => $elapsedMs,
        'filename' => $finalFile,
        'columns' => $columns,
        'preview' => $preview,
        'preview_limit' => $previewLimit,
    ]);

} catch (\Throwable $e) {
    $db->prepare("UPDATE queries SET status = 'failed', error_message = ? WHERE id = ?")
        ->execute([$e->getMessage(), $queryId]);
    aiLogError('query', 'extraction_failed', $e->getMessage(), ['query_id' => $queryId]);

    // Messaggi più comprensibili
    $msg = $e->getMessage();
    $friendly = $msg;
    if (preg_match("/Table '([^']+)' doesn't exist/", $msg, $m)) {
        $friendly = "La tabella '{$m[1]}' non esiste nel sistema. Claude ha inventato una fonte dati non disponibile. Riformula la richiesta oppure clicca AFFINA e chiedi di NON usare filtri basati su dati che il sistema non ha (es. popolazione comuni).";
    } elseif (preg_match("/Unknown column '([^']+)'/", $msg, $m)) {
        $friendly = "La colonna '{$m[1]}' non esiste. Claude ha usato un nome di colonna errato. Clicca AFFINA e chiedi di usare solo le colonne disponibili.";
    }

    echo json_encode(['success' => false, 'error' => $friendly, 'raw' => $msg]);
}
