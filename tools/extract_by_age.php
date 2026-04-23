#!/usr/bin/env php
<?php
/**
 * Estrazione CLI per ETA (filtro da codice fiscale).
 *
 * Uso:
 *   php tools/extract_by_age.php \
 *        --province=CE,NA,AV,SA \
 *        --eta-min=18 --eta-max=30 \
 *        --target=100000 \
 *        --extra-num=1 \
 *        --cliente-id=610 \
 *        --label="Campania 18-30"
 *
 * Opzioni:
 *   --province     CSV sigle province (obbligatorio). Es: CE,NA,AV,SA
 *   --eta-min      età minima (default 18)
 *   --eta-max      età massima (default 30)
 *   --target       max contatti da estrarre (default 100000)
 *   --extra-num    1/0 — lookup numeri extra da master_cf_numeri (default 1)
 *   --cliente-id   id cliente backoffice per log delivery (default 610 generico)
 *   --label        etichetta descrittiva (default auto da province)
 *   --regione      opzionale, forza regione per index hit su Edicus2021 (auto da prov)
 *   --output-dir   dir output xlsx (default storage/extractions)
 *   --dry-run      non salva xlsx né log delivery
 *
 * Fonti interrogate (entrambe, con dedup per mobile):
 *   1) Edicus_2023_marzo.superpod_2023  (cols: codice_fiscale, nome, cognome, provincia, ecc.)
 *   2) Edicus2021_luglio.SUPERPOD       (cols: CodiceFiscale, NomeCliente, PROVINCIA, anno_cf)
 *
 * Se --extra-num=1: per ogni CF arricchisce con colonne Tel_Extra_1..N
 * (N = max trovato nei risultati) dalla tabella trovacodicefiscale2.master_cf_numeri.
 *
 * Output: xlsx in storage/extractions + riga in ai_laboratory.deliveries.
 */

define("AILAB", true);
require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../lib/db.php";

// ============ Args parsing ============
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? '1';
    }
}
if (empty($args['province'])) {
    fwrite(STDERR, "ERRORE: --province è obbligatorio. Esempio: --province=CE,NA,AV\n");
    exit(1);
}

$provinces  = array_map('strtoupper', array_map('trim', explode(',', $args['province'])));
$etaMin     = (int)($args['eta-min'] ?? 18);
$etaMax     = (int)($args['eta-max'] ?? 30);
$target     = (int)($args['target']  ?? 100000);
$wantExtra  = (int)($args['extra-num'] ?? 1) === 1;
$clienteId  = (int)($args['cliente-id'] ?? 610);
$label      = $args['label'] ?? ('Prov ' . implode('/', $provinces) . ' eta ' . $etaMin . '-' . $etaMax);
$regioneHint= $args['regione'] ?? null;
$outDir     = $args['output-dir'] ?? (__DIR__ . '/../storage/extractions');
$dryRun     = isset($args['dry-run']);

// Deriva regione automaticamente se non fornita (usata per index hit su Edicus2021_luglio)
if (!$regioneHint) {
    $regioneHint = guessRegioneFromProvince($provinces);
}

// Anno nascita da età
$oggi = new DateTime('now');
$annoOggi = (int)$oggi->format('Y');
$annoMin = $annoOggi - $etaMax;   // piu vecchi
$annoMax = $annoOggi - $etaMin;   // piu giovani
// Lista YY (ultimi 2 char) per SUBSTRING su CF (per fonte senza anno_cf)
$yyList = [];
for ($y = $annoMin; $y <= $annoMax; $y++) $yyList[] = sprintf('%02d', $y % 100);

echo "[" . date('H:i:s') . "] === Estrazione CLI per età ===\n";
echo "  Province   : " . implode(', ', $provinces) . "\n";
echo "  Età        : $etaMin - $etaMax (anni nascita $annoMin - $annoMax)\n";
echo "  Target     : $target\n";
echo "  Extra num  : " . ($wantExtra ? 'SI' : 'no') . "\n";
echo "  Cliente    : $clienteId (" . $label . ")\n";
echo "  Regione    : " . ($regioneHint ?: '—') . " (per index hit Edicus2021)\n";
echo "  Dry run    : " . ($dryRun ? 'SI' : 'no') . "\n\n";

$rows = [];
$ph   = implode(',', array_fill(0, count($provinces), '?'));
$yph  = implode(',', array_fill(0, count($yyList), '?'));

