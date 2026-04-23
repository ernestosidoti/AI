<?php
/**
 * LTM AI LAB — Metadata editor completo
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

aiSecurityHeaders();
aiRequireAuth();

$db = aiDb();
$message = null;
$msgType = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && aiVerifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    try {
        // DB metadata
        if ($action === 'db_save') {
            $id = (int)$_POST['id'];
            $prodotti = array_values(array_filter(array_map('trim', $_POST['prodotti_adatti'] ?? [])));
            $db->prepare("UPDATE db_metadata SET label=?, tipo_principale=?, prodotti_adatti=?, priorita=?, active=?, description=?, note_interne=? WHERE id=?")
                ->execute([
                    trim($_POST['label']), trim($_POST['tipo_principale']),
                    json_encode($prodotti), max(1,min(10,(int)$_POST['priorita'])),
                    isset($_POST['active']) ? 1 : 0,
                    trim($_POST['description']), trim($_POST['note_interne']),
                    $id,
                ]);
            $message = 'DB aggiornato';
        }

        // Product rules
        if ($action === 'rule_save') {
            $id = (int)$_POST['id'];
            if ($id > 0) {
                $db->prepare("UPDATE product_rules SET product_code=?, rule_type=?, rule_name=?, description=?, rule_sql=?, priority=?, active=? WHERE id=?")
                    ->execute([
                        trim($_POST['product_code']), trim($_POST['rule_type']),
                        trim($_POST['rule_name']), trim($_POST['description']),
                        trim($_POST['rule_sql']), max(1,min(99,(int)$_POST['priority'])),
                        isset($_POST['active']) ? 1 : 0, $id,
                    ]);
                $message = 'Regola aggiornata';
            } else {
                $db->prepare("INSERT INTO product_rules (product_code, rule_type, rule_name, description, rule_sql, priority, active) VALUES (?,?,?,?,?,?,?)")
                    ->execute([
                        trim($_POST['product_code']), trim($_POST['rule_type']),
                        trim($_POST['rule_name']), trim($_POST['description']),
                        trim($_POST['rule_sql']), max(1,min(99,(int)$_POST['priority'])),
                        isset($_POST['active']) ? 1 : 0,
                    ]);
                $message = 'Regola creata';
            }
        }
        if ($action === 'rule_delete') {
            $id = (int)$_POST['id'];
            $db->prepare("DELETE FROM product_rules WHERE id=?")->execute([$id]);
            $message = 'Regola eliminata';
        }

        // City exclusions
        if ($action === 'city_add') {
            $list = trim($_POST['list_code']);
            $cities = array_map('trim', explode("\n", trim($_POST['cities_bulk'] ?? '')));
            $inserted = 0;
            foreach ($cities as $line) {
                if ($line === '') continue;
                $parts = preg_split('/[,;|]/', $line, 2);
                $city = strtoupper(trim($parts[0]));
                $prov = isset($parts[1]) ? strtoupper(trim($parts[1])) : null;
                if ($city !== '') {
                    $db->prepare("INSERT INTO city_exclusions (list_code, city_name, province, active) VALUES (?,?,?,1)")
                       ->execute([$list, $city, $prov]);
                    $inserted++;
                }
            }
            $message = "$inserted città aggiunte";
        }
        if ($action === 'city_delete') {
            $id = (int)$_POST['id'];
            $db->prepare("DELETE FROM city_exclusions WHERE id=?")->execute([$id]);
            $message = 'Città rimossa';
        }
        if ($action === 'city_toggle') {
            $id = (int)$_POST['id'];
            $db->prepare("UPDATE city_exclusions SET active = 1-active WHERE id=?")->execute([$id]);
            $message = 'Città aggiornata';
        }
        if ($action === 'city_new_list') {
            $newList = trim($_POST['new_list_code']);
            if ($newList && preg_match('/^[a-z0-9_]+$/i', $newList)) {
                $message = "Lista '$newList' pronta — aggiungi le prime città qui sotto";
                $_GET['list'] = $newList;
                $_GET['tab'] = 'cities';
            } else {
                $message = 'Nome lista non valido (solo lettere/numeri/underscore)';
                $msgType = 'error';
            }
        }

        // Products catalog
        if ($action === 'product_save') {
            $id = (int)$_POST['id'];
            if ($id > 0) {
                $db->prepare("UPDATE products_catalog SET code=?, label=?, description=?, active=?, display_order=? WHERE id=?")
                    ->execute([trim($_POST['code']), trim($_POST['label']), trim($_POST['description']),
                               isset($_POST['active'])?1:0, max(1,min(99,(int)$_POST['display_order'])), $id]);
                $message = 'Prodotto aggiornato';
            } else {
                $db->prepare("INSERT INTO products_catalog (code, label, description, active, display_order) VALUES (?,?,?,?,?)")
                    ->execute([trim($_POST['code']), trim($_POST['label']), trim($_POST['description']),
                               isset($_POST['active'])?1:0, max(1,min(99,(int)$_POST['display_order']))]);
                $message = 'Prodotto creato';
            }
        }
    } catch (\Throwable $e) {
        $message = 'Errore: ' . $e->getMessage();
        $msgType = 'error';
    }
}

$tab = $_GET['tab'] ?? 'db';
$csrf = aiCsrfToken();

$dbs = $db->query("SELECT * FROM db_metadata ORDER BY tipo_principale, priorita, source_id")->fetchAll(PDO::FETCH_ASSOC);
$products = $db->query("SELECT * FROM products_catalog ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);
$rules = $db->query("SELECT r.*, p.label AS product_label FROM product_rules r LEFT JOIN products_catalog p ON p.code = r.product_code ORDER BY r.product_code, r.priority")->fetchAll(PDO::FETCH_ASSOC);

$cityLists = $db->query("SELECT list_code, SUM(active) AS active, COUNT(*) AS tot FROM city_exclusions GROUP BY list_code")->fetchAll(PDO::FETCH_ASSOC);
$selectedList = $_GET['list'] ?? ($cityLists[0]['list_code'] ?? 'capoluoghi_provincia');
$cities = [];
if ($tab === 'cities') {
    $stmt = $db->prepare("SELECT * FROM city_exclusions WHERE list_code = ? ORDER BY city_name");
    $stmt->execute([$selectedList]);
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

aiRenderHeader('Intelligence Config', 'metadata');
?>
<style>
.modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 50; display: none; align-items: center; justify-content: center; padding: 20px; }
.modal-bg.open { display: flex; }
.modal-box { background: rgba(10,15,30,0.95); border: 1px solid rgba(34,211,238,0.4); border-radius: 16px; max-width: 780px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 0 60px rgba(34,211,238,0.25); }
.chip { display: inline-flex; align-items: center; gap: 4px; background: rgba(99,102,241,0.2); color: #c7d2fe; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-family: 'Orbitron', monospace; }
.chip button { color: #f87171; font-weight: bold; background: none; border: none; cursor: pointer; padding: 0 2px; }
</style>

<main class="relative z-10 max-w-7xl mx-auto px-6 py-8">
    <h1 class="orbitron text-2xl font-black mb-2 bg-gradient-to-r from-cyan-400 via-purple-500 to-pink-500 bg-clip-text text-transparent">
        INTELLIGENCE CONFIG
    </h1>
    <p class="text-slate-400 text-sm mb-6">Modifica metadata, regole e liste. Le modifiche vengono applicate immediatamente alle nuove estrazioni.</p>

    <?php if ($message): ?>
    <div class="glass rounded-xl p-3 mb-4 border-<?= $msgType === 'success' ? 'green' : 'red' ?>-500/50">
        <p class="text-<?= $msgType === 'success' ? 'green' : 'red' ?>-400 text-sm"><?= $msgType === 'success' ? '✓' : '✗' ?> <?= htmlspecialchars($message) ?></p>
    </div>
    <?php endif; ?>

    <div class="flex gap-2 mb-6 border-b border-slate-700/50">
        <a href="?tab=db" class="orbitron text-xs px-4 py-2 <?= $tab === 'db' ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/50 border-b-0 rounded-t' : 'text-slate-400 hover:text-cyan-400' ?>">📊 DATABASE (<?= count($dbs) ?>)</a>
        <a href="?tab=rules" class="orbitron text-xs px-4 py-2 <?= $tab === 'rules' ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/50 border-b-0 rounded-t' : 'text-slate-400 hover:text-cyan-400' ?>">⚙️ REGOLE (<?= count($rules) ?>)</a>
        <a href="?tab=cities" class="orbitron text-xs px-4 py-2 <?= $tab === 'cities' ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/50 border-b-0 rounded-t' : 'text-slate-400 hover:text-cyan-400' ?>">🏙️ CITTÀ</a>
        <a href="?tab=products" class="orbitron text-xs px-4 py-2 <?= $tab === 'products' ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/50 border-b-0 rounded-t' : 'text-slate-400 hover:text-cyan-400' ?>">📦 PRODOTTI (<?= count($products) ?>)</a>
    </div>

    <?php if ($tab === 'db'): ?>
    <!-- ========== TAB DATABASE ========== -->
    <div class="glass rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-900/50 border-b border-slate-700">
                <tr class="text-left orbitron text-xs text-slate-400 tracking-wider">
                    <th class="px-3 py-2">LABEL</th>
                    <th class="px-3 py-2">TIPO</th>
                    <th class="px-3 py-2 text-right">RECORDS</th>
                    <th class="px-3 py-2">PRODOTTI</th>
                    <th class="px-3 py-2 text-center">PRIO</th>
                    <th class="px-3 py-2 text-center">ON</th>
                    <th class="px-3 py-2">AZIONI</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dbs as $d):
                    $prodotti = json_decode($d['prodotti_adatti'] ?? '[]', true);
                    $tipoClr = ['residenziale'=>'bg-cyan-500/20 text-cyan-300','business'=>'bg-purple-500/20 text-purple-300','gas'=>'bg-pink-500/20 text-pink-300','misto'=>'bg-yellow-500/20 text-yellow-300'][$d['tipo_principale']] ?? '';
                ?>
                <tr class="border-b border-slate-800 hover:bg-slate-800/30 <?= !$d['active'] ? 'opacity-50' : '' ?>">
                    <td class="px-3 py-2">
                        <div class="text-white font-medium"><?= htmlspecialchars($d['label']) ?></div>
                        <div class="text-xs text-slate-500 font-mono"><?= htmlspecialchars($d['database_name'].'.'.$d['table_name']) ?></div>
                    </td>
                    <td class="px-3 py-2"><span class="text-xs orbitron px-2 py-0.5 rounded <?= $tipoClr ?>"><?= $d['tipo_principale'] ?></span></td>
                    <td class="px-3 py-2 text-right font-mono text-xs"><?= number_format($d['records_count'],0,',','.') ?></td>
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap gap-1 max-w-xs">
                            <?php foreach ($prodotti as $p): ?>
                            <span class="text-[10px] px-1.5 py-0.5 bg-slate-800 text-slate-300 rounded"><?= htmlspecialchars($p) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td class="px-3 py-2 text-center font-mono text-cyan-400"><?= $d['priorita'] ?></td>
                    <td class="px-3 py-2 text-center"><?= $d['active'] ? '<span class="text-green-400">●</span>' : '<span class="text-slate-600">○</span>' ?></td>
                    <td class="px-3 py-2">
                        <button onclick='openDbModal(<?= json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="btn-primary orbitron px-3 py-1 text-xs text-white rounded tracking-wider"
                                style="background: linear-gradient(135deg, #22d3ee, #6366f1);">✏ EDIT</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($tab === 'rules'): ?>
    <!-- ========== TAB REGOLE ========== -->
    <div class="flex justify-end mb-4">
        <button onclick='openRuleModal(null)' class="btn-primary orbitron px-4 py-2 text-sm text-white font-bold rounded-lg tracking-wider"
                style="background: linear-gradient(135deg, #22d3ee, #6366f1, #a855f7);">+ NUOVA REGOLA</button>
    </div>

    <div class="space-y-4">
        <?php
        $byProduct = [];
        foreach ($rules as $r) $byProduct[$r['product_code']][] = $r;
        foreach ($byProduct as $code => $items):
            $lbl = $items[0]['product_label'] ?? $code;
        ?>
        <div class="glass rounded-xl overflow-hidden">
            <div class="px-4 py-3 bg-slate-900/50 border-b border-slate-700/50">
                <h3 class="orbitron text-sm text-cyan-400"><?= htmlspecialchars($lbl) ?> <span class="text-slate-500">(<?= $code ?>)</span></h3>
            </div>
            <div class="divide-y divide-slate-800">
                <?php foreach ($items as $rule):
                    $typeClr = ['exclude'=>'bg-red-500/20 text-red-400','include'=>'bg-green-500/20 text-green-400','transform'=>'bg-blue-500/20 text-blue-400','note'=>'bg-yellow-500/20 text-yellow-400'][$rule['rule_type']] ?? '';
                ?>
                <div class="p-4 flex items-start gap-4 <?= !$rule['active'] ? 'opacity-50' : '' ?>">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-[10px] orbitron px-2 py-0.5 rounded <?= $typeClr ?>"><?= $rule['rule_type'] ?></span>
                            <span class="text-white font-medium"><?= htmlspecialchars($rule['rule_name']) ?></span>
                            <span class="text-xs text-slate-500">(prio <?= $rule['priority'] ?>)</span>
                        </div>
                        <p class="text-xs text-slate-400 mb-2"><?= htmlspecialchars($rule['description'] ?? '') ?></p>
                        <?php if ($rule['rule_sql']): ?>
                        <pre class="text-xs text-cyan-300 font-mono bg-black/40 p-2 rounded border border-cyan-500/20 whitespace-pre-wrap"><?= htmlspecialchars($rule['rule_sql']) ?></pre>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-2 flex-shrink-0">
                        <button onclick='openRuleModal(<?= json_encode($rule, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="orbitron px-3 py-1 text-xs text-cyan-400 bg-cyan-500/20 border border-cyan-500/50 rounded">✏ EDIT</button>
                        <form method="POST" onsubmit="return confirm('Eliminare questa regola?');">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="rule_delete">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <button type="submit" class="orbitron px-3 py-1 text-xs text-red-400 bg-red-500/20 border border-red-500/50 rounded">🗑</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php elseif ($tab === 'cities'): ?>
    <!-- ========== TAB CITTÀ ========== -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <div class="flex gap-2 flex-wrap">
            <?php foreach ($cityLists as $cl): ?>
            <a href="?tab=cities&list=<?= urlencode($cl['list_code']) ?>"
               class="orbitron text-xs px-3 py-1.5 rounded <?= $selectedList === $cl['list_code'] ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/50' : 'bg-slate-800/50 text-slate-400 border border-slate-700' ?>">
                <?= str_replace('_',' ',$cl['list_code']) ?> <span class="text-slate-500">(<?= $cl['active'] ?>/<?= $cl['tot'] ?>)</span>
            </a>
            <?php endforeach; ?>
        </div>

        <form method="POST" class="flex gap-2">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="city_new_list">
            <input type="text" name="new_list_code" placeholder="nuova_lista" pattern="[a-z0-9_]+" required class="form-input py-1.5 text-xs">
            <button type="submit" class="orbitron px-3 py-1.5 text-xs text-cyan-400 bg-cyan-500/20 border border-cyan-500/50 rounded">+ NUOVA LISTA</button>
        </form>
    </div>

    <!-- Form aggiunta città -->
    <div class="glass rounded-xl p-4 mb-4">
        <form method="POST" class="flex flex-col md:flex-row gap-3">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="city_add">
            <input type="hidden" name="list_code" value="<?= htmlspecialchars($selectedList) ?>">
            <div class="flex-1">
                <label class="form-label">Aggiungi città a <span class="text-cyan-400"><?= htmlspecialchars($selectedList) ?></span></label>
                <textarea name="cities_bulk" rows="3" class="form-input font-mono text-xs"
                          placeholder="Una per riga. Formato: NOMECITTA oppure NOMECITTA,PROV. Es:&#10;MILANO,MI&#10;ROMA,RM&#10;CORSICO,MI"></textarea>
            </div>
            <div class="flex items-end">
                <button type="submit" class="btn-primary orbitron px-5 py-2.5 text-white font-bold rounded-lg text-xs tracking-wider"
                        style="background: linear-gradient(135deg, #22d3ee, #6366f1);">+ AGGIUNGI</button>
            </div>
        </form>
    </div>

    <!-- Elenco città -->
    <div class="glass rounded-xl p-4">
        <?php if (empty($cities)): ?>
        <p class="text-slate-500 text-center py-8">Nessuna città in questa lista. Usa il form sopra per aggiungerne.</p>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
            <?php foreach ($cities as $c): ?>
            <div class="flex items-center justify-between bg-slate-900/40 rounded px-3 py-2 border border-slate-700/50 <?= !$c['active'] ? 'opacity-50' : '' ?>">
                <div class="flex-1 min-w-0">
                    <div class="text-xs text-slate-200 truncate"><?= htmlspecialchars($c['city_name']) ?></div>
                    <?php if ($c['province']): ?><div class="text-[10px] text-slate-500">(<?= htmlspecialchars($c['province']) ?>)</div><?php endif; ?>
                </div>
                <div class="flex gap-1">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="city_toggle">
                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                        <button type="submit" title="Toggle" class="text-xs <?= $c['active'] ? 'text-green-400' : 'text-slate-500' ?>"><?= $c['active'] ? '●' : '○' ?></button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Rimuovere <?= htmlspecialchars($c['city_name']) ?>?');">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="city_delete">
                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                        <button type="submit" title="Elimina" class="text-xs text-red-400 hover:text-red-300">✕</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($tab === 'products'): ?>
    <!-- ========== TAB PRODOTTI ========== -->
    <div class="flex justify-end mb-4">
        <button onclick='openProductModal(null)' class="btn-primary orbitron px-4 py-2 text-sm text-white font-bold rounded-lg tracking-wider"
                style="background: linear-gradient(135deg, #22d3ee, #6366f1, #a855f7);">+ NUOVO PRODOTTO</button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <?php foreach ($products as $p): ?>
        <div class="glass rounded-xl p-4 <?= !$p['active'] ? 'opacity-50' : '' ?>">
            <div class="flex items-start justify-between gap-2">
                <div class="flex-1 min-w-0">
                    <h3 class="orbitron text-sm font-bold text-cyan-400"><?= htmlspecialchars($p['label']) ?></h3>
                    <p class="text-xs text-slate-500 font-mono mt-1"><?= htmlspecialchars($p['code']) ?></p>
                </div>
                <button onclick='openProductModal(<?= json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' class="orbitron px-2 py-1 text-[10px] text-cyan-400 bg-cyan-500/20 border border-cyan-500/50 rounded">✏</button>
            </div>
            <p class="text-xs text-slate-400 mt-2"><?= htmlspecialchars($p['description'] ?? '') ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<!-- ========== MODAL DB EDIT ========== -->
<div id="modalDb" class="modal-bg">
    <div class="modal-box p-6">
        <h3 class="orbitron text-xl text-cyan-400 mb-4">MODIFICA DATABASE</h3>
        <form method="POST" id="formDb" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="db_save">
            <input type="hidden" name="id" id="db_id">

            <div>
                <label class="form-label">Label</label>
                <input type="text" name="label" id="db_label" required class="form-input">
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="form-label">Tipo</label>
                    <select name="tipo_principale" id="db_tipo_principale" class="form-input">
                        <option value="residenziale">Residenziale</option>
                        <option value="business">Business</option>
                        <option value="gas">Gas</option>
                        <option value="misto">Misto</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Priorità (1-10)</label>
                    <input type="number" name="priorita" id="db_priorita" min="1" max="10" class="form-input font-mono">
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="active" id="db_active" class="w-5 h-5">
                        <span class="orbitron text-xs text-cyan-400">ATTIVO</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="form-label">Prodotti adatti</label>
                <div id="db_prodotti_container" class="flex flex-wrap gap-2 p-3 bg-slate-900/50 rounded-lg border border-slate-700 min-h-[50px]"></div>
                <div class="mt-2 flex gap-2">
                    <select id="db_prodotto_add" class="form-input flex-1 text-xs">
                        <option value="">— Aggiungi prodotto —</option>
                        <?php foreach ($products as $p): if (!$p['active']) continue; ?>
                        <option value="<?= htmlspecialchars($p['code']) ?>"><?= htmlspecialchars($p['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="addProdotto()" class="orbitron px-3 py-1 text-xs text-cyan-400 bg-cyan-500/20 border border-cyan-500/50 rounded">+ ADD</button>
                </div>
            </div>

            <div>
                <label class="form-label">Descrizione (mostrata a Claude)</label>
                <textarea name="description" id="db_description" rows="3" class="form-input text-xs"></textarea>
            </div>

            <div>
                <label class="form-label">Note interne (non usate da Claude)</label>
                <textarea name="note_interne" id="db_note_interne" rows="2" class="form-input text-xs"></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-3 border-t border-slate-700/50">
                <button type="button" onclick="closeModal('modalDb')" class="px-4 py-2 text-slate-400 hover:text-slate-200 text-sm">Annulla</button>
                <button type="submit" class="btn-primary orbitron px-6 py-2 text-white font-bold rounded-lg text-sm tracking-wider" style="background: linear-gradient(135deg, #22d3ee, #6366f1);">SALVA</button>
            </div>
        </form>
    </div>
</div>

<!-- ========== MODAL RULE EDIT ========== -->
<div id="modalRule" class="modal-bg">
    <div class="modal-box p-6">
        <h3 class="orbitron text-xl text-cyan-400 mb-4" id="modalRuleTitle">MODIFICA REGOLA</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="rule_save">
            <input type="hidden" name="id" id="rule_id">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="form-label">Prodotto</label>
                    <select name="product_code" id="rule_product_code" required class="form-input">
                        <?php foreach ($products as $p): if (!$p['active']) continue; ?>
                        <option value="<?= htmlspecialchars($p['code']) ?>"><?= htmlspecialchars($p['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Tipo regola</label>
                    <select name="rule_type" id="rule_type" class="form-input">
                        <option value="exclude">Exclude (escludi)</option>
                        <option value="include">Include (includi solo)</option>
                        <option value="transform">Transform</option>
                        <option value="note">Note (solo testo)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="form-label">Nome regola</label>
                <input type="text" name="rule_name" id="rule_name" required class="form-input">
            </div>

            <div>
                <label class="form-label">Descrizione (per Claude)</label>
                <textarea name="description" id="rule_description" rows="2" class="form-input text-xs"></textarea>
            </div>

            <div>
                <label class="form-label">SQL WHERE (opzionale)</label>
                <textarea name="rule_sql" id="rule_sql" rows="4" class="form-input font-mono text-xs"
                          placeholder="Es: UPPER(TRIM(localita)) NOT IN (SELECT ... FROM ai_laboratory.city_exclusions WHERE ...)"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="form-label">Priorità</label>
                    <input type="number" name="priority" id="rule_priority" min="1" max="99" value="10" class="form-input font-mono">
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="active" id="rule_active" checked class="w-5 h-5">
                        <span class="orbitron text-xs text-cyan-400">ATTIVA</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-3 border-t border-slate-700/50">
                <button type="button" onclick="closeModal('modalRule')" class="px-4 py-2 text-slate-400 hover:text-slate-200 text-sm">Annulla</button>
                <button type="submit" class="btn-primary orbitron px-6 py-2 text-white font-bold rounded-lg text-sm tracking-wider" style="background: linear-gradient(135deg, #22d3ee, #6366f1);">SALVA</button>
            </div>
        </form>
    </div>
</div>

<!-- ========== MODAL PRODUCT EDIT ========== -->
<div id="modalProduct" class="modal-bg">
    <div class="modal-box p-6">
        <h3 class="orbitron text-xl text-cyan-400 mb-4">PRODOTTO</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="product_save">
            <input type="hidden" name="id" id="prod_id">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="form-label">Codice (univoco)</label>
                    <input type="text" name="code" id="prod_code" required pattern="[a-z0-9_]+" class="form-input font-mono">
                </div>
                <div>
                    <label class="form-label">Ordine</label>
                    <input type="number" name="display_order" id="prod_display_order" min="1" max="99" value="10" class="form-input font-mono">
                </div>
            </div>
            <div>
                <label class="form-label">Label visibile</label>
                <input type="text" name="label" id="prod_label" required class="form-input">
            </div>
            <div>
                <label class="form-label">Descrizione</label>
                <textarea name="description" id="prod_description" rows="3" class="form-input text-xs"></textarea>
            </div>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="active" id="prod_active" checked class="w-5 h-5">
                <span class="orbitron text-xs text-cyan-400">ATTIVO</span>
            </label>
            <div class="flex justify-end gap-3 pt-3 border-t border-slate-700/50">
                <button type="button" onclick="closeModal('modalProduct')" class="px-4 py-2 text-slate-400 hover:text-slate-200 text-sm">Annulla</button>
                <button type="submit" class="btn-primary orbitron px-6 py-2 text-white font-bold rounded-lg text-sm tracking-wider" style="background: linear-gradient(135deg, #22d3ee, #6366f1);">SALVA</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openDbModal(data) {
    document.getElementById('db_id').value = data.id;
    document.getElementById('db_label').value = data.label;
    document.getElementById('db_tipo_principale').value = data.tipo_principale;
    document.getElementById('db_priorita').value = data.priorita;
    document.getElementById('db_active').checked = !!+data.active;
    document.getElementById('db_description').value = data.description || '';
    document.getElementById('db_note_interne').value = data.note_interne || '';
    const prodotti = JSON.parse(data.prodotti_adatti || '[]');
    const cont = document.getElementById('db_prodotti_container');
    cont.innerHTML = '';
    prodotti.forEach(p => addProdottoChip(p));
    openModal('modalDb');
}
function addProdotto() {
    const sel = document.getElementById('db_prodotto_add');
    const v = sel.value;
    if (!v) return;
    const current = [...document.querySelectorAll('#db_prodotti_container input')].map(i => i.value);
    if (!current.includes(v)) addProdottoChip(v);
    sel.value = '';
}
function addProdottoChip(code) {
    const cont = document.getElementById('db_prodotti_container');
    const el = document.createElement('span');
    el.className = 'chip';
    el.innerHTML = `<input type="hidden" name="prodotti_adatti[]" value="${code}">${code}<button type="button" onclick="this.parentElement.remove()">✕</button>`;
    cont.appendChild(el);
}

function openRuleModal(data) {
    if (data) {
        document.getElementById('modalRuleTitle').textContent = 'MODIFICA REGOLA';
        document.getElementById('rule_id').value = data.id;
        document.getElementById('rule_product_code').value = data.product_code;
        document.getElementById('rule_type').value = data.rule_type;
        document.getElementById('rule_name').value = data.rule_name;
        document.getElementById('rule_description').value = data.description || '';
        document.getElementById('rule_sql').value = data.rule_sql || '';
        document.getElementById('rule_priority').value = data.priority;
        document.getElementById('rule_active').checked = !!+data.active;
    } else {
        document.getElementById('modalRuleTitle').textContent = 'NUOVA REGOLA';
        document.getElementById('rule_id').value = 0;
        document.getElementById('rule_product_code').value = '<?= $products[0]['code'] ?? '' ?>';
        document.getElementById('rule_type').value = 'exclude';
        document.getElementById('rule_name').value = '';
        document.getElementById('rule_description').value = '';
        document.getElementById('rule_sql').value = '';
        document.getElementById('rule_priority').value = 10;
        document.getElementById('rule_active').checked = true;
    }
    openModal('modalRule');
}

function openProductModal(data) {
    if (data) {
        document.getElementById('prod_id').value = data.id;
        document.getElementById('prod_code').value = data.code;
        document.getElementById('prod_label').value = data.label;
        document.getElementById('prod_description').value = data.description || '';
        document.getElementById('prod_display_order').value = data.display_order;
        document.getElementById('prod_active').checked = !!+data.active;
    } else {
        document.getElementById('prod_id').value = 0;
        document.getElementById('prod_code').value = '';
        document.getElementById('prod_label').value = '';
        document.getElementById('prod_description').value = '';
        document.getElementById('prod_display_order').value = 10;
        document.getElementById('prod_active').checked = true;
    }
    openModal('modalProduct');
}

document.querySelectorAll('.modal-bg').forEach(m => {
    m.addEventListener('click', (e) => { if (e.target === m) m.classList.remove('open'); });
});
</script>

<?php aiRenderFooter(); ?>
