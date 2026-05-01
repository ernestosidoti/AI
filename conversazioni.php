<?php
/**
 * /ai/conversazioni.php — Archivio conversazioni Telegram (audit/review)
 * Lista sessioni · click → vista chat completa
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/TGArchive.php';

aiSecurityHeaders();
aiRequireAuth();

// Filtri
$dateFrom = $_GET['from']  ?? '';
$dateTo   = $_GET['to']    ?? '';
$chatId   = isset($_GET['chat']) && $_GET['chat'] !== '' ? (int)$_GET['chat'] : null;
$sessionId= $_GET['session'] ?? '';
$clienteSearch = trim($_GET['cliente'] ?? '');
$actionType = trim($_GET['action'] ?? '');

// Validazione date
if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo = '';

aiRenderHeader('Archivio conversazioni', 'conversazioni');

// ===== Vista dettaglio sessione =====
if ($sessionId) {
    $msgs = TGArchive::getSession($sessionId);
    $first = $msgs[0] ?? null;
    // Recupera tag cliente/action
    $tagInfo = null;
    if ($first) {
        $pdo = remoteDb('ai_laboratory');
        $st = $pdo->prepare("SELECT cliente_id, cliente_name, action_type FROM tg_conversation_archive WHERE session_id = ? AND (cliente_id IS NOT NULL OR action_type IS NOT NULL) LIMIT 1");
        $st->execute([$sessionId]);
        $tagInfo = $st->fetch(PDO::FETCH_ASSOC);
    }
    ?>
    <main class="relative z-10 max-w-5xl mx-auto px-6 py-6">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <a href="conversazioni.php<?= $dateFrom||$dateTo||$chatId||$clienteSearch||$actionType ? '?'.http_build_query(['from'=>$dateFrom,'to'=>$dateTo,'chat'=>$chatId,'cliente'=>$clienteSearch,'action'=>$actionType]) : '' ?>" class="link">← Torna alla lista</a>
                <h1 class="page-title mt-1">Sessione <span class="mono text-base text-slate-500"><?= htmlspecialchars(substr($sessionId, 0, 16)) ?>...</span></h1>
                <?php if ($first): ?>
                <div class="text-slate-400 text-sm mt-1 flex flex-wrap gap-3">
                    <span>👤 <?= htmlspecialchars($first['user_name'] ?? '-') ?></span>
                    <span>📅 <?= htmlspecialchars(substr($first['ts'], 0, 19)) ?></span>
                    <span>💬 <?= count($msgs) ?> messaggi</span>
                    <?php if ($tagInfo && $tagInfo['cliente_name']): ?>
                        <span class="badge badge-blue">🏢 <?= htmlspecialchars($tagInfo['cliente_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($tagInfo && $tagInfo['action_type']): ?>
                        <span class="badge badge-purple">🎯 <?= htmlspecialchars($tagInfo['action_type']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="glass p-6 space-y-3">
            <?php foreach ($msgs as $m): ?>
                <?php
                $isIn  = $m['direction'] === 'in';
                $isOut = $m['direction'] === 'out';
                $isSys = $m['direction'] === 'system';
                ?>
                <div class="flex <?= $isIn ? 'justify-end' : 'justify-start' ?>">
                    <div style="max-width: 75%;" class="<?= $isSys ? 'opacity-70' : '' ?>">
                        <div class="text-xs text-slate-500 mb-1 mono <?= $isIn ? 'text-right' : '' ?>">
                            <?= htmlspecialchars(substr($m['ts'], 11, 8)) ?>
                            <?= $isSys ? ' · 🛠 system' : ($isIn ? ' · 👤' : ' · 🤖') ?>
                        </div>
                        <div class="px-4 py-3 rounded-xl"
                             style="<?php
                                if ($isIn) echo 'background: rgba(59,130,246,0.18); border:1px solid rgba(59,130,246,0.35);';
                                elseif ($isOut) echo 'background: rgba(30,41,59,0.7); border:1px solid var(--border);';
                                else echo 'background: rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.3); font-size:13px;';
                             ?>">
                            <?= $isOut ? $m['text'] /* HTML allowed (parse_mode HTML del bot) */ : nl2br(htmlspecialchars($m['text'])) ?>
                        </div>
                        <?php if (!empty($m['meta'])): ?>
                            <details class="text-xs text-slate-500 mt-1 mono">
                                <summary class="cursor-pointer">meta</summary>
                                <pre class="bg-slate-900/50 p-2 rounded mt-1 overflow-x-auto"><?= htmlspecialchars($m['meta']) ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
    <?php
    aiRenderFooter();
    exit;
}

// ===== Lista sessioni =====
$sessions = TGArchive::listSessions(200, $chatId, $dateFrom ?: null, $dateTo ?: null, $clienteSearch ?: null, $actionType ?: null);
$stats = TGArchive::stats($dateFrom ?: null, $dateTo ?: null);
?>

