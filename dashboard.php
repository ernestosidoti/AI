<?php
/**
 * LTM AI LAB — Dashboard
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/CostTracker.php';

aiSecurityHeaders();
aiRequireAuth();

$db = aiDb();
$stats = CostTracker::getStats($db);
$history = $db->query("SELECT * FROM queries ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$daily = $db->query("SELECT DATE(created_at) AS giorno, COUNT(*) AS n, SUM(cost_usd) AS c
    FROM queries WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at) ORDER BY giorno DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>AI Laboratory - Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Rajdhani:wght@400;500;600&display=swap" rel="stylesheet">
<style>
body { background: #000510; font-family: 'Rajdhani', sans-serif; color: #e0e7ff; min-height: 100vh; margin: 0; }
.orbitron { font-family: 'Orbitron', monospace; }
.glass { background: rgba(10, 15, 30, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(99, 102, 241, 0.25); }
.bg-grid { position: fixed; inset: 0; background-image: linear-gradient(rgba(99,102,241,0.06) 1px, transparent 1px), linear-gradient(90deg, rgba(99,102,241,0.06) 1px, transparent 1px); background-size: 50px 50px; z-index: 0; }
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="relative z-10 max-w-7xl mx-auto px-6 py-8">

    <div class="flex items-center justify-between mb-8">
        <h1 class="orbitron text-2xl font-black bg-gradient-to-r from-cyan-400 via-purple-500 to-pink-500 bg-clip-text text-transparent">COST DASHBOARD</h1>
        <div class="flex gap-3">
            <a href="index.php" class="text-cyan-400 hover:text-cyan-300 text-sm">&larr; Laboratory</a>
            <a href="logout.php" class="text-slate-500 hover:text-red-400 text-sm">Logout</a>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="glass rounded-xl p-5"><p class="orbitron text-xs text-cyan-400 tracking-widest">OGGI</p><p class="orbitron text-3xl font-bold text-white mt-1">$<?= number_format($stats['oggi']['cost_usd'], 4) ?></p><p class="text-slate-400 text-sm mt-1"><?= $stats['oggi']['queries'] ?> queries</p></div>
        <div class="glass rounded-xl p-5"><p class="orbitron text-xs text-purple-400 tracking-widest">QUESTO MESE</p><p class="orbitron text-3xl font-bold text-white mt-1">$<?= number_format($stats['mese']['cost_usd'], 4) ?></p><p class="text-slate-400 text-sm mt-1"><?= $stats['mese']['queries'] ?> queries</p></div>
        <div class="glass rounded-xl p-5"><p class="orbitron text-xs text-pink-400 tracking-widest">TOTALE</p><p class="orbitron text-3xl font-bold text-white mt-1">$<?= number_format($stats['totale']['cost_usd'], 4) ?></p><p class="text-slate-400 text-sm mt-1"><?= $stats['totale']['queries'] ?> queries</p></div>
        <div class="glass rounded-xl p-5"><p class="orbitron text-xs text-yellow-400 tracking-widest">MEDIA/QUERY</p><p class="orbitron text-3xl font-bold text-white mt-1">$<?= number_format($stats['media'], 4) ?></p><p class="text-slate-400 text-sm mt-1"><?= number_format($stats['totale']['input_tokens'] + $stats['totale']['output_tokens']) ?> tok</p></div>
    </div>

    <?php if (!empty($daily)): ?>
    <div class="glass rounded-xl p-5 mb-8">
        <h3 class="orbitron text-sm font-bold text-white mb-4 tracking-wider">COSTI PER GIORNO</h3>
        <div class="space-y-2">
            <?php $maxCost = max(array_column($daily, 'c')); foreach ($daily as $d): $pct = $maxCost > 0 ? ($d['c'] / $maxCost * 100) : 0; ?>
            <div class="flex items-center gap-3 text-xs">
                <span class="text-slate-400 font-mono w-24"><?= date('d/m/Y', strtotime($d['giorno'])) ?></span>
                <div class="flex-1 bg-slate-800/50 h-6 rounded overflow-hidden relative">
                    <div class="h-full bg-gradient-to-r from-cyan-500 to-purple-600" style="width: <?= $pct ?>%"></div>
                    <span class="absolute inset-0 flex items-center px-3 text-white font-mono">$<?= number_format($d['c'], 4) ?> <span class="text-slate-400 ml-2">(<?= $d['n'] ?> queries)</span></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-700/50"><h3 class="orbitron text-sm font-bold text-white tracking-wider">STORICO QUERY</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-900/50 border-b border-slate-700">
                    <tr class="text-left orbitron text-xs text-slate-400 tracking-wider">
                        <th class="px-3 py-2">#</th><th class="px-3 py-2">DATA</th><th class="px-3 py-2">RICHIESTA</th>
                        <th class="px-3 py-2 text-right">TOK</th><th class="px-3 py-2 text-right">COSTO</th>
                        <th class="px-3 py-2 text-right">REC</th><th class="px-3 py-2">STATO</th>
                        <th class="px-3 py-2">AZIONI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $q): $statusColor = match($q['status']) { 'downloaded' => 'text-green-400', 'executed' => 'text-cyan-400', 'interpreted' => 'text-yellow-400', 'failed' => 'text-red-400', default => 'text-slate-400' }; ?>
                    <tr class="border-b border-slate-800 hover:bg-slate-800/30">
                        <td class="px-3 py-2 text-slate-500 font-mono">#<?= $q['id'] ?></td>
                        <td class="px-3 py-2 text-slate-400 font-mono text-xs"><?= date('d/m H:i', strtotime($q['created_at'])) ?></td>
                        <td class="px-3 py-2 max-w-xs truncate text-slate-300" title="<?= htmlspecialchars($q['user_prompt']) ?>"><?= htmlspecialchars(mb_substr($q['user_prompt'], 0, 80)) ?></td>
                        <td class="px-3 py-2 text-right text-xs font-mono text-slate-400"><?= number_format($q['input_tokens']) ?>/<?= number_format($q['output_tokens']) ?></td>
                        <td class="px-3 py-2 text-right font-mono text-cyan-300">$<?= number_format($q['cost_usd'], 6) ?></td>
                        <td class="px-3 py-2 text-right font-mono text-white"><?= number_format($q['records_count']) ?></td>
                        <td class="px-3 py-2"><span class="orbitron text-xs <?= $statusColor ?> uppercase"><?= $q['status'] ?></span></td>
                        <td class="px-3 py-2">
                            <a href="index.php?refine=<?= $q['id'] ?>" class="inline-block px-2 py-1 bg-cyan-500/20 hover:bg-cyan-500/30 border border-cyan-500/50 text-cyan-400 text-xs rounded tracking-wider">🔄 AFFINA</a>
                        </td>
                    </tr>
                    <?php if ($q['parent_query_id']): ?>
                    <tr class="border-b border-slate-800/50">
                        <td colspan="8" class="px-3 py-1 text-xs text-slate-500 pl-12">
                            <span class="text-purple-400">↳</span> Affinamento di query #<?= $q['parent_query_id'] ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (empty($history)): ?><tr><td colspan="8" class="px-3 py-8 text-center text-slate-500">Nessuna query</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
