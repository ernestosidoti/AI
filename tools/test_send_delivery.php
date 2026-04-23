<?php
/**
 * Test invio email consegna lista — lancia da CLI:
 *   php tools/test_send_delivery.php
 * Invia la delivery Cerullo v3 a upselling@gmail.com per test.
 */

define('AILAB', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/mailer.php';

$xlsx = __DIR__ . '/../downloads/51/stile-acqua-srl_milano_4000_depurazione_v3.xlsx';
if (!is_file($xlsx)) {
    fwrite(STDERR, "File non trovato: $xlsx\n");
    exit(1);
}

$result = aiSendListDelivery(
    'upselling@gmail.com',
    'Ernesto',
    [
        'cliente'    => 'STILE ACQUA SRL (Michele Cerullo)',
        'contatto'   => 'Michele',
        'prodotto'   => 'depurazione acqua',
        'area'       => 'Provincia di Milano',
        'records'    => 4000,
        'note_dedup' => 'anti-join eseguito contro storico più recente (109_Michele_cerullo_CF). I numeri consegnati sono stati inseriti nel magazzino con data_lotto ' . date('d-m-Y H.i.s') . '.',
    ],
    $xlsx
);

echo $result['success']
    ? "✓ Email inviata a upselling@gmail.com (size allegato: " . round(filesize($xlsx)/1024, 1) . " KB)\n"
    : "✗ ERRORE: " . $result['error'] . "\n";

exit($result['success'] ? 0 : 1);
