<?php
/**
 * Elenco ordini da backoffice.orders
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
$statoFilter = trim($_GET['stato'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$createdId = (int)($_GET['created'] ?? 0);
$updatedId = (int)($_GET['updated'] ?? 0);
$deletedId = (int)($_GET['deleted'] ?? 0);

$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= " AND (c.ragione_sociale LIKE ? OR o.note LIKE ? OR o.zona LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
}
if ($statoFilter !== '') {
    $where .= " AND o.stato = ?";
    $params[] = $statoFilter;
}

$countStmt = $backDb->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN clientes c ON o.cliente_id = c.id WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $backDb->prepare("
    SELECT o.*, c.ragione_sociale, p.nome AS prodotto_nome, u.name AS creatore_name, pm.nome AS metodo_nome
    FROM orders o
    LEFT JOIN clientes c ON o.cliente_id = c.id
    LEFT JOIN prodotti p ON o.prodotto_id = p.id
    LEFT JOIN users u ON o.creatore = u.id
    LEFT JOIN payment_methods pm ON o.metodo_pagamento_id = pm.id
    WHERE $where
    ORDER BY o.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$ordini = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats rapide
$statsStmt = $backDb->query("SELECT stato, COUNT(*) AS n FROM orders GROUP BY stato");
$statsByStato = [];
foreach ($statsStmt as $r) $statsByStato[$r['stato']] = (int)$r['n'];

$statoColors = [
    'Statistica da effettuare' => 'bg-slate-500/20 text-slate-300',
    'Statistica generata' => 'bg-cyan-500/20 text-cyan-300',
    'Da Evadere' => 'bg-yellow-500/20 text-yellow-300',
    'Pronto da inviare' => 'bg-blue-500/20 text-blue-300',
    'Annullato' => 'bg-red-500/20 text-red-400',
    'Evaso' => 'bg-green-500/20 text-green-400',
    'Errore di Vendita' => 'bg-red-500/20 text-red-400',
];

aiRenderHeader('Ordini', 'ordini');
?>

<main class="relative z-10 max-w-7xl mx-auto px-6 py-8">
    <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
        <div>
            <h1 class="orbitron text-2xl font-black bg-gradient-to-r from-cyan-400 via-purple-500 to-pink-500 bg-clip-text text-transparent">ORDINI</h1>
            <p class="text-slate-400 text-sm mt-1"><?= $total ?> ordini totali</p>
        </div>
        <a href="nuovo_ordine.php" class="btn-primary orbitron px-6 py-2.5 text-white font-bold rounded-lg text-sm tracking-wider">+ NUOVO ORDINE</a>
    </div>

    <?php if ($createdId): ?>
    <div class="glass rounded-xl p-4 mb-6 border-green-500/50">
        <p class="text-green-400 text-sm">✓ Ordine #<?= $createdId ?> creato con successo.</p>
    </div>
    <?php endif; ?>
    <?php if ($updatedId): ?>
    <div class="glass rounded-xl p-4 mb-6 border-cyan-500/50">
        <p class="text-cyan-400 text-sm">✓ Ordine #<?= $updatedId ?> aggiornato.</p>
    </div>
    <?php endif; ?>
    <?php if ($deletedId): ?>
    <div class="glass rounded-xl p-4 mb-6 border-red-500/50">
        <p class="text-red-400 text-sm">🗑 Ordine #<?= $deletedId ?> eliminato.</p>
    </div>
    <?php endif; ?>

    <!-- Filtri rapidi per stato -->
    <div class="flex gap-2 flex-wrap mb-4">
        <a href="ordini.php" class="orbitron text-xs px-3 py-1.5 rounded-full <?= $statoFilter === '' ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/50' : 'bg-slate-800/50 text-slate-400 border border-slate-700' ?>">TUTTI</a>
        <?php foreach ($statoColors as $s => $cls): ?>
        <a href="?stato=<?= urlencode($s) ?>" class="orbitron text-xs px-3 py-1.5 rounded-full <?= $statoFilter === $s ? $cls . ' border border-current' : 'bg-slate-800/50 text-slate-400 border border-slate-700 hover:text-slate-200' ?>">
            <?= $s ?> <?= isset($statsByStato[$s]) ? '(' . $statsByStato[$s] . ')' : '' ?>
        </a>
        <?php endforeach; ?>
    </div>

    <form method="GET" class="glass rounded-xl p-4 mb-6 flex items-end gap-3">
        <?php if ($statoFilter): ?><input type="hidden" name="stato" value="<?= htmlspecialchars($statoFilter) ?>"><?php endif; ?>
        <div class="flex-1">
            <label class="form-label">Cerca per cliente, zona, note</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Digita..." class="form-input">
        </div>
        <button type="submit" class="btn-primary orbitron px-5 py-2.5 text-white font-bold rounded-lg text-xs tracking-wider">CERCA</button>
        <?php if ($search || $statoFilter): ?>
        <a href="ordini.php" class="text-slate-400 hover:text-slate-200 text-sm">Reset</a>
        <?php endif; ?>
    </form>

    <div class="glass rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-900/50 border-b border-slate-700">
                    <tr class="text-left orbitron text-xs text-slate-400 tracking-wider">
                        <th class="px-3 py-2">#</th>
                        <th class="px-3 py-2">DATA</th>
                        <th class="px-3 py-2">CLIENTE</th>
                        <th class="px-3 py-2">PRODOTTO</th>
                        <th class="px-3 py-2">TIPO</th>
                        <th class="px-3 py-2 text-right">QTY</th>
                        <th class="px-3 py-2 text-right">€</th>
                        <th class="px-3 py-2">AGENTE</th>
                        <th class="px-3 py-2">STATO</th>
                        <th class="px-3 py-2 text-center">NOTE</th>
                        <th class="px-3 py-2">AZIONI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ordini as $o):
                        $cls = $statoColors[$o['stato']] ?? 'bg-slate-500/20 text-slate-300';
                    ?>
                    <tr class="border-b border-slate-800 hover:bg-slate-800/30">
                        <td class="px-3 py-2 text-slate-500 font-mono">#<?= $o['id'] ?></td>
                        <td class="px-3 py-2 text-xs text-slate-400 font-mono">
                            <?= date('d/m/Y', strtotime($o['data_ora'])) ?><br>
                            <span class="text-slate-600"><?= date('H:i', strtotime($o['data_ora'])) ?></span>
                        </td>
                        <td class="px-3 py-2">
                            <div class="text-white text-sm"><?= htmlspecialchars($o['ragione_sociale'] ?? '-') ?></div>
                            <?php if (!empty($o['zona'])): ?>
                            <div class="text-xs text-slate-500"><?= htmlspecialchars($o['zona']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-xs text-cyan-300"><?= htmlspecialchars($o['prodotto_nome'] ?? '-') ?></td>
                        <td class="px-3 py-2 text-xs text-slate-300"><?= htmlspecialchars($o['tipo']) ?></td>
                        <td class="px-3 py-2 text-right text-xs font-mono text-white"><?= $o['quantita'] ? number_format($o['quantita'], 0, ',', '.') : '-' ?></td>
                        <td class="px-3 py-2 text-right text-xs font-mono text-green-400"><?= $o['importo_bonifico'] ? number_format($o['importo_bonifico'], 2, ',', '.') : '-' ?></td>
                        <td class="px-3 py-2 text-xs text-purple-300"><?= htmlspecialchars($o['creatore_name'] ?? '-') ?></td>
                        <td class="px-3 py-2">
                            <span class="inline-block px-2 py-0.5 rounded text-[10px] orbitron tracking-wider <?= $cls ?>">
                                <?= $o['stato'] ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 text-center">
                            <?php if (!empty($o['note'])): ?>
                            <button type="button"
                                    onclick="showNote(<?= $o['id'] ?>)"
                                    title="Mostra note ordine"
                                    class="inline-flex items-center justify-center w-7 h-7 bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/40 text-amber-400 rounded transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                            </button>
                            <template id="note-<?= $o['id'] ?>"><?= htmlspecialchars($o['note']) ?></template>
                            <?php else: ?>
                            <span class="text-slate-700">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex gap-1">
                                <a href="nuovo_ordine.php?id=<?= $o['id'] ?>"
                                   title="Visualizza e modifica ordine"
                                   class="orbitron inline-block px-2 py-1 text-[10px] text-cyan-400 bg-cyan-500/20 border border-cyan-500/50 rounded tracking-wider">
                                    ✏ EDIT
                                </a>
                                <a href="index.php?order_id=<?= $o['id'] ?>"
                                   title="Interpreta ed esegui con Claude AI"
                                   class="orbitron inline-block px-2 py-1 text-[10px] text-white rounded tracking-wider font-bold"
                                   style="background: linear-gradient(135deg, #22d3ee, #6366f1, #a855f7); box-shadow: 0 0 10px rgba(99,102,241,0.4);">
                                    🤖 ESEGUI
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($ordini)): ?>
                    <tr><td colspan="11" class="px-3 py-8 text-center text-slate-500">Nessun ordine trovato</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-center gap-2 p-4 border-t border-slate-700/50">
            <?php
            $qs = http_build_query(array_filter(['q' => $search, 'stato' => $statoFilter]));
            if ($page > 1): ?>
            <a href="?<?= $qs ?>&page=<?= $page - 1 ?>" class="px-3 py-1.5 rounded-lg text-xs bg-slate-800/50 text-slate-400 hover:text-cyan-400">&laquo; Prec</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
            <a href="?<?= $qs ?>&page=<?= $i ?>" class="px-3 py-1.5 rounded-lg text-xs <?= $i === $page ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/50' : 'bg-slate-800/50 text-slate-400 hover:text-cyan-400' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?<?= $qs ?>&page=<?= $page + 1 ?>" class="px-3 py-1.5 rounded-lg text-xs bg-slate-800/50 text-slate-400 hover:text-cyan-400">Succ &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- Modal Note -->
<div id="noteModal" class="hidden fixed inset-0 z-50 items-center justify-center p-4"
     style="background: rgba(0,0,0,0.75); backdrop-filter: blur(8px); display: none;"
     onclick="if(event.target===this) closeNoteModal()">
    <div class="glass rounded-xl max-w-2xl w-full max-h-[80vh] overflow-hidden flex flex-col" style="border-color: rgba(245,158,11,0.4)">
        <div class="px-5 py-3 flex items-center justify-between border-b border-slate-700/50">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                <h3 class="font-semibold text-slate-100">Note ordine <span id="noteOrderId" class="mono text-slate-400 text-sm"></span></h3>
            </div>
            <button onclick="closeNoteModal()" class="text-slate-400 hover:text-slate-100 text-xl leading-none">&times;</button>
        </div>
        <div class="p-5 overflow-y-auto">
            <pre id="noteContent" class="text-sm text-slate-200 whitespace-pre-wrap font-sans leading-relaxed"></pre>
        </div>
        <div class="px-5 py-3 border-t border-slate-700/50 flex justify-end">
            <button onclick="closeNoteModal()" class="btn-secondary">Chiudi</button>
        </div>
    </div>
</div>

<script>
function showNote(orderId) {
    const tpl = document.getElementById('note-' + orderId);
    if (!tpl) return;
    document.getElementById('noteOrderId').textContent = '#' + orderId;
    document.getElementById('noteContent').textContent = tpl.innerHTML;
    const m = document.getElementById('noteModal');
    m.classList.remove('hidden');
    m.style.display = 'flex';
}
function closeNoteModal() {
    const m = document.getElementById('noteModal');
    m.classList.add('hidden');
    m.style.display = 'none';
}
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeNoteModal(); });
</script>

<?php aiRenderFooter(); ?>
