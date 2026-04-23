<?php
/**
 * INVIO REALE delivery a Cerullo + registrazione in ai_laboratory.deliveries
 *   - Email interna (report) → upselling@gmail.com (team)
 *   - Email cliente (cliente-facing) → stilecasabenessere@libero.it
 *   - INSERT in ai_laboratory.deliveries
 */

define('AILAB', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/mailer.php';

$xlsx = __DIR__ . '/../downloads/51/stile-acqua-srl_milano_4000_depurazione_v3.xlsx';
if (!is_file($xlsx)) { fwrite(STDERR, "File non trovato: $xlsx\n"); exit(1); }

$TEAM_EMAIL   = 'upselling@gmail.com';
$CLIENT_EMAIL = 'stilecasabenessere@libero.it';
$CLIENT_NAME  = 'Michele Cerullo';

$report = [
    'cliente'      => 'STILE ACQUA SRL',
    'cliente_id'   => 51,
    'contatto'     => $CLIENT_NAME,
    'piva'         => '04572140160',
    'prodotto'     => 'depurazione acqua',
    'area'         => 'Provincia di Milano',
    'fonte_db'     => 'Edicus_2023_marzo.superpod_2023',
    'filtri'       => 'provincia in (MI, Milano) · CF italiani (no Z) · mobile valido',
    'pool'         => 83733,
    'records'      => 4000,
    'comuni'       => '275 distinti',
    'magazzino'    => 'clienti.109_Michele_cerullo_CF',
    'dedup'        => 'anti-join pre-estrazione',
    'insert_info'  => '4000 mobile inseriti · data_lotto 22-04-2026 07.43.02 · moo 3.210.885 → 3.214.884',
    'send_to'      => $CLIENT_EMAIL . ' (' . $CLIENT_NAME . ')',
    'prezzo_eur'   => 0.00,
    'query_ricerca' => 'Estrai 4000 record nella provincia di Milano non stranieri · prodotto: depurazione acqua',
];

// 1. Email interna
echo "[1/3] Email INTERNA → $TEAM_EMAIL\n";
$r1 = aiSendInternalReport($TEAM_EMAIL, 'Team AI Lab', $report, $xlsx);
echo $r1['success'] ? "    ✓ OK\n" : "    ✗ " . $r1['error'] . "\n";

// 2. Email cliente
echo "[2/3] Email CLIENTE → $CLIENT_EMAIL\n";
$r2 = aiSendListDelivery(
    $CLIENT_EMAIL, $CLIENT_NAME,
    [
        'cliente'  => $report['cliente'],
        'contatto' => 'Michele',
        'prodotto' => $report['prodotto'],
        'area'     => $report['area'],
        'records'  => $report['records'],
    ],
    $xlsx
);
echo $r2['success'] ? "    ✓ OK\n" : "    ✗ " . $r2['error'] . "\n";

// 3. Registra nella tabella deliveries
echo "[3/3] Registrazione in ai_laboratory.deliveries\n";
try {
    $pdo = new PDO("mysql:host=" . AI_DB_HOST . ";port=" . AI_DB_PORT . ";charset=utf8mb4",
                   AI_DB_USER, AI_DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare("INSERT INTO ai_laboratory.deliveries
        (sent_at, cliente_id, cliente_nome, cliente_email, prodotto, query_ricerca, area,
         fonte_db, filtri, contatti_inviati, magazzino_tabella, file_path, file_name,
         prezzo_eur, note)
        VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $report['cliente_id'],
        $report['cliente'],
        $CLIENT_EMAIL,
        'depurazione',
        $report['query_ricerca'],
        $report['area'],
        $report['fonte_db'],
        $report['filtri'],
        $report['records'],
        '109_Michele_cerullo_CF',
        realpath($xlsx),
        basename($xlsx),
        $report['prezzo_eur'],
        'Dedup + insert magazzino: ' . $report['insert_info'],
    ]);
    $deliveryId = $pdo->lastInsertId();
    echo "    ✓ OK — delivery_id $deliveryId\n";
} catch (\Throwable $e) {
    echo "    ✗ " . $e->getMessage() . "\n";
    exit(1);
}

exit(($r1['success'] && $r2['success']) ? 0 : 1);
