<?php
/**
 * SIMULAZIONE doppio invio:
 *   1. Email INTERNA (report completo) → team
 *   2. Email CLIENTE-FACING (senza info interne) → destinatario
 *
 * In simulazione entrambe vanno a upselling@gmail.com.
 */

define('AILAB', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/mailer.php';

$xlsx = __DIR__ . '/../downloads/51/stile-acqua-srl_milano_4000_depurazione_v3.xlsx';
if (!is_file($xlsx)) { fwrite(STDERR, "File non trovato: $xlsx\n"); exit(1); }

$TEAM_EMAIL       = 'upselling@gmail.com';
$CLIENT_EMAIL_SIM = 'upselling@gmail.com'; // in prod sarà es. stilecasabenessere@libero.it
$CLIENT_NAME      = 'Michele Cerullo';

$report = [
    'cliente'      => 'STILE ACQUA SRL',
    'cliente_id'   => '51',
    'contatto'     => $CLIENT_NAME,
    'piva'         => '04572140160',
    'prodotto'     => 'depurazione acqua',
    'area'         => 'Provincia di Milano',
    'fonte_db'     => 'Edicus_2023_marzo.superpod_2023 (5.4M record)',
    'filtri'       => 'provincia in (MI, Milano) · CF italiani (no Z) · mobile valido',
    'pool'         => 83733,
    'records'      => 4000,
    'comuni'       => '275 distinti',
    'magazzino'    => 'clienti.109_Michele_cerullo_CF (più recente, created 2026-03-06)',
    'dedup'        => 'anti-join pre-estrazione',
    'insert_info'  => '4000 mobile inseriti · data_lotto 22-04-2026 07.43.02 · moo 3.210.885 → 3.214.884',
    'send_to'      => $CLIENT_EMAIL_SIM . ' (' . $CLIENT_NAME . ')',
];

// 1. Email interna
echo "[1/2] Invio email INTERNA al team ($TEAM_EMAIL)...\n";
$r1 = aiSendInternalReport($TEAM_EMAIL, 'Team AI Lab', $report, $xlsx);
echo $r1['success'] ? "    ✓ OK\n" : "    ✗ ERROR: " . $r1['error'] . "\n";

// 2. Email cliente (in simulazione va allo stesso indirizzo)
echo "[2/2] Invio email CLIENTE ($CLIENT_EMAIL_SIM) [SIMULAZIONE]...\n";
$r2 = aiSendListDelivery(
    $CLIENT_EMAIL_SIM,
    $CLIENT_NAME,
    [
        'cliente'  => $report['cliente'],
        'contatto' => explode(' ', $CLIENT_NAME)[0] ?? $CLIENT_NAME,
        'prodotto' => $report['prodotto'],
        'area'     => $report['area'],
        'records'  => $report['records'],
    ],
    $xlsx
);
echo $r2['success'] ? "    ✓ OK\n" : "    ✗ ERROR: " . $r2['error'] . "\n";

exit(($r1['success'] && $r2['success']) ? 0 : 1);
