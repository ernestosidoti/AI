<?php
/**
 * POST multipart/form-data /ai/api/analisi_upload_magazzino.php
 * Campo: file (xlsx o csv)
 * Risposta: { ok, magazzino_key: "tmp.xxx", count: N }
 *
 * Auto-detection colonna telefono: tel|mobile|cellulare|numero|telefono|recapito
 */
define('AILAB', true);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Analisi.php';

try {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Nessun file ricevuto o errore upload');
    }

    $tmpFile = $_FILES['file']['tmp_name'];
    $orig = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

    $tels = [];
    if ($ext === 'csv') {
        $fp = fopen($tmpFile, 'r');
        if (!$fp) throw new RuntimeException('Impossibile aprire CSV');
        $header = fgetcsv($fp);
        if (!$header) throw new RuntimeException('CSV vuoto');
        $colIdx = detectTelColumn($header);
        if ($colIdx === null) throw new RuntimeException('Colonna telefono non rilevata. Header: ' . implode(', ', $header));
        while ($r = fgetcsv($fp)) {
            $t = trim($r[$colIdx] ?? '');
            if ($t !== '') $tels[] = $t;
        }
        fclose($fp);
    } elseif ($ext === 'xlsx') {
        // Usa Python openpyxl per leggere
        $tmpCsv = tempnam(sys_get_temp_dir(), 'mag_') . '.csv';
        $py = <<<PY
import sys
from openpyxl import load_workbook
import csv
wb = load_workbook(sys.argv[1], read_only=True, data_only=True)
ws = wb.active
with open(sys.argv[2], 'w', newline='', encoding='utf-8') as out:
    w = csv.writer(out)
    for row in ws.iter_rows(values_only=True):
        w.writerow([str(c) if c is not None else '' for c in row])
PY;
        $pyFile = tempnam(sys_get_temp_dir(), 'rd_') . '.py';
        file_put_contents($pyFile, $py);
        exec('python3 ' . escapeshellarg($pyFile) . ' ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($tmpCsv) . ' 2>&1', $out, $rc);
        @unlink($pyFile);
        if ($rc !== 0) throw new RuntimeException('Errore conversione xlsx: ' . implode("\n", $out));

        $fp = fopen($tmpCsv, 'r');
        $header = fgetcsv($fp);
        if (!$header) throw new RuntimeException('xlsx vuoto');
        $colIdx = detectTelColumn($header);
        if ($colIdx === null) throw new RuntimeException('Colonna telefono non rilevata. Header: ' . implode(', ', $header));
        while ($r = fgetcsv($fp)) {
            $t = trim($r[$colIdx] ?? '');
            if ($t !== '') $tels[] = $t;
        }
        fclose($fp);
        @unlink($tmpCsv);
    } else {
        throw new RuntimeException("Formato non supportato: $ext. Usa csv o xlsx");
    }

    if (!$tels) throw new RuntimeException('Nessun numero estratto dal file');

    $magKey = Analisi::createTmpMagazzino($tels);
    echo json_encode([
        'ok' => true,
        'magazzino_key' => $magKey,
        'count' => count($tels),
        'filename' => $orig,
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

/** Trova l'indice della colonna telefono in base al nome */
function detectTelColumn(array $header): ?int
{
    $patterns = ['tel','telefono','telephone','mobile','cellulare','cell','numero','recapito','phone','number'];
    foreach ($header as $i => $h) {
        $hLow = strtolower(trim($h));
        foreach ($patterns as $p) {
            if ($hLow === $p || strpos($hLow, $p) !== false) return $i;
        }
    }
    // Fallback: prima colonna interamente numerica
    return null;
}