<main class="relative z-10 max-w-7xl mx-auto px-6 py-6">

    <div class="mb-4 flex items-end justify-between flex-wrap gap-3">
        <div>
            <h1 class="page-title">📚 Archivio conversazioni</h1>
            <p class="text-slate-400 text-sm mt-1">
                Tutte le sessioni Telegram con bot per audit e review
            </p>
        </div>
    </div>

    <!-- Filtri -->
    <form method="get" class="glass p-4 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
            <div>
                <label class="form-label">Da</label>
                <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-input mono">
            </div>
            <div>
                <label class="form-label">A</label>
                <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="form-input mono">
            </div>
            <div>
                <label class="form-label">🔎 Cliente</label>
                <input type="text" name="cliente" value="<?= htmlspecialchars($clienteSearch) ?>" class="form-input" placeholder="cerullo, e-power...">
            </div>
            <div>
                <label class="form-label">Azione</label>
                <select name="action" class="form-input">
                    <option value="">tutte</option>
                    <option value="estrai"   <?= $actionType==='estrai'?'selected':'' ?>>estrai</option>
                    <option value="stat"     <?= $actionType==='stat'?'selected':'' ?>>stat</option>
                    <option value="storico"  <?= $actionType==='storico'?'selected':'' ?>>storico</option>
                </select>
            </div>
            <div>
                <button type="submit" class="btn-primary w-full justify-center" style="width:100%;justify-content:center;">🔍 Filtra</button>
            </div>
            <div>
                <a href="conversazioni.php" class="btn-secondary text-center block">🔄 Reset</a>
            </div>
        </div>
        <input type="hidden" name="chat" value="<?= htmlspecialchars((string)($chatId ?? '')) ?>">
    </form>

    <!-- KPI -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
        <div class="glass p-3"><div class="text-xs text-slate-500 uppercase">Sessioni</div><div class="text-2xl font-bold mt-1"><?= number_format((int)($stats['sessions'] ?? 0)) ?></div></div>
        <div class="glass p-3"><div class="text-xs text-slate-500 uppercase">Utenti unici</div><div class="text-2xl font-bold mt-1"><?= number_format((int)($stats['unique_users'] ?? 0)) ?></div></div>
        <div class="glass p-3"><div class="text-xs text-slate-500 uppercase">Msg in</div><div class="text-2xl font-bold mt-1 text-blue-400"><?= number_format((int)($stats['msg_in'] ?? 0)) ?></div></div>
        <div class="glass p-3"><div class="text-xs text-slate-500 uppercase">Msg out</div><div class="text-2xl font-bold mt-1 text-emerald-400"><?= number_format((int)($stats['msg_out'] ?? 0)) ?></div></div>
        <div class="glass p-3"><div class="text-xs text-slate-500 uppercase">Eventi sys</div><div class="text-2xl font-bold mt-1 text-amber-400"><?= number_format((int)($stats['msg_sys'] ?? 0)) ?></div></div>
    </div>

    <!-- Lista sessioni -->
    <div class="glass overflow-hidden">
        <table class="table">
            <thead>
                <tr>
                    <th>Data/ora</th>
                    <th>Operatore</th>
                    <th>Cliente</th>
                    <th>Azione</th>
                    <th class="text-center">Msg</th>
                    <th>Prima richiesta</th>
                    <th>Durata</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$sessions): ?>
                    <tr><td colspan="8" class="text-center py-12 text-slate-500">Nessuna sessione trovata</td></tr>
                <?php endif; ?>
                <?php foreach ($sessions as $s): ?>
                    <?php
                    $start = $s['started_at']; $end = $s['ended_at'];
                    $durSec = $start && $end ? max(0, strtotime($end) - strtotime($start)) : 0;
                    $durStr = $durSec < 60 ? "{$durSec}s" : (floor($durSec/60) . "m " . ($durSec % 60) . "s");
                    $actBadge = ['estrai'=>'badge-blue','stat'=>'badge-green','storico'=>'badge-purple','magazzino'=>'badge-amber'][$s['action_type'] ?? ''] ?? 'badge-slate';
                    ?>
                    <tr>
                        <td class="mono text-sm whitespace-nowrap">
                            <?= htmlspecialchars(substr($end, 0, 10)) ?>
                            <span class="text-slate-500"><?= htmlspecialchars(substr($end, 11, 5)) ?></span>
                        </td>
                        <td>
                            <?php if ($s['user_name']): ?>
                                <strong><?= htmlspecialchars($s['user_name']) ?></strong>
                            <?php else: ?>
                                <span class="text-slate-500">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['cliente_name']): ?>
                                <a href="?<?= http_build_query(['cliente' => $s['cliente_name'], 'from' => $dateFrom, 'to' => $dateTo]) ?>" class="link" title="Filtra per questo cliente">
                                    <?= htmlspecialchars($s['cliente_name']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-slate-600">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['action_type']): ?>
                                <span class="badge <?= $actBadge ?>"><?= htmlspecialchars($s['action_type']) ?></span>
                            <?php else: ?>
                                <span class="text-slate-600">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center whitespace-nowrap">
                            <span class="badge badge-blue"><?= (int)$s['msg_in'] ?>↘</span>
                            <span class="badge badge-green"><?= (int)$s['msg_out'] ?>↗</span>
                            <?php if ($s['msg_sys'] > 0): ?>
                                <span class="badge badge-amber">⚠ <?= (int)$s['msg_sys'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm" style="max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?= $s['first_user_msg'] ? htmlspecialchars(mb_substr($s['first_user_msg'], 0, 80)) : '<span class="text-slate-500">—</span>' ?>
                        </td>
                        <td class="text-sm text-slate-400 mono"><?= $durStr ?></td>
                        <td>
                            <a href="?<?= http_build_query(['session' => $s['session_id'], 'from' => $dateFrom, 'to' => $dateTo, 'chat' => $chatId, 'cliente' => $clienteSearch, 'action' => $actionType]) ?>" class="btn-ghost">Apri →</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="text-xs text-slate-500 mt-4">
        Mostrate ultime <?= count($sessions) ?> sessioni · ordinate per ultima attività
    </div>
</main>

<?php aiRenderFooter(); ?>
