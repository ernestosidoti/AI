<?php
/**
 * Lista utenti (solo admin)
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

aiSecurityHeaders();
aiRequireAdmin();

$backDb = remoteDb(AI_BACKOFFICE_DB);

$search = trim($_GET['q'] ?? '');
$createdId = (int)($_GET['created'] ?? 0);
$updatedId = (int)($_GET['updated'] ?? 0);
$deletedId = (int)($_GET['deleted'] ?? 0);
$pwdSent = (int)($_GET['pwdsent'] ?? 0);

$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= " AND (name LIKE ? OR email LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like];
}

$stmt = $backDb->prepare("SELECT u.*,
    (SELECT COUNT(*) FROM clientes c WHERE c.user_id = u.id) AS n_clienti,
    (SELECT COUNT(*) FROM orders o WHERE o.creatore = u.id) AS n_ordini,
    (SELECT COALESCE(SUM(importo_bonifico), 0) FROM orders o WHERE o.creatore = u.id AND YEAR(o.data_ora) = YEAR(NOW())) AS fatturato_anno
    FROM users u WHERE $where ORDER BY u.active DESC, u.name");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

aiRenderHeader('Utenti', 'utenti');
?>

<main class="relative z-10 max-w-7xl mx-auto px-6 py-8">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <h1 class="page-title">Gestione utenti</h1>
            <p class="text-sm text-slate-400 mt-1"><?= count($users) ?> utenti · <?= count(array_filter($users, fn($u)=>(int)$u['active']===1)) ?> attivi</p>
        </div>
        <a href="nuovo_utente.php" class="btn-primary">+ Nuovo utente</a>
    </div>

    <?php if ($createdId): ?>
    <div class="glass rounded-lg p-3 mb-4"><p class="text-sm text-emerald-400">✓ Utente creato</p></div>
    <?php endif; ?>
    <?php if ($updatedId): ?>
    <div class="glass rounded-lg p-3 mb-4"><p class="text-sm text-blue-400">✓ Utente #<?= $updatedId ?> aggiornato</p></div>
    <?php endif; ?>
    <?php if ($deletedId): ?>
    <div class="glass rounded-lg p-3 mb-4"><p class="text-sm text-red-400">🗑 Utente #<?= $deletedId ?> eliminato</p></div>
    <?php endif; ?>
    <?php if ($pwdSent): ?>
    <div class="glass rounded-lg p-3 mb-4"><p class="text-sm text-amber-400">📧 Credenziali inviate per utente #<?= $pwdSent ?></p></div>
    <?php endif; ?>

    <form method="GET" class="glass rounded-lg p-3 mb-5 flex gap-2">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cerca per nome o email..." class="form-input flex-1">
        <button type="submit" class="btn-secondary">Cerca</button>
        <?php if ($search): ?><a href="utenti.php" class="btn-ghost">Reset</a><?php endif; ?>
    </form>

    <div class="glass rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Ruolo</th>
                        <th>Commerciale</th>
                        <th>Stato</th>
                        <th class="text-right">Clienti</th>
                        <th class="text-right">Ordini</th>
                        <th class="text-right">Fatt. anno</th>
                        <th>Ultimo login</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr<?= !$u['active'] ? ' style="opacity:0.5"' : '' ?>>
                        <td>
                            <div class="font-medium"><?= htmlspecialchars($u['name']) ?></div>
                            <?php if ($u['telefono']): ?>
                            <div class="text-xs text-slate-500 mono"><?= htmlspecialchars($u['telefono']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="mono text-xs text-slate-300"><?= htmlspecialchars($u['email']) ?></td>
                        <td>
                            <?php if ($u['role'] === 'admin'): ?>
                            <span class="badge badge-purple">Admin</span>
                            <?php else: ?>
                            <span class="badge badge-slate">User</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$u['commerciale'] === 1): ?>
                            <span class="badge badge-blue">Sì</span>
                            <?php else: ?>
                            <span class="text-slate-500 text-xs">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$u['active'] === 1): ?>
                            <span class="badge badge-green">Attivo</span>
                            <?php else: ?>
                            <span class="badge badge-red">Disattivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right mono"><?= number_format($u['n_clienti']) ?></td>
                        <td class="text-right mono"><?= number_format($u['n_ordini']) ?></td>
                        <td class="text-right mono text-emerald-400">€<?= number_format($u['fatturato_anno'], 2, ',', '.') ?></td>
                        <td class="text-xs text-slate-500 mono">
                            <?= $u['ultimo_login'] ? date('d/m H:i', strtotime($u['ultimo_login'])) : '—' ?>
                        </td>
                        <td class="text-right">
                            <a href="edit_utente.php?id=<?= $u['id'] ?>" class="btn-ghost">Apri</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php aiRenderFooter(); ?>
