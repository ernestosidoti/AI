<?php
/**
 * Fatture — elenco con filtri
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

aiSecurityHeaders();
aiRequireAuth();

$backDb = remoteDb(AI_BACKOFFICE_DB);
$uid = aiCurrentUserId();
$isAdmin = aiCurrentUserRole() === 'admin';

$search = trim($_GET['q'] ?? '');
$anno = (int)($_GET['anno'] ?? date('Y'));
$pagato = $_GET['pagato'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];
if (!$isAdmin) { $where .= " AND f.creatore_id = ?"; $params[] = $uid; }
if ($search !== '') {
    $where .= " AND (c.ragione_sociale LIKE ? OR c.partita_iva LIKE ? OR f.progressivo = ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = is_numeric($search) ? (int)$search : 0;
}
if ($anno > 0) { $where .= " AND f.anno = ?"; $params[] = $anno; }
if ($pagato !== '') { $where .= " AND f.pagato = ?"; $params[] = $pagato === '1' ? 1 : 0; }

$countStmt = $backDb->prepare("SELECT COUNT(*) FROM fatture f LEFT JOIN clientes c ON f.cliente_id = c.id WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// Stats
$statsStmt = $backDb->prepare("SELECT
    COUNT(*) AS n,
    COALESCE(SUM(importo_totale), 0) AS totale,
    COALESCE(SUM(CASE WHEN pagato = 1 THEN importo_totale END), 0) AS pagato_tot,
    COALESCE(SUM(CASE WHEN pagato = 0 THEN importo_totale END), 0) AS da_pagare,
    SUM(pagato = 1) AS n_pagate
    FROM fatture f LEFT JOIN clientes c ON f.cliente_id = c.id WHERE $where");
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$stmt = $backDb->prepare("
    SELECT f.*, c.ragione_sociale, c.partita_iva, u.name AS creatore_name, pm.nome AS metodo_nome
    FROM fatture f
    LEFT JOIN clientes c ON f.cliente_id = c.id
    LEFT JOIN users u ON f.creatore_id = u.id
    LEFT JOIN payment_methods pm ON f.metodo_pagamento_id = pm.id
    WHERE $where
    ORDER BY f.anno DESC, f.progressivo DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$fatture = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Anni disponibili per filtro
$anni = $backDb->query("SELECT DISTINCT anno FROM fatture ORDER BY anno DESC")->fetchAll(PDO::FETCH_COLUMN);

aiRenderHeader('Fatture', 'fatture');
?>

<main class="relative z-10 max-w-7xl mx-auto px-6 py-8">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <h1 class="page-title">Fatture</h1>
            <p class="text-sm text-slate-400 mt-1"><?= number_format($total, 0, ',', '.') ?> fatture</p>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="glass rounded-lg p-4">
            <div class="text-xs text-slate-400 uppercase tracking-wider">Totale fatturato</div>
            <div class="text-xl font-bold text-emerald-400 mt-1">€<?= number_format($stats['totale'], 2, ',', '.') ?></div>
            <div class="text-xs text-slate-500 mt-1"><?= $stats['n'] ?> fatture</div>
        </div>
        <div class="glass rounded-lg p-4">
            <div class="text-xs text-slate-400 uppercase tracking-wider">Incassato</div>
            <div class="text-xl font-bold text-emerald-400 mt-1">€<?= number_format($stats['pagato_tot'], 2, ',', '.') ?></div>
            <div class="text-xs text-slate-500 mt-1"><?= $stats['n_pagate'] ?> pagate</div>
        </div>
        <div class="glass rounded-lg p-4">
            <div class="text-xs text-slate-400 uppercase tracking-wider">Da incassare</div>
            <div class="text-xl font-bold text-amber-400 mt-1">€<?= number_format($stats['da_pagare'], 2, ',', '.') ?></div>
            <div class="text-xs text-slate-500 mt-1"><?= $stats['n'] - $stats['n_pagate'] ?> in sospeso</div>
        </div>
        <div class="glass rounded-lg p-4">
            <div class="text-xs text-slate-400 uppercase tracking-wider">% Incassato</div>
            <div class="text-xl font-bold mt-1"><?= $stats['totale'] > 0 ? round($stats['pagato_tot'] / $stats['totale'] * 100) : 0 ?>%</div>
        </div>
    </div>

    <!-- Filtri -->
    <form method="GET" class="glass rounded-lg p-4 mb-5 grid grid-cols-1 md:grid-cols-4 gap-3">
        <div>
            <label class="form-label">Cerca</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cliente, P.IVA, N° fattura" class="form-input">
        </div>
        <div>
            <label class="form-label">Anno</label>
            <select name="anno" class="form-input">
                <?php foreach ($anni as $y): ?>
                <option value="<?= $y ?>" <?= $anno == $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">Stato</label>
            <select name="pagato" class="form-input">
                <option value="" <?= $pagato === '' ? 'selected' : '' ?>>Tutte</option>
                <option value="1" <?= $pagato === '1' ? 'selected' : '' ?>>Pagate</option>
                <option value="0" <?= $pagato === '0' ? 'selected' : '' ?>>Da incassare</option>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary flex-1">Filtra</button>
            <a href="fatture.php" class="btn-ghost">Reset</a>
        </div>
    </form>

    <!-- Tabella -->
    <div class="glass rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>N° Fattura</th>
                        <th>Data</th>
                        <th>Cliente</th>
                        <th>P.IVA</th>
                        <th>Agente</th>
                        <th>Metodo</th>
                        <th class="text-right">Importo</th>
                        <th class="text-right">Spese gest.</th>
                        <th>Stato</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fatture)): ?>
                    <tr><td colspan="9" class="text-center py-10 text-slate-500">Nessuna fattura trovata</td></tr>
                    <?php endif; ?>
                    <?php foreach ($fatture as $f): ?>
                    <tr>
                        <td class="mono text-sm font-semibold">
                            <?= $f['progressivo'] ?>/<?= $f['anno'] ?>
                        </td>
                        <td class="mono text-xs text-slate-400"><?= date('d/m/Y', strtotime($f['data_emissione'])) ?></td>
                        <td>
                            <div class="text-sm"><?= htmlspecialchars($f['ragione_sociale'] ?? '—') ?></div>
                        </td>
                        <td class="mono text-xs text-slate-400"><?= htmlspecialchars($f['partita_iva'] ?? '—') ?></td>
                        <td class="text-xs text-slate-300"><?= htmlspecialchars($f['creatore_name'] ?? '—') ?></td>
                        <td class="text-xs text-slate-400"><?= htmlspecialchars($f['metodo_nome'] ?? '—') ?></td>
                        <td class="text-right mono text-sm text-emerald-400">€<?= number_format($f['importo_totale'], 2, ',', '.') ?></td>
                        <td class="text-right mono text-xs text-slate-400">€<?= number_format($f['spese_gestione'], 2, ',', '.') ?></td>
                        <td>
                            <?php if ((int)$f['pagato'] === 1): ?>
                            <span class="badge badge-green">✓ Pagata</span>
                            <?php else: ?>
                            <span class="badge badge-amber">In sospeso</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-center gap-1 p-4 border-t border-slate-800/60">
            <?php $qs = http_build_query(array_filter(['q' => $search, 'anno' => $anno, 'pagato' => $pagato])); ?>
            <?php if ($page > 1): ?>
            <a href="?<?= $qs ?>&page=<?= $page-1 ?>" class="btn-ghost">← Prec</a>
            <?php endif; ?>
            <span class="text-xs text-slate-500 mx-3">Pagina <?= $page ?> di <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
            <a href="?<?= $qs ?>&page=<?= $page+1 ?>" class="btn-ghost">Succ →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php aiRenderFooter(); ?>