// ============ FONTE 1: superpod_2023 (Edicus_2023_marzo) ============
echo "[" . date('H:i:s') . "] FONTE 1: superpod_2023...\n";
$pdo1 = remoteDb('Edicus_2023_marzo');
$pdo1->exec("SET SESSION sql_mode = ''");
$pdo1->exec("SET SESSION max_execution_time = 300000");
$sql1 = "SELECT s.mobile AS Mobile, MAX(s.nome) AS Nome, MAX(s.cognome) AS Cognome,
    MAX(s.codice_fiscale) AS CF, MAX(SUBSTRING(s.codice_fiscale,7,2)) AS YY,
    MAX(s.indirizzo) AS Indirizzo, MAX(s.civico) AS Civico, MAX(s.localita) AS Comune,
    MAX(s.cap) AS CAP, MAX(s.provincia) AS Provincia, MAX(s.regione) AS Regione,
    'superpod_2023' AS Fonte
  FROM `Edicus_2023_marzo`.`superpod_2023` s
  WHERE s.provincia IN ($ph)
    AND LENGTH(s.codice_fiscale) = 16
    AND SUBSTRING(s.codice_fiscale,7,2) IN ($yph)
    AND s.mobile IS NOT NULL AND s.mobile != ''
  GROUP BY s.mobile LIMIT $target";
$stmt1 = $pdo1->prepare($sql1);
$stmt1->execute(array_merge($provinces, $yyList));
while ($r = $stmt1->fetch(PDO::FETCH_ASSOC)) {
    $yy = (int)$r['YY'];
    $r['AnnoNascita'] = ($yy <= 30) ? (2000 + $yy) : (1900 + $yy);
    unset($r['YY']);
    $rows[] = $r;
}
$seen = [];
foreach ($rows as $r) $seen[$r['Mobile']] = true;
echo "[" . date('H:i:s') . "]   -> " . count($rows) . " mobili univoci\n";

// ============ FONTE 2: SUPERPOD (Edicus2021_luglio) ============
$remaining = $target - count($rows);
if ($remaining > 0) {
    echo "[" . date('H:i:s') . "] FONTE 2: SUPERPOD_2021 (target $remaining)...\n";
    $pdo2 = remoteDb('Edicus2021_luglio');
    $pdo2->exec("SET SESSION sql_mode = ''");
    $pdo2->exec("SET SESSION max_execution_time = 600000");
    // Se abbiamo la regione usiamo l'index composto (regione,PROVINCIA,anno_cf,zona)
    $regCond = $regioneHint ? "s.regione = ? AND " : "";
    $regParam = $regioneHint ? [$regioneHint] : [];
    $sql2 = "SELECT s.mobile AS Mobile, s.NomeCliente AS Nome, '' AS Cognome,
        s.CodiceFiscale AS CF, s.anno_cf AS AnnoNascita,
        s.Indirizzo AS Indirizzo, s.Civico AS Civico, s.Localita AS Comune,
        s.CAP AS CAP, s.PROVINCIA AS Provincia, s.regione AS Regione,
        'SUPERPOD_2021' AS Fonte
      FROM `Edicus2021_luglio`.`SUPERPOD` s
      WHERE $regCond s.PROVINCIA IN ($ph)
        AND s.anno_cf BETWEEN ? AND ?
        AND s.mobile IS NOT NULL AND s.mobile != ''
      LIMIT " . ($remaining * 5);
    $stmt2 = $pdo2->prepare($sql2);
    $stmt2->execute(array_merge($regParam, $provinces, [$annoMin, $annoMax]));
    $added = 0;
    while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        if (isset($seen[$r['Mobile']])) continue;
        $seen[$r['Mobile']] = true;
        $rows[] = $r;
        $added++;
        if (count($rows) >= $target) break;
    }
    echo "[" . date('H:i:s') . "]   -> +$added (tot " . count($rows) . ")\n";
}

