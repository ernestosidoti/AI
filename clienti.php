<?php
/**
 * Elenco clienti da backoffice.clientes + ricerca
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

aiSecurityHeaders();
aiRequireAuth();

$backDb = remoteDb(AI_BACKOFFICE_DB);

$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$createdId = (int)($_GET['created'] ?? 0);
$updatedId = (int)($_GET['updated'] ?? 0);
$deletedId = (int)($_GET['deleted'] ?? 0);

$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= " AND (c.ragione_sociale LIKE ? OR c.nome LIKE ? OR c.cognome LIKE ? OR c.partita_iva LIKE ? OR c.codice_fiscale LIKE ? OR c.email LIKE ? OR c.comune LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_fill(0, 7, $like);
}

$countStmt = $backDb->prepare("SELECT COUNT(*) FROM clientes c LEFT JOIN users u ON c.user_id = u.id WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $backDb->prepare("SELECT c.*, u.name AS agent_name
    FROM clientes c LEFT JOIN users u ON c.user_id = u.id
    WHERE $where
    ORDER BY c.id DESC
    LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$clienti = $stmt->fetchAll(PDO::FETCH_ASSOC);

aiRenderHeader('Clienti', 'clienti');
?>

<main class="relative z-10 max-w-7xl mx-auto px-6 py-8">
    <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
        <div>
            <h1 class="orbitron text-2xl font-black bg-gradient-to-r from-cyan-400 via-purple-500 to-pink-500 bg-clip-text text-transparent">
                CLIENTI
            </h1>
            <p class="text-slate-400 text-sm mt-1"><?= $total ?> clienti registrati</p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <a href="incolla_cliente.php" class="orbitron px-5 py-2.5 bg-yellow-500/20 hover:bg-yellow-500/30 border border-yellow-500/50 text-yellow-400 font-bold rounded-lg text-sm tracking-wider">📋 INCOLLA DATI</a>
            <a href="nuovo_cliente.php" class="btn-primary orbitron px-6 py-2.5 text-white font-bold rounded-lg text-sm tracking-wider">+ NUOVO CLIENTE</a>
        </div>
    </div>

    <?php if ($createdId): ?>
    <div class="glass rounded-xl p-4 mb-6 border-green-500/50">
        <p class="text-green-400 text-sm">✓ Cliente #<?= $createdId ?> creato con successo.</p>
    </div>
    <?php endif; ?>
    <?php if ($updatedId): ?>
    <div class="glass rounded-xl p-4 mb-6 border-cyan-500/50">
        <p class="text-cyan-400 text-sm">✓ Cliente #<?= $updatedId ?> aggiornato.</p>
    </div>
    <?php endif; ?>
    <?php if ($deletedId): ?>
    <div class="glass rounded-xl p-4 mb-6 border-red-500/50">
        <p class="text-red-400 text-sm">🗑 Cliente #<?= $deletedId ?> eliminato.</p>
    </div>
    <?php endif; ?>

    <form method="GET" class="glass rounded-xl p-4 mb-6 flex items-end gap-3">
        <div class="flex-1">
            <label class="form-label">Cerca per ragione sociale, nome, cognome, P.IVA, CF, email, comune</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Digita per cercare..." class="form-input">
        </div>
        <button type="submit" class="btn-primary orbitron px-5 py-2.5 text-white font-bold rounded-lg text-xs tracking-wider">CERCA</button>
        <?php if ($search): ?>
        <a href="clienti.php" class="text-slate-400 hover:text-slate-200 text-sm">Reset</a>
        <?php endif; ?>
    </form>

    <div class="glass rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-900/50 border-b border-slate-700">
                    <tr class="text-left orbitron text-xs text-slate-400 tracking-wider">
                        <th class="px-3 py-2">#</th>
                        <th class="px-3 py-2">RAGIONE SOCIALE</th>
                        <th class="px-3 py-2">P.IVA / CF</th>
                        <th class="px-3 py-2">COMUNE</th>
                        <th class="px-3 py-2">CONTATTI</th>
                        <th class="px-3 py-2">AGENTE</th>
                        <th class="px-3 py-2">AZIONI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clienti as $c): ?>
                    <tr class="border-b border-slate-800 hover:bg-slate-800/30">
                        <td class="px-3 py-2 text-slate-500 font-mono">#<?= $c['id'] ?></td>
                        <td class="px-3 py-2">
                            <div class="text-white font-medium"><?= htmlspecialchars($c['ragione_sociale']) ?></div>
                            <?php if ($c['nome'] || $c['cognome']): ?>
                            <div class="text-xs text-slate-400"><?= htmlspecialchars(trim($c['nome'] . ' ' . $c['cognome'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-xs font-mono text-slate-300">
                            <?= htmlspecialchars($c['partita_iva'] ?? '') ?>
                            <?php if ($c['codice_fiscale']): ?>
                            <div class="text-slate-500"><?= htmlspecialchars($c['codice_fiscale']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-slate-300">
                            <?= htmlspecialchars($c['comune'] ?? '') ?>
                            <?php if ($c['provincia']): ?><span class="text-slate-500">(<?= htmlspecialchars($c['provincia']) ?>)</span><?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            <?php if ($c['email']): ?><div class="text-cyan-400"><?= htmlspecialchars($c['email']) ?></div><?php endif; ?>
                            <?php if ($c['numero_cellulare']): ?><div class="text-slate-400 font-mono"><?= htmlspecialchars($c['numero_cellulare']) ?></div><?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-xs text-purple-300"><?= htmlspecialchars($c['agent_name'] ?? '-') ?></td>
                        <td class="px-3 py-2">
                            <div class="flex gap-1 flex-wrap">
                                <a href="nuovo_cliente.php?id=<?= $c['id'] ?>" title="Modifica cliente" class="inline-block px-2 py-1 bg-cyan-500/20 hover:bg-cyan-500/30 border border-cyan-500/50 text-cyan-400 text-xs rounded tracking-wider">✏ EDIT</a>
                                <a href="cliente_storico.php?id=<?= $c['id'] ?>" class="inline-block px-2 py-1 bg-purple-500/20 hover:bg-purple-500/30 border border-purple-500/50 text-purple-400 text-xs rounded tracking-wider">📋 STORICO</a>
                                <a href="index.php?cliente_id=<?= $c['id'] ?>" class="inline-block px-2 py-1 bg-pink-500/20 hover:bg-pink-500/30 border border-pink-500/50 text-pink-400 text-xs rounded tracking-wider">🤖 ESTRAI</a>
                                <a href="nuovo_ordine.php?cliente_id=<?= $c['id'] ?>" class="inline-block px-2 py-1 bg-slate-700/50 hover:bg-slate-700 border border-slate-600 text-slate-300 text-xs rounded tracking-wider">+ ORDINE</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($clienti)): ?>
                    <tr><td colspan="7" class="px-3 py-8 text-center text-slate-500">Nessun cliente trovato</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-center gap-2 p-4 border-t border-slate-700/50">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>" class="px-3 py-1.5 rounded-lg text-xs bg-slate-800/50 text-slate-400 hover:text-cyan-400">&laquo; Prec</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
            <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>" class="px-3 py-1.5 rounded-lg text-xs <?= $i === $page ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/50' : 'bg-slate-800/50 text-slate-400 hover:text-cyan-400' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>" class="px-3 py-1.5 rounded-lg text-xs bg-slate-800/50 text-slate-400 hover:text-cyan-400">Succ &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php aiRenderFooter(); ?>
