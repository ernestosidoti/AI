<?php
/**
 * Importer — carica comuni italiani con popolazione in ai_laboratory.comuni_popolazione
 * Fonte: https://github.com/matteocontrini/comuni-json
 * Uso: php tools/import_comuni.php
 */

define('AILAB', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/db.php';

$url = 'https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json';

echo "⬇️  Scarico dataset comuni da GitHub...\n";
$json = file_get_contents($url);
if (!$json) { echo "❌ Errore download.\n"; exit(1); }
echo "✓ " . number_format(strlen($json)) . " bytes scaricati\n";

$comuni = json_decode($json, true);
if (!$comuni) { echo "❌ JSON non valido\n"; exit(1); }
echo "✓ " . count($comuni) . " comuni nel dataset\n";

$db = aiDb();

echo "🗑️  Svuoto tabella esistente...\n";
$db->exec("TRUNCATE TABLE comuni_popolazione");

echo "📥 Import in corso...\n";
$db->beginTransaction();

$stmt = $db->prepare("INSERT INTO comuni_popolazione
    (codice_istat, nome, nome_upper, sigla_provincia, provincia_nome, regione, zona, popolazione, cap_list, codice_catastale)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$count = 0;
$skip = 0;
foreach ($comuni as $c) {
    try {
        $cap = is_array($c['cap'] ?? null) ? implode(',', $c['cap']) : (string)($c['cap'] ?? '');
        $stmt->execute([
            $c['codice'] ?? '',
            $c['nome'] ?? '',
            mb_strtoupper($c['nome'] ?? '', 'UTF-8'),
            $c['sigla'] ?? '',
            $c['provincia']['nome'] ?? '',
            $c['regione']['nome'] ?? '',
            $c['zona']['nome'] ?? null,
            (int)($c['popolazione'] ?? 0),
            $cap ?: null,
            $c['codiceCatastale'] ?? null,
        ]);
        $count++;
    } catch (\Throwable $e) {
        $skip++;
    }
}

$db->commit();
echo "✓ Importati: $count · Saltati: $skip\n";

// Statistiche post-import
echo "\n📊 STATISTICHE:\n";
$stats = $db->query("SELECT COUNT(*) AS tot, SUM(popolazione) AS pop_tot,
    MIN(popolazione) AS pop_min, MAX(popolazione) AS pop_max,
    AVG(popolazione) AS pop_avg FROM comuni_popolazione")->fetch();
printf("  Totale comuni:  %s\n", number_format($stats['tot']));
printf("  Pop. totale:    %s abitanti\n", number_format($stats['pop_tot']));
printf("  Comune più piccolo: %s ab.\n", number_format($stats['pop_min']));
printf("  Comune più grande:  %s ab.\n", number_format($stats['pop_max']));
printf("  Media:          %s ab.\n", number_format($stats['pop_avg']));

echo "\n📍 Distribuzione per soglia popolazione:\n";
$soglie = [1000, 5000, 10000, 20000, 50000, 100000];
foreach ($soglie as $s) {
    $n = (int)$db->query("SELECT COUNT(*) FROM comuni_popolazione WHERE popolazione < $s")->fetchColumn();
    printf("  < %s ab:  %s comuni\n", number_format($s), number_format($n));
}

echo "\n✅ Import completato!\n";
