<?php
/**
 * POST /ai/api/analisi_estrai.php
 * Body: JSON con filtri + limit
 * Risposta: { ok, files: [{target, count, xlsx_url}] }
 */
define('AILAB', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Analisi.php';

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?: [];
    $filters = $payload['filters'] ?? $payload;
    $limit   = max(1, min(500000, (int)($payload['limit'] ?? 5000)));

    $outDir = __DIR__ . '/../storage/extractions';
    if (!is_dir($outDir)) @mkdir($outDir, 0775, true);

    $t0 = microtime(true);
    $r = Analisi::extract($filters, $limit, $outDir);

    // Convert each CSV → XLSX via openpyxl
    foreach ($r['files'] as &$f) {
        $xlsx = preg_replace('/\.csv$/', '.xlsx', $f['csv_path']);
        $script = csvToXlsxScript($f['csv_path'], $xlsx);
        $py = tempnam(sys_get_temp_dir(), 'xlsx_') . '.py';
        file_put_contents($py, $script);
        exec('python3 ' . escapeshellarg($py) . ' 2>&1', $out, $rc);
        @unlink($py);
        if ($rc === 0 && is_file($xlsx)) {
            @unlink($f['csv_path']);
            $f['xlsx_path'] = $xlsx;
            $f['xlsx_name'] = basename($xlsx);
            $f['xlsx_url']  = '/ai/storage/extractions/' . basename($xlsx);
            $f['size_kb']   = round(filesize($xlsx) / 1024);
            unset($f['csv_path']);
        } else {
            $f['error'] = 'xlsx convert failed: ' . implode("\n", $out);
        }
    }
    unset($f);

    $r['ok'] = true;
    $r['elapsed_ms'] = (int)((microtime(true) - $t0) * 1000);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

function csvToXlsxScript(string $csv, string $xlsx): string
{
    $csvE  = addslashes($csv);
    $xlsxE = addslashes($xlsx);
    return <<<PY
import csv, sys
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment
from openpyxl.utils import get_column_letter

wb = Workbook()
ws = wb.active
ws.title = "Estrazione"

with open("{$csvE}", newline="", encoding="utf-8") as f:
    rdr = csv.reader(f)
    for r in rdr: ws.append(r)

hdr_font = Font(bold=True, color="FFFFFF")
hdr_fill = PatternFill(start_color="305496", end_color="305496", fill_type="solid")
for c in ws[1]:
    c.font = hdr_font; c.fill = hdr_fill; c.alignment = Alignment(horizontal="center", vertical="center")

# auto width semplice
for col in range(1, ws.max_column + 1):
    ws.column_dimensions[get_column_letter(col)].width = 18
ws.freeze_panes = "A2"
ws.auto_filter.ref = ws.dimensions
wb.save("{$xlsxE}")
PY;
}