// ============ LOOKUP numeri extra ============
$maxExtra = 0;
if ($wantExtra && $rows) {
    echo "[" . date('H:i:s') . "] Lookup numeri extra da master_cf_numeri...\n";
    $cfs = array_unique(array_filter(array_column($rows, 'CF')));
    $pdoM = remoteDb('trovacodicefiscale2');
    $pdoM->exec("SET SESSION max_execution_time = 300000");
    $allNums = [];
    $chunks = array_chunk(array_values($cfs), 500);
    $chunkCount = count($chunks);
    $i = 0;
    foreach ($chunks as $chunk) {
        $i++;
        $cph = implode(',', array_fill(0, count($chunk), '?'));
        $q = $pdoM->prepare("SELECT cf, tel, tel_type FROM master_cf_numeri
                             WHERE cf IN ($cph) ORDER BY FIELD(tel_type,'mobile','fisso'), tel");
        $q->execute($chunk);
        while ($n = $q->fetch(PDO::FETCH_ASSOC)) {
            $allNums[$n['cf']][] = $n['tel'];
        }
        if ($i % 10 === 0 || $i === $chunkCount) {
            echo "[" . date('H:i:s') . "]   chunk $i/$chunkCount (CF trovati: " . count($allNums) . ")\n";
        }
    }
    foreach ($rows as &$r) {
        $extra = [];
        if (isset($allNums[$r['CF']])) {
            $seenTel = [$r['Mobile'] => true];
            foreach ($allNums[$r['CF']] as $t) {
                if (isset($seenTel[$t])) continue;
                $seenTel[$t] = true;
                $extra[] = $t;
            }
        }
        $r['_extra'] = $extra;
        if (count($extra) > $maxExtra) $maxExtra = count($extra);
    }
    unset($r);
    $maxExtra = min($maxExtra, 20);   // cap sicurezza
    echo "[" . date('H:i:s') . "] Max numeri extra per contatto: $maxExtra\n";
}

if (!$rows) {
    echo "[" . date('H:i:s') . "] Nessun contatto estratto — esco.\n";
    exit(0);
}

// ============ CSV tmp ============
$baseCols = ['Mobile','Nome','Cognome','CF','AnnoNascita','Indirizzo','Civico','Comune','CAP','Provincia','Regione'];
$extraCols = [];
for ($k = 1; $k <= $maxExtra; $k++) $extraCols[] = "Tel_Extra_$k";
$cols = array_merge($baseCols, $extraCols);

$csvTmp = sys_get_temp_dir() . '/extract_by_age_' . getmypid() . '.csv';
$fp = fopen($csvTmp, 'w');
fputcsv($fp, $cols);
foreach ($rows as $r) {
    $line = [];
    foreach ($baseCols as $c) $line[] = $r[$c] ?? '';
    for ($k = 0; $k < $maxExtra; $k++) $line[] = $r['_extra'][$k] ?? '';
    fputcsv($fp, $line);
}
fclose($fp);

if ($dryRun) {
    echo "[" . date('H:i:s') . "] DRY-RUN — CSV: $csvTmp (" . count($rows) . " righe, " . count($cols) . " cols)\n";
    exit(0);
}

// ============ XLSX output ============
if (!is_dir($outDir)) @mkdir($outDir, 0775, true);
$ts = date('Ymd_His');
$safeLabel = preg_replace('/[^A-Za-z0-9\-_]/', '_', $label);
$xlsxPath = rtrim($outDir, '/') . "/{$safeLabel}_{$ts}.xlsx";
$pyScript = sys_get_temp_dir() . '/make_xlsx_' . getmypid() . '.py';
file_put_contents($pyScript, build_py($csvTmp, $xlsxPath, $label));
$py = exec('which python3 2>/dev/null');
if (!$py) $py = 'python3';
echo "[" . date('H:i:s') . "] Scrivo xlsx...\n";
passthru("$py " . escapeshellarg($pyScript), $ec);
if ($ec !== 0 || !is_file($xlsxPath)) {
    fwrite(STDERR, "Generazione xlsx fallita.\n");
    exit(2);
}
$sizeKb = round(filesize($xlsxPath) / 1024, 1);
echo "[" . date('H:i:s') . "] File: $xlsxPath ($sizeKb KB)\n";

// ============ Log delivery ============
$pdoAi = remoteDb('ai_laboratory');
$stmt = $pdoAi->prepare("INSERT INTO deliveries
  (sent_at, cliente_id, cliente_nome, cliente_email, prodotto, query_ricerca, area, fonte_db, filtri, contatti_inviati, magazzino_tabella, file_path, file_name, prezzo_eur, note)
  VALUES (NOW(), ?, ?, NULL, 'generiche', ?, ?, ?, ?, ?, NULL, ?, ?, 0, ?)");
// Recupera nome cliente
$cnome = 'Cliente generico';
try {
    $qc = remoteDb('backoffice')->prepare("SELECT ragione_sociale, nome, cognome FROM clientes WHERE id = ?");
    $qc->execute([$clienteId]);
    if ($c = $qc->fetch(PDO::FETCH_ASSOC)) {
        $cnome = $c['ragione_sociale'] ?: trim(($c['nome']??'') . ' ' . ($c['cognome']??''));
    }
} catch (\Throwable $e) {}

$stmt->execute([
    $clienteId, $cnome,
    $label,
    'provincia: ' . implode(',', $provinces),
    'Edicus_2023_marzo.superpod_2023 + Edicus2021_luglio.SUPERPOD' . ($wantExtra ? ' + master_cf_numeri' : ''),
    "eta $etaMin-$etaMax (anni $annoMin-$annoMax), no dedup magazzino" . ($wantExtra ? ", +numeri extra ($maxExtra cols)" : ''),
    count($rows),
    $xlsxPath, basename($xlsxPath),
    'Estrazione CLI per età via tools/extract_by_age.php',
]);
$deliveryId = $pdoAi->lastInsertId();
echo "[" . date('H:i:s') . "] Delivery id=$deliveryId registrata\n";
echo "\n✅ PRONTO\n";
echo "   File    : $xlsxPath\n";
echo "   Righe   : " . count($rows) . "\n";
echo "   Delivery: $deliveryId\n";

// ============ Helpers ============
function guessRegioneFromProvince(array $provs): ?string {
    $map = [
        // Campania
        'NA' => 'Campania', 'SA' => 'Campania', 'CE' => 'Campania', 'AV' => 'Campania', 'BN' => 'Campania',
        // Lombardia
        'MI' => 'Lombardia', 'BG' => 'Lombardia', 'BS' => 'Lombardia', 'CO' => 'Lombardia', 'CR' => 'Lombardia',
        'LC' => 'Lombardia', 'LO' => 'Lombardia', 'MN' => 'Lombardia', 'MB' => 'Lombardia', 'PV' => 'Lombardia',
        'SO' => 'Lombardia', 'VA' => 'Lombardia',
        // Lazio
        'RM' => 'Lazio', 'VT' => 'Lazio', 'RI' => 'Lazio', 'LT' => 'Lazio', 'FR' => 'Lazio',
        // Piemonte
        'TO' => 'Piemonte', 'VC' => 'Piemonte', 'NO' => 'Piemonte', 'CN' => 'Piemonte', 'AT' => 'Piemonte',
        'AL' => 'Piemonte', 'BI' => 'Piemonte', 'VB' => 'Piemonte',
        // Sicilia
        'PA' => 'Sicilia', 'CT' => 'Sicilia', 'ME' => 'Sicilia', 'AG' => 'Sicilia', 'CL' => 'Sicilia',
        'EN' => 'Sicilia', 'RG' => 'Sicilia', 'SR' => 'Sicilia', 'TP' => 'Sicilia',
        // Puglia
        'BA' => 'Puglia', 'BT' => 'Puglia', 'BR' => 'Puglia', 'FG' => 'Puglia', 'LE' => 'Puglia', 'TA' => 'Puglia',
        // Veneto
        'VE' => 'Veneto', 'VR' => 'Veneto', 'PD' => 'Veneto', 'TV' => 'Veneto', 'VI' => 'Veneto',
        'BL' => 'Veneto', 'RO' => 'Veneto',
        // Toscana
        'FI' => 'Toscana', 'PI' => 'Toscana', 'LI' => 'Toscana', 'PO' => 'Toscana', 'PT' => 'Toscana',
        'GR' => 'Toscana', 'MS' => 'Toscana', 'SI' => 'Toscana', 'AR' => 'Toscana', 'LU' => 'Toscana',
        // Emilia-Romagna
        'BO' => 'Emilia-Romagna', 'FE' => 'Emilia-Romagna', 'FC' => 'Emilia-Romagna', 'MO' => 'Emilia-Romagna',
        'PR' => 'Emilia-Romagna', 'PC' => 'Emilia-Romagna', 'RA' => 'Emilia-Romagna', 'RE' => 'Emilia-Romagna',
        'RN' => 'Emilia-Romagna',
        // Liguria
        'GE' => 'Liguria', 'SP' => 'Liguria', 'SV' => 'Liguria', 'IM' => 'Liguria',
    ];
    $regs = [];
    foreach ($provs as $p) if (isset($map[$p])) $regs[$map[$p]] = true;
    return count($regs) === 1 ? array_key_first($regs) : null;
}

function build_py(string $csv, string $xlsx, string $title): string {
    $csvE  = addslashes($csv);
    $xlsxE = addslashes($xlsx);
    $titleE = addslashes(mb_substr($title, 0, 30));
    return <<<PY
import csv
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment
from openpyxl.utils import get_column_letter

wb = Workbook()
ws = wb.active
ws.title = "{$titleE}"[:31]

with open("{$csvE}", newline="", encoding="utf-8") as f:
    rdr = csv.reader(f)
    header = next(rdr)
    ws.append(header)
    for row in rdr:
        ws.append(row)

hdr_font = Font(bold=True, color="FFFFFF")
hdr_fill = PatternFill(start_color="305496", end_color="305496", fill_type="solid")
for cell in ws[1]:
    cell.font = hdr_font
    cell.fill = hdr_fill
    cell.alignment = Alignment(horizontal="center", vertical="center")

base_widths = [14, 28, 20, 18, 8, 30, 8, 22, 8, 10, 14]
ec = len(header) - len(base_widths)
widths = base_widths + [14] * max(0, ec)
for i, w in enumerate(widths, 1):
    ws.column_dimensions[get_column_letter(i)].width = w
ws.freeze_panes = "A2"
wb.save("{$xlsxE}")
print("OK")
PY;
}
