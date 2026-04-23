<?php
/**
 * Duplica ordine — cerca un ordine esistente e crea una copia
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/logger.php';
require_once __DIR__ . '/lib/layout.php';

aiSecurityHeaders();
aiRequireAuth();

$backDb = remoteDb(AI_BACKOFFICE_DB);
$uid = aiCurrentUserId();
$isAdmin = aiCurrentUserRole() === 'admin';

$message = null;
$messageType = 'success';

// Azione duplica (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aiVerifyCsrf($_POST['csrf_token'] ?? '')) {
    $sourceId = (int)($_POST['source_id'] ?? 0);
    if ($sourceId > 0) {
        try {
            $stmt = $backDb->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$sourceId]);
            $src = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$src) {
                $message = 'Ordine non trovato';
                $messageType = 'error';
            } else {
                $ins = $backDb->prepare("INSERT INTO orders
                    (prodotto_id, cliente_id, creatore, tipo, quantita, zona, data_stimata,
                     importo_bonifico, metodo_pagamento_id, stato, note, link_file,
                     data_ora, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, 'Statistica da effettuare', ?, NULL, NOW(), NOW(), NOW())");
                $ins->execute([
                    $src['prodotto_id'], $src['cliente_id'],
                    $uid ?: $src['creatore'],
                    $src['tipo'], $src['quantita'], $src['zona'],
                    $src['importo_bonifico'], $src['metodo_pagamento_id'],
                    ($src['note'] ? "[DUPLICATO da #$sourceId]\n\n" . $src['note'] : "[Duplicato da ordine #$sourceId]"),
                ]);
                $newId = (int)$backDb->lastInsertId();
                aiLog('order', 'order_duplicated', "Ordine #$newId duplicato da #$sourceId", ['source' => $sourceId, 'new' => $newId]);
                header('Location: nuovo_ordine.php?id=' . $newId . '&duplicated=1');
                exit;
            }
        } catch (\Throwable $e) {
            $message = 'Errore: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Ricerca ordini
$search = trim($_GET['q'] ?? '');
$clienteFilter = (int)($_GET['cliente_id'] ?? 0);

$where = '1=1';
$params = [];
if (!$isAdmin) { $where .= " AND o.creatore = ?"; $params[] = $uid; }
if ($search !== '') {
    $where .= " AND (c.ragione_sociale LIKE ? OR o.note LIKE ? OR o.zona LIKE ? OR p.nome LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($clienteFilter > 0) {
    $where .= " AND o.cliente_id = ?";
    $params[] = $clienteFilter;
}

$stmt = $backDb->prepare("
    SELECT o.*, c.ragione_sociale, c.partita_iva, c.comune AS cliente_comune,
           p.nome AS prodotto_nome, u.name AS creatore_name
    FROM orders o
    LEFT JOIN clientes c ON o.cliente_id = c.id
    LEFT JOIN prodotti p ON o.prodotto_id = p.id
    LEFT JOIN users u ON o.creatore = u.id
    WHERE $where
    ORDER BY o.id DESC
    LIMIT 50
");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrf = aiCsrfToken();
aiRenderHeader('Duplica ordine', 'ordini');
?>

<main class="relative z-10 max-w-7xl mx-auto px-6 py-8">
    <div class="mb-6">
        <a href="home.php" class="link text-sm">← Home</a>
        <h1 class="page-title mt-2">Duplica ordine</h1>
        <p class="text-sm text-slate-400 mt-1">Cerca un ordine precedente e crea una copia modificabile. I dati del cliente, prodotto, tipo, quantità, zona e note vengono copiati. Lo stato parte da "Statistica da effettuare".</p>
    </div>

    <?php if ($message): ?>
    <div class="glass rounded-lg p-3 mb-4">
        <p class="text-sm <?= $messageType === 'success' ? 'text-emerald-400' : 'text-red-400' ?>"><?= htmlspecialchars($message) ?></p>
    </div>
    <?php endif; ?>

    <form method="GET" class="glass rounded-lg p-4 mb-5 flex gap-2">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cerca per cliente, prodotto, zona, note..." class="form-input flex-1" autofocus>
        <button type="submit" class="btn-primary">Cerca</button>
        <?php if ($search || $clienteFilter): ?>
        <a href="duplica_ordine.php" class="btn-ghost">Reset</a>
        <?php endif; ?>
    </form>

    <?php if (empty($orders)): ?>
    <div class="glass rounded-lg p-10 text-center text-slate-500">
        <p><?= $search ? 'Nessun ordine trovato con questi criteri.' : 'Digita nella barra di ricerca per trovare l\'ordine da duplicare.' ?></p>
    </div>
    <?php else: ?>
    <div class="glass rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Data</th>
                        <th>Cliente</th>
                        <th>Prodotto</th>
                        <th>Tipo</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">€</th>
                        <th>Agente</th>
                        <th>Stato</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o):
                        $badgeClass = match($o['stato']) {
                            'Evaso' => 'badge-green',
                            'Pronto da inviare','Statistica generata' => 'badge-blue',
                            'Da Evadere','Statistica da effettuare' => 'badge-amber',
                            'Annullato','Errore di Vendita' => 'badge-red',
                            default => 'badge-slate',
                        };
                    ?>
                    <tr>
                        <td class="mono text-xs text-slate-500">#<?= $o['id'] ?></td>
                        <td class="mono text-xs text-slate-400"><?= date('d/m/Y', strtotime($o['data_ora'])) ?></td>
                        <td>
                            <div class="text-sm"><?= htmlspecialchars($o['ragione_sociale'] ?? '-') ?></div>
                            <?php if ($o['cliente_comune']): ?>
                            <div class="text-xs text-slate-500"><?= htmlspecialchars($o['cliente_comune']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs text-slate-300"><?= htmlspecialchars($o['prodotto_nome'] ?? '-') ?></td>
                        <td class="text-xs"><?= htmlspecialchars($o['tipo']) ?></td>
                        <td class="text-right mono text-sm"><?= $o['quantita'] ? number_format($o['quantita'], 0, ',', '.') : '-' ?></td>
                        <td class="text-right mono text-sm text-emerald-400"><?= $o['importo_bonifico'] ? number_format($o['importo_bonifico'], 2, ',', '.') : '-' ?></td>
                        <td class="text-xs text-slate-300"><?= htmlspecialchars($o['creatore_name'] ?? '-') ?></td>
                        <td><span class="badge <?= $badgeClass ?>"><?= $o['stato'] ?></span></td>
                        <td class="text-right">
                            <form method="POST" class="inline-block" onsubmit="return confirm('Duplicare l\'ordine #<?= $o['id'] ?> per <?= htmlspecialchars(addslashes($o['ragione_sociale'] ?? '-'), ENT_QUOTES) ?>?');">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="source_id" value="<?= $o['id'] ?>">
                                <button type="submit" class="btn-primary text-xs">⎘ Duplica</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php aiRenderFooter(); ?>
