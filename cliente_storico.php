<?php
/**
 * Storico query + ordini di un singolo cliente
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

aiSecurityHeaders();
aiRequireAuth();

$clienteId = (int)($_GET['id'] ?? 0);
if (!$clienteId) { header('Location: clienti.php'); exit; }

$backDb = remoteDb(AI_BACKOFFICE_DB);
$stmt = $backDb->prepare("SELECT c.*, u.name AS agent_name FROM clientes c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?");
$stmt->execute([$clienteId]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) { header('Location: clienti.php'); exit; }

// Query AI Lab di questo cliente
$aiDb = aiDb();
$queries = $aiDb->prepare("SELECT * FROM queries WHERE cliente_id = ? ORDER BY id DESC LIMIT 100");
$queries->execute([$clienteId]);
$queriesData = $queries->fetchAll(PDO::FETCH_ASSOC);

// Statistiche AI
$statsStmt = $aiDb->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(cost_usd),0) AS c, COALESCE(SUM(records_count),0) AS r FROM queries WHERE cliente_id = ?");
$statsStmt->execute([$clienteId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Ordini backoffice
$ordini = $backDb->prepare("
    SELECT o.*, p.nome AS prodotto_nome, u.name AS creatore_name
    FROM orders o
    LEFT JOIN prodotti p ON o.prodotto_id = p.id
    LEFT JOIN users u ON o.creatore = u.id
    WHERE o.cliente_id = ?
    ORDER BY o.id DESC
    LIMIT 50
");
$ordini->execute([$clienteId]);
$ordiniData = $ordini->fetchAll(PDO::FETCH_ASSOC);

aiRenderHeader('Storico ' . $cliente['ragione_sociale'], 'clienti');
?>

<main class="relative z-10 max-w-7xl mx-auto px-6 py-8">
    <div class="flex items-start justify-between mb-6 flex-wrap gap-4">
        <div>
            <a href="clienti.php" class="text-cyan-400 hover:text-cyan-300 text-sm">&larr; Tutti i clienti</a>
            <h1 class="orbitron text-2xl font-black mt-2 bg-gradient-to-r from-cyan-400 via-purple-500 to-pink-500 bg-clip-text text-transparent">
                <?= htmlspecialchars($cliente['ragione_sociale']) ?>
            </h1>
            <p class="text-slate-400 text-sm mt-1">
                <?php if ($cliente['partita_iva']): ?>P.IVA <span class="font-mono text-cyan-300"><?= htmlspecialchars($cliente['partita_iva']) ?></span><?php endif; ?>
                <?php if ($cliente['codice_fiscale']): ?> · CF <span class="font-mono text-cyan-300"><?= htmlspecialchars($cliente['codice_fiscale']) ?></span><?php endif; ?>
                <?php if ($cliente['comune']): ?> · <?= htmlspecialchars($cliente['comune']) ?> (<?= htmlspecialchars($cliente['provincia'] ?? '') ?>)<?php endif; ?>
            </p>
            <p class="text-xs text-slate-500 mt-1">
                <?php if ($cliente['email']): ?>📧 <?= htmlspecialchars($cliente['email']) ?> · <?php endif; ?>
                <?php if ($cliente['numero_cellulare']): ?>📞 <?= htmlspecialchars($cliente['numero_cellulare']) ?> · <?php endif; ?>
                Agente: <span class="text-purple-300"><?= htmlspecialchars($cliente['agent_name'] ?? '-') ?></span>
            </p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <a href="index.php?cliente_id=<?= $clienteId ?>" class="btn-primary orbitron px-4 py-2.5 text-sm text-white font-bold rounded-lg tracking-wider"
               style="background: linear-gradient(135deg, #22d3ee 0%, #6366f1 50%, #a855f7 100%);">+ NUOVA ESTRAZIONE</a>
            <a href="nuovo_ordine.php?cliente_id=<?= $clienteId ?>" class="orbitron px-4 py-2.5 text-sm bg-slate-800/50 hover:bg-slate-700 border border-slate-600 text-slate-200 rounded-lg tracking-wider">+ ORDINE</a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="glass rounded-xl p-4">
            <p class="orbitron text-xs text-cyan-400">ESTRAZIONI AI</p>
            <p class="orbitron text-2xl font-bold text-white mt-1"><?= $stats['n'] ?></p>
        </div>
        <div class="glass rounded-xl p-4">
            <p class="orbitron text-xs text-pink-400">RECORD ESTRATTI</p>
            <p class="orbitron text-2xl font-bold text-white mt-1"><?= number_format($stats['r'],0,',','.') ?></p>
        </div>
        <div class="glass rounded-xl p-4">
            <p class="orbitron text-xs text-purple-400">COSTO AI</p>
            <p class="orbitron text-2xl font-bold text-white mt-1">$<?= number_format($stats['c'],4) ?></p>
        </div>
        <div class="glass rounded-xl p-4">
            <p class="orbitron text-xs text-yellow-400">ORDINI</p>
            <p class="orbitron text-2xl font-bold text-white mt-1"><?= count($ordiniData) ?></p>
        </div>
    </div>

    <!-- QUERY AI LAB -->
    <div class="glass rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-slate-700/50 flex items-center justify-between">
            <h3 class="orbitron text-sm font-bold text-cyan-400">🤖 ESTRAZIONI AI</h3>
            <span class="text-xs text-slate-500"><?= count($queriesData) ?> query</span>
        </div>
        <?php if (empty($queriesData)): ?>
        <div class="p-8 text-center text-slate-500 text-sm">
            Nessuna estrazione fatta per questo cliente.
            <a href="index.php?cliente_id=<?= $clienteId ?>" class="text-cyan-400 hover:underline">Crea la prima</a>
        </div>
        <?php else: ?>
        <div class="divide-y divide-slate-800">
            <?php foreach ($queriesData as $q):
                $statusClr = ['downloaded'=>'text-green-400','executed'=>'text-cyan-400','interpreted'=>'text-yellow-400','failed'=>'text-red-400'][$q['status']] ?? 'text-slate-400';
            ?>
            <div class="p-4 hover:bg-slate-800/30">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-2 flex-wrap">
                            <span class="orbitron text-xs text-slate-500">#<?= $q['id'] ?></span>
                            <span class="text-xs text-slate-400 font-mono"><?= date('d/m/Y H:i', strtotime($q['created_at'])) ?></span>
                            <?php if ($q['product_code']): ?>
                            <span class="text-[10px] orbitron tracking-wider px-2 py-0.5 bg-purple-500/20 text-purple-300 rounded"><?= htmlspecialchars($q['product_code']) ?></span>
                            <?php endif; ?>
                            <span class="text-[10px] orbitron tracking-wider uppercase <?= $statusClr ?>"><?= $q['status'] ?></span>
                            <?php if ($q['parent_query_id']): ?>
                            <span class="text-[10px] text-purple-400">↳ da #<?= $q['parent_query_id'] ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-white text-sm mb-2"><?= htmlspecialchars($q['user_prompt']) ?></p>
                        <?php if ($q['interpretation']): ?>
                        <p class="text-xs text-slate-400 italic mb-2">→ <?= htmlspecialchars(mb_substr($q['interpretation'], 0, 200)) ?></p>
                        <?php endif; ?>
                        <?php if ($q['generated_sql']): ?>
                        <details class="text-xs">
                            <summary class="cursor-pointer text-purple-400 hover:text-purple-300 orbitron tracking-wider">▸ VEDI SQL</summary>
                            <pre class="mt-2 text-cyan-300 font-mono bg-black/40 p-2 rounded border border-cyan-500/20 whitespace-pre-wrap overflow-x-auto"><?= htmlspecialchars($q['generated_sql']) ?></pre>
                        </details>
                        <?php endif; ?>
                        <div class="text-xs text-slate-500 mt-2 font-mono">
                            <?= number_format($q['records_count']) ?> record · $<?= number_format($q['cost_usd'],6) ?>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2">
                        <?php if ($q['file_path'] && $q['status'] !== 'failed'): ?>
                        <a href="api/download.php?id=<?= $q['id'] ?>" class="orbitron text-xs px-3 py-1.5 bg-cyan-500/20 hover:bg-cyan-500/30 border border-cyan-500/50 text-cyan-400 rounded tracking-wider text-center">⬇ SCARICA</a>
                        <?php endif; ?>
                        <a href="index.php?refine=<?= $q['id'] ?>" class="orbitron text-xs px-3 py-1.5 bg-purple-500/20 hover:bg-purple-500/30 border border-purple-500/50 text-purple-400 rounded tracking-wider text-center">🔄 AFFINA</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ORDINI BACKOFFICE -->
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-700/50 flex items-center justify-between">
            <h3 class="orbitron text-sm font-bold text-cyan-400">📋 ORDINI (backoffice)</h3>
            <a href="nuovo_ordine.php?cliente_id=<?= $clienteId ?>" class="text-xs text-cyan-400 hover:underline">+ Nuovo</a>
        </div>
        <?php if (empty($ordiniData)): ?>
        <div class="p-6 text-center text-slate-500 text-sm">Nessun ordine per questo cliente.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-900/50 border-b border-slate-700">
                    <tr class="text-left orbitron text-xs text-slate-400 tracking-wider">
                        <th class="px-3 py-2">#</th><th class="px-3 py-2">DATA</th><th class="px-3 py-2">PRODOTTO</th>
                        <th class="px-3 py-2">TIPO</th><th class="px-3 py-2 text-right">QTY</th>
                        <th class="px-3 py-2 text-right">€</th><th class="px-3 py-2">AGENTE</th><th class="px-3 py-2">STATO</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ordiniData as $o): ?>
                    <tr class="border-b border-slate-800">
                        <td class="px-3 py-2 text-slate-500 font-mono">#<?= $o['id'] ?></td>
                        <td class="px-3 py-2 text-xs text-slate-400"><?= date('d/m/Y', strtotime($o['data_ora'])) ?></td>
                        <td class="px-3 py-2 text-xs text-cyan-300"><?= htmlspecialchars($o['prodotto_nome'] ?? '-') ?></td>
                        <td class="px-3 py-2 text-xs"><?= htmlspecialchars($o['tipo']) ?></td>
                        <td class="px-3 py-2 text-right font-mono text-xs"><?= $o['quantita'] ? number_format($o['quantita'],0,',','.') : '-' ?></td>
                        <td class="px-3 py-2 text-right font-mono text-xs text-green-400"><?= $o['importo_bonifico'] ? number_format($o['importo_bonifico'],2,',','.') : '-' ?></td>
                        <td class="px-3 py-2 text-xs text-purple-300"><?= htmlspecialchars($o['creatore_name'] ?? '-') ?></td>
                        <td class="px-3 py-2 text-xs"><?= $o['stato'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php aiRenderFooter(); ?>
