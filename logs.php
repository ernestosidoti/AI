<?php
/**
 * Log viewer (solo admin) — filtri per livello, categoria, utente, data
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/logger.php';
require_once __DIR__ . '/lib/layout.php';

aiSecurityHeaders();
aiRequireAdmin();

$db = aiDb();

// Filtri
$fLevel    = $_GET['level']    ?? '';
$fCategory = $_GET['category'] ?? '';
$fUser     = trim($_GET['user'] ?? '');
$fAction   = trim($_GET['action'] ?? '');
$fFrom     = $_GET['from']     ?? '';
$fTo       = $_GET['to']       ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 50;
$offset    = ($page - 1) * $perPage;

// Export CSV
if (($_GET['export'] ?? '') === 'csv') {
    $export = true;
    $perPage = 10000;
    $offset = 0;
} else {
    $export = false;
}

$where = '1=1';
$params = [];
if ($fLevel)    { $where .= " AND level = ?";          $params[] = $fLevel; }
if ($fCategory) { $where .= " AND category = ?";       $params[] = $fCategory; }
if ($fUser)     { $where .= " AND user_email LIKE ?";  $params[] = '%' . $fUser . '%'; }
if ($fAction)   { $where .= " AND action LIKE ?";      $params[] = '%' . $fAction . '%'; }
if ($fFrom)     { $where .= " AND created_at >= ?";    $params[] = $fFrom . ' 00:00:00'; }
if ($fTo)       { $where .= " AND created_at <= ?";    $params[] = $fTo   . ' 23:59:59'; }

// Totale
$countStmt = $db->prepare("SELECT COUNT(*) FROM app_logs WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare("SELECT * FROM app_logs WHERE $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats rapide
$statStmt = $db->query("SELECT level, COUNT(*) AS n FROM app_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY level");
$statsLast24 = [];
foreach ($statStmt as $r) $statsLast24[$r['level']] = (int)$r['n'];

// Export CSV
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="applogs_' . date('Ymd_His') . '.csv"');
    $fp = fopen('php://output', 'w');
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, ['id','timestamp','level','category','user','ip','action','message','context'], ';');
    foreach ($logs as $l) {
        fputcsv($fp, [
            $l['id'], $l['created_at'], $l['level'], $l['category'],
            $l['user_email'] ?? '', $l['ip'] ?? '',
            $l['action'], $l['message'] ?? '', $l['context'] ?? '',
        ], ';');
    }
    fclose($fp);
    exit;
}

$qs = http_build_query(array_filter([
    'level' => $fLevel, 'category' => $fCategory, 'user' => $fUser,
    'action' => $fAction, 'from' => $fFrom, 'to' => $fTo,
]));

$levelColors = [
    'debug'    => 'badge-slate',
    'info'     => 'badge-blue',
    'warning'  => 'badge-amber',
    'error'    => 'badge-red',
    'critical' => 'badge-red',
    'security' => 'badge-purple',
];
$catColors = [
    'auth'    => 'badge-purple',
    'system'  => 'badge-slate',
    'query'   => 'badge-blue',
    'user'    => 'badge-green',
    'client'  => 'badge-green',
    'order'   => 'badge-green',
    'admin'   => 'badge-amber',
    'api'     => 'badge-blue',
];

aiRenderHeader('Log applicativo', 'logs');
?>

<main class="relative z-10 max-w-7xl mx-auto px-6 py-8">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <h1 class="page-title">Log applicativo</h1>
            <p class="text-sm text-slate-400 mt-1"><?= number_format($total, 0, ',', '.') ?> eventi trovati</p>
        </div>
        <div class="flex gap-2">
            <a href="?<?= $qs ?>&export=csv" class="btn-secondary">⬇ Export CSV</a>
        </div>
    </div>

    <!-- Stats ultime 24h -->
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-5">
        <?php foreach (['info'=>'Info','warning'=>'Warn','error'=>'Error','critical'=>'Critical','security'=>'Security','debug'=>'Debug'] as $lv => $lbl):
            $n = $statsLast24[$lv] ?? 0;
            $cls = $levelColors[$lv] ?? 'badge-slate';
        ?>
        <a href="?level=<?= $lv ?>" class="glass rounded-lg p-3 glass-hover">
            <div class="flex items-center justify-between">
                <span class="text-xs text-slate-400 uppercase tracking-wider"><?= $lbl ?></span>
                <span class="badge <?= $cls ?>"><?= $n ?></span>
            </div>
            <div class="text-[10px] text-slate-500 mt-1">ultime 24h</div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filtri -->
    <form method="GET" class="glass rounded-lg p-4 mb-5 grid grid-cols-1 md:grid-cols-6 gap-3">
        <div>
            <label class="form-label">Livello</label>
            <select name="level" class="form-input">
                <option value="">Tutti</option>
                <?php foreach (['debug','info','warning','error','critical','security'] as $lv): ?>
                <option value="<?= $lv ?>" <?= $fLevel === $lv ? 'selected' : '' ?>><?= $lv ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">Categoria</label>
            <select name="category" class="form-input">
                <option value="">Tutte</option>
                <?php foreach (['auth','system','query','user','client','order','admin','api'] as $c): ?>
                <option value="<?= $c ?>" <?= $fCategory === $c ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">Utente</label>
            <input type="text" name="user" value="<?= htmlspecialchars($fUser) ?>" placeholder="email..." class="form-input">
        </div>
        <div>
            <label class="form-label">Azione</label>
            <input type="text" name="action" value="<?= htmlspecialchars($fAction) ?>" placeholder="login_success..." class="form-input">
        </div>
        <div>
            <label class="form-label">Da</label>
            <input type="date" name="from" value="<?= htmlspecialchars($fFrom) ?>" class="form-input">
        </div>
        <div>
            <label class="form-label">A</label>
            <input type="date" name="to" value="<?= htmlspecialchars($fTo) ?>" class="form-input">
        </div>
        <div class="md:col-span-6 flex gap-2 justify-end">
            <a href="logs.php" class="btn-ghost">Reset</a>
            <button type="submit" class="btn-primary">Applica filtri</button>
        </div>
    </form>

    <!-- Tabella -->
    <div class="glass rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Livello</th>
                        <th>Categoria</th>
                        <th>Utente</th>
                        <th>IP</th>
                        <th>Azione</th>
                        <th>Messaggio</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="8" class="text-center py-10 text-slate-500">Nessun log trovato con i filtri selezionati</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $l):
                        $levCls = $levelColors[$l['level']] ?? 'badge-slate';
                        $catCls = $catColors[$l['category']] ?? 'badge-slate';
                    ?>
                    <tr>
                        <td class="mono text-xs text-slate-400"><?= date('d/m H:i:s', strtotime($l['created_at'])) ?></td>
                        <td><span class="badge <?= $levCls ?>"><?= $l['level'] ?></span></td>
                        <td><span class="badge <?= $catCls ?>"><?= $l['category'] ?></span></td>
                        <td class="text-xs mono text-slate-300"><?= htmlspecialchars($l['user_email'] ?? '—') ?></td>
                        <td class="text-xs mono text-slate-500"><?= htmlspecialchars($l['ip'] ?? '—') ?></td>
                        <td class="mono text-xs text-slate-200"><?= htmlspecialchars($l['action']) ?></td>
                        <td class="text-sm text-slate-300 max-w-xl"><?= htmlspecialchars(mb_substr($l['message'] ?? '', 0, 180)) ?><?= mb_strlen($l['message'] ?? '') > 180 ? '…' : '' ?></td>
                        <td>
                            <?php if (!empty($l['context'])): ?>
                            <button onclick='showLogDetail(<?= (int)$l['id'] ?>, <?= htmlspecialchars(json_encode($l['context']), ENT_QUOTES) ?>)'
                                    class="btn-ghost text-xs">Context</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-center gap-1 p-4 border-t border-slate-800/60">
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

<!-- Modal Context -->
<div id="ctxModal" class="hidden fixed inset-0 z-50 items-center justify-center p-4"
     style="background: rgba(0,0,0,0.75); backdrop-filter: blur(8px); display: none"
     onclick="if(event.target===this) closeCtx()">
    <div class="glass rounded-xl max-w-2xl w-full max-h-[80vh] overflow-hidden flex flex-col">
        <div class="px-5 py-3 flex items-center justify-between border-b border-slate-700/50">
            <h3 class="font-semibold">Log context <span id="ctxId" class="mono text-slate-400 text-sm"></span></h3>
            <button onclick="closeCtx()" class="text-slate-400 hover:text-slate-100 text-xl leading-none">&times;</button>
        </div>
        <div class="p-5 overflow-y-auto">
            <pre id="ctxBody" class="text-xs text-slate-200 mono whitespace-pre-wrap"></pre>
        </div>
    </div>
</div>

<script>
function showLogDetail(id, ctx) {
    document.getElementById('ctxId').textContent = '#' + id;
    try {
        const obj = typeof ctx === 'string' ? JSON.parse(ctx) : ctx;
        document.getElementById('ctxBody').textContent = JSON.stringify(obj, null, 2);
    } catch (e) {
        document.getElementById('ctxBody').textContent = String(ctx);
    }
    const m = document.getElementById('ctxModal');
    m.classList.remove('hidden');
    m.style.display = 'flex';
}
function closeCtx() {
    const m = document.getElementById('ctxModal');
    m.classList.add('hidden');
    m.style.display = 'none';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCtx(); });
</script>

<?php aiRenderFooter(); ?>
