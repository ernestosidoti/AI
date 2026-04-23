<?php
/**
 * LTM AI LABORATORY — Pagina principale (v2, con selettore prodotto)
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/TableRegistry.php';
require_once __DIR__ . '/lib/IntelRegistry.php';
require_once __DIR__ . '/lib/CostTracker.php';
require_once __DIR__ . '/lib/ClaudeAPI.php';

aiSecurityHeaders();
aiRequireAuth();

$db = aiDb();
$apiKeyConfigured = !empty(ClaudeAPI::loadApiKey($db));
$stats = CostTracker::getStats($db);
$products = IntelRegistry::getProducts($db);
$allSources = IntelRegistry::getAllSources($db, true);
$csrf = aiCsrfToken();

$refineId = (int)($_GET['refine'] ?? 0);
$refineQuery = null;
if ($refineId > 0) {
    $stmt = $db->prepare("SELECT * FROM queries WHERE id = ?");
    $stmt->execute([$refineId]);
    $refineQuery = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Ordine preselezionato (via ?order_id=X) — costruisce prompt dall'ordine
$preselOrderId = (int)($_GET['order_id'] ?? 0);
$preselOrder = null;
$preselPrompt = '';
$preselProduct = '';

if ($preselOrderId > 0) {
    $backDb = remoteDb(AI_BACKOFFICE_DB);
    $stmt = $backDb->prepare("
        SELECT o.*, c.ragione_sociale, c.partita_iva, c.comune AS cliente_comune, p.nome AS prodotto_nome
        FROM orders o
        LEFT JOIN clientes c ON o.cliente_id = c.id
        LEFT JOIN prodotti p ON o.prodotto_id = p.id
        WHERE o.id = ?
    ");
    $stmt->execute([$preselOrderId]);
    $preselOrder = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($preselOrder) {
        // Mappa prodotto backoffice → product_code AI
        $mapStmt = $db->prepare("SELECT code FROM products_catalog WHERE backoffice_product_id = ?");
        $mapStmt->execute([$preselOrder['prodotto_id']]);
        $preselProduct = $mapStmt->fetchColumn() ?: '';

        // Costruisci prompt da campi ordine
        $parts = [];
        if (!empty($preselOrder['quantita']) && (int)$preselOrder['quantita'] > 0) {
            $parts[] = (int)$preselOrder['quantita'] . ' contatti';
        }
        if (!empty($preselOrder['tipo'])) {
            $parts[] = 'tipo ' . $preselOrder['tipo'];
        }
        if (!empty($preselOrder['zona']) && !in_array(strtolower(trim($preselOrder['zona'])), ['note','vedi note','italia','da file',''])) {
            $parts[] = 'zona ' . $preselOrder['zona'];
        }
        $base = implode(', ', $parts);
        $note = trim($preselOrder['note'] ?? '');
        $preselPrompt = $base;
        if ($note !== '') {
            $preselPrompt .= ($base ? ".\n\nSpecifiche cliente:\n" : '') . $note;
        }
    }
}

// Cliente preselezionato (via ?cliente_id=X o da ordine o da refine)
$preselClienteId = (int)($_GET['cliente_id'] ?? $preselOrder['cliente_id'] ?? $refineQuery['cliente_id'] ?? 0);
$preselCliente = null;
if ($preselClienteId > 0) {
    if (!isset($backDb)) $backDb = remoteDb(AI_BACKOFFICE_DB);
    $stmt = $backDb->prepare("SELECT id, ragione_sociale, partita_iva, comune FROM clientes WHERE id = ?");
    $stmt->execute([$preselClienteId]);
    $preselCliente = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

aiRenderHeader('Ricerca AI', 'ricerca');
?>
<style>
.bg-particles { position: fixed; inset: 0; z-index: 0; pointer-events: none; }
.particle { position: absolute; width: 2px; height: 2px; background: #22d3ee; border-radius: 50%; box-shadow: 0 0 4px #22d3ee, 0 0 8px #22d3ee; animation: float 15s linear infinite; }
@keyframes float { from { transform: translateY(100vh) translateX(0); opacity: 0; } 10% { opacity: 1; } 90% { opacity: 1; } to { transform: translateY(-10vh) translateX(50px); opacity: 0; } }
.product-card { cursor: pointer; transition: all 0.3s ease; position: relative; }
.product-card.selected { border-color: #22d3ee !important; background: linear-gradient(135deg, rgba(34,211,238,0.15), rgba(99,102,241,0.1)); box-shadow: 0 0 25px rgba(34,211,238,0.4); }
.product-card.selected::after { content: '✓'; position: absolute; top: 8px; right: 8px; width: 22px; height: 22px; background: linear-gradient(135deg, #22d3ee, #6366f1); color: #000; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 12px; }
.scan-line { position: relative; overflow: hidden; }
.scan-line::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, #22d3ee, transparent); animation: scan 3s linear infinite; }
@keyframes scan { from { top: -2px; } to { top: 100%; } }
.spinner-hex { width: 60px; height: 60px; animation: spin 2s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.neon-text { text-shadow: 0 0 10px rgba(34,211,238,0.5); }
</style>

<div class="bg-particles" id="particles"></div>

<main class="relative z-10 max-w-6xl mx-auto px-6 py-8">

    <!-- Stats costi -->
    <div class="flex items-center justify-between mb-8 flex-wrap gap-4">
        <div>
            <h1 class="orbitron text-3xl font-black neon-text bg-gradient-to-r from-cyan-400 via-purple-500 to-pink-500 bg-clip-text text-transparent">
                ESTRAZIONE DATI
            </h1>
            <p class="text-slate-400 text-sm mt-1">Descrivi la lista che ti serve. Claude sceglie i database giusti.</p>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <div class="glass px-3 py-2 rounded-lg text-xs">
                <span class="text-cyan-400 orbitron">OGGI:</span>
                <span class="text-white font-bold">$<?= number_format($stats['oggi']['cost_usd'], 4) ?></span>
                <span class="text-slate-500">(<?= $stats['oggi']['queries'] ?>)</span>
            </div>
            <div class="glass px-3 py-2 rounded-lg text-xs">
                <span class="text-purple-400 orbitron">MESE:</span>
                <span class="text-white font-bold">$<?= number_format($stats['mese']['cost_usd'], 4) ?></span>
            </div>
        </div>
    </div>

    <?php if (!$apiKeyConfigured): ?>
    <div class="glass rounded-xl p-5 mb-6 border-2 border-yellow-500/60">
        <h3 class="orbitron text-yellow-400 font-bold mb-1">⚠ API CORE NON CONFIGURATO</h3>
        <p class="text-slate-300 text-sm">Inserisci la Anthropic API key in <a href="settings.php" class="text-cyan-400 underline">Settings</a>.</p>
    </div>
    <?php endif; ?>

    <?php if ($preselOrder): ?>
    <div class="glass rounded-xl p-5 mb-6 border-2 border-yellow-500/60" style="box-shadow: 0 0 40px rgba(234,179,8,0.2)">
        <div class="flex items-start gap-4">
            <div class="text-yellow-400 text-3xl">🤖</div>
            <div class="flex-1">
                <h3 class="orbitron text-yellow-400 font-bold tracking-wider mb-1">ESECUZIONE ORDINE #<?= $preselOrder['id'] ?></h3>
                <p class="text-sm text-slate-300 mb-3">
                    Cliente: <span class="text-white font-bold"><?= htmlspecialchars($preselOrder['ragione_sociale'] ?? '-') ?></span>
                    · Prodotto: <span class="text-cyan-300"><?= htmlspecialchars($preselOrder['prodotto_nome'] ?? '-') ?></span>
                    · Qty: <span class="font-mono"><?= $preselOrder['quantita'] ? number_format((int)$preselOrder['quantita'],0,',','.') : '-' ?></span>
                </p>
                <p class="text-xs text-slate-400 mb-2">Cliente, prodotto e testo dell'ordine sono già caricati qui sotto. Rivedi il prompt prima di inviare a Claude.</p>
                <a href="ordini.php" class="text-xs text-slate-400 hover:text-slate-200">✕ Annulla esecuzione ordine</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($refineQuery): ?>
    <div class="glass rounded-xl p-5 mb-6 border-2 border-purple-500/50">
        <div class="flex items-start gap-4">
            <div class="text-purple-400 text-xl">🔄</div>
            <div class="flex-1">
                <h3 class="orbitron text-purple-400 font-bold tracking-wider mb-2">AFFINAMENTO — Query #<?= $refineQuery['id'] ?></h3>
                <p class="text-xs text-slate-400 mb-2">Scrivi solo la modifica da fare alla query precedente.</p>
                <div class="bg-slate-900/60 rounded p-3 border border-purple-500/20 mb-2">
                    <p class="text-xs text-purple-400 orbitron mb-1">PROMPT ORIGINALE</p>
                    <p class="text-slate-300 text-sm"><?= htmlspecialchars($refineQuery['user_prompt']) ?></p>
                </div>
                <a href="index.php" class="text-xs text-slate-400 hover:text-slate-200">✕ Annulla affinamento</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- STEP 0: CLIENTE -->
    <section class="mb-6">
        <div class="flex items-center gap-3 mb-3">
            <div class="orbitron text-xs text-yellow-400 tracking-widest bg-yellow-500/10 border border-yellow-500/30 px-3 py-1 rounded-full">STEP 0</div>
            <h2 class="orbitron text-base font-bold text-white">PER QUALE CLIENTE?</h2>
        </div>
        <div class="glass rounded-xl p-4">
            <div id="clienteSelected" class="<?= $preselCliente ? '' : 'hidden' ?> flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 flex-1">
                    <div class="w-10 h-10 rounded-full bg-yellow-500/20 border border-yellow-500/50 flex items-center justify-center">
                        <span class="orbitron text-yellow-400 text-sm">👤</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div id="clienteSelName" class="text-white font-bold truncate"><?= htmlspecialchars($preselCliente['ragione_sociale'] ?? '') ?></div>
                        <div id="clienteSelInfo" class="text-xs text-slate-400">
                            <?= $preselCliente ? 'P.IVA ' . htmlspecialchars($preselCliente['partita_iva'] ?? '-') . ' · ' . htmlspecialchars($preselCliente['comune'] ?? '') : '' ?>
                        </div>
                    </div>
                    <a id="clienteSelStorico" href="cliente_storico.php?id=<?= $preselClienteId ?>" class="orbitron text-xs text-cyan-400 hover:text-cyan-300">📋 STORICO</a>
                </div>
                <button onclick="resetCliente()" class="text-xs text-slate-400 hover:text-red-400">✕ cambia</button>
            </div>
            <div id="clienteSearch" class="<?= $preselCliente ? 'hidden' : '' ?>">
                <div class="relative">
                    <input id="clienteInput" type="text" placeholder="Cerca cliente per nome, P.IVA, CF, comune..." class="form-input" autocomplete="off">
                    <div id="clienteDropdown" class="hidden absolute z-40 left-0 right-0 top-full mt-1 bg-slate-900 border border-cyan-500/40 rounded-lg max-h-64 overflow-y-auto shadow-xl"></div>
                </div>
                <div class="flex items-center justify-between mt-2">
                    <p class="text-xs text-slate-500">Cliente opzionale. Se non lo selezioni, la query non verrà associata.</p>
                    <a href="nuovo_cliente.php" class="text-xs text-cyan-400 hover:text-cyan-300">+ nuovo cliente</a>
                </div>
            </div>
        </div>
    </section>

    <!-- STEP 1: PRODOTTO -->
    <section class="mb-6">
        <div class="flex items-center gap-3 mb-3">
            <div class="orbitron text-xs text-cyan-400 tracking-widest bg-cyan-500/10 border border-cyan-500/30 px-3 py-1 rounded-full">STEP 1</div>
            <h2 class="orbitron text-base font-bold text-white">CHE LISTA SERVE AL CLIENTE?</h2>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2">
            <?php foreach ($products as $p): ?>
            <div class="product-card glass rounded-lg p-3 text-center" data-product="<?= htmlspecialchars($p['code']) ?>">
                <div class="orbitron text-xs text-white font-bold mb-1"><?= htmlspecialchars($p['label']) ?></div>
                <div class="text-[10px] text-slate-500 font-mono"><?= htmlspecialchars($p['code']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-slate-500 mt-2" id="productInfo">Seleziona una categoria. Claude userà automaticamente i database più adatti e applicherà le regole prodotto.</p>
    </section>

    <!-- STEP 2: OVERRIDE DB (collapsible avanzato) -->
    <details class="mb-6">
        <summary class="cursor-pointer orbitron text-xs text-slate-400 hover:text-cyan-400">⚙️ AVANZATO: scegli tu i database (opzionale)</summary>
        <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2 bg-slate-900/30 p-3 rounded-lg border border-slate-700/50">
            <?php foreach ($allSources as $s): ?>
            <label class="flex items-start gap-2 p-2 hover:bg-slate-800/50 rounded cursor-pointer">
                <input type="checkbox" class="source-override mt-1" value="<?= htmlspecialchars($s['source_id']) ?>">
                <div class="flex-1 min-w-0">
                    <div class="text-xs text-white truncate"><?= htmlspecialchars($s['label']) ?></div>
                    <div class="text-[10px] text-slate-500"><?= $s['tipo_principale'] ?> · P<?= $s['priorita'] ?></div>
                </div>
            </label>
            <?php endforeach; ?>
        </div>
        <p class="text-[10px] text-slate-500 mt-2">Se selezioni manualmente, ignora la scelta automatica in base al prodotto.</p>
    </details>

    <!-- STEP 3: RICHIESTA -->
    <section class="mb-6">
        <div class="flex items-center gap-3 mb-3">
            <div class="orbitron text-xs text-purple-400 tracking-widest bg-purple-500/10 border border-purple-500/30 px-3 py-1 rounded-full">STEP 2</div>
            <h2 class="orbitron text-base font-bold text-white">DESCRIVI LA RICHIESTA</h2>
        </div>
        <div class="glass rounded-xl p-5">
            <textarea id="userPrompt" rows="<?= $preselPrompt ? 6 : 3 ?>"
                placeholder="Es: 2000 contatti di Brescia con mobile valido e trader diverso da ENEL"
                class="w-full bg-transparent text-white placeholder-slate-500 font-mono text-sm border-0 focus:ring-0 focus:outline-none resize-none"><?= htmlspecialchars($preselPrompt) ?></textarea>
            <div class="flex items-center justify-between mt-3 pt-3 border-t border-slate-700/50">
                <p class="text-xs text-slate-400"><span id="productBadge" class="hidden"></span></p>
                <button id="btnInterpret" class="orbitron btn-primary px-6 py-2.5 text-white font-bold rounded-lg text-sm tracking-wider disabled:opacity-40 disabled:cursor-not-allowed" disabled
                        style="background: linear-gradient(135deg, #22d3ee 0%, #6366f1 50%, #a855f7 100%); transition: all 0.3s;">
                    CHIEDI A CLAUDE
                </button>
            </div>
        </div>
    </section>

    <!-- RISPOSTA AI -->
    <section id="interpretSection" class="mb-6 hidden">
        <div class="flex items-center gap-3 mb-3">
            <div class="orbitron text-xs text-pink-400 tracking-widest bg-pink-500/10 border border-pink-500/30 px-3 py-1 rounded-full">STEP 3</div>
            <h2 class="orbitron text-base font-bold text-white">CLAUDE HA CAPITO</h2>
        </div>
        <div class="glass rounded-xl overflow-hidden scan-line">
            <div class="p-5 border-b border-slate-700/50">
                <p class="orbitron text-xs text-cyan-400 tracking-widest mb-2">INTERPRETAZIONE</p>
                <p id="interpretationText" class="text-white text-sm leading-relaxed"></p>
                <div class="flex items-center justify-between mt-3 text-xs text-slate-400">
                    <span id="estimatedRecords"></span>
                </div>
            </div>
            <div class="p-5 bg-slate-900/50">
                <details id="sqlDetails">
                    <summary class="cursor-pointer orbitron text-xs text-purple-400 tracking-widest hover:text-purple-300 select-none">
                        ▸ VEDI SQL GENERATO
                    </summary>
                    <pre id="sqlText" class="text-xs text-cyan-300 font-mono whitespace-pre-wrap overflow-x-auto bg-black/40 p-3 rounded border border-cyan-500/20 mt-3"></pre>
                </details>
            </div>
            <div class="p-5 bg-slate-900/70 flex items-center justify-between flex-wrap gap-3">
                <div class="text-xs text-slate-400">
                    <span class="orbitron text-pink-400">COSTO:</span>
                    <span id="queryCost" class="text-white font-mono ml-2">—</span>
                    <span class="text-slate-500 ml-4" id="queryTokens"></span>
                </div>
                <div class="flex gap-2">
                    <button id="btnCancel" class="px-5 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded-lg text-sm">Annulla</button>
                    <button id="btnExecute" class="btn-primary orbitron px-6 py-2 text-white font-bold rounded-lg text-sm tracking-wider"
                            style="background: linear-gradient(135deg, #22d3ee 0%, #6366f1 50%, #a855f7 100%);">
                        ESEGUI & SCARICA
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- RISULTATO -->
    <section id="resultSection" class="mb-6 hidden">
        <div class="glass rounded-xl overflow-hidden border-green-500/50">
            <div class="p-5 text-center border-b border-slate-700/50">
                <svg class="w-12 h-12 mx-auto mb-2 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <h3 class="orbitron text-lg font-bold text-green-400">ESTRAZIONE COMPLETATA</h3>
                <p id="resultMessage" class="text-slate-300 text-sm mt-1"></p>
            </div>

            <!-- Preview dati -->
            <div class="p-5 bg-slate-900/40">
                <div class="flex items-center justify-between mb-3">
                    <p class="orbitron text-xs text-cyan-400 tracking-widest">ANTEPRIMA DATI</p>
                    <p id="previewInfo" class="text-xs text-slate-500"></p>
                </div>
                <div id="previewContainer" class="overflow-auto border border-slate-700/50 rounded-lg max-h-96 bg-black/40">
                    <table id="previewTable" class="text-xs font-mono">
                        <thead class="bg-slate-800/80 sticky top-0 z-10"></thead>
                        <tbody class="text-slate-300"></tbody>
                    </table>
                </div>
                <div class="mt-3 flex items-center gap-2 text-xs text-slate-400">
                    <span>Filtra:</span>
                    <input id="previewFilter" type="text" placeholder="Digita per filtrare la preview..."
                           class="form-input flex-1 py-1 text-xs">
                </div>
            </div>

            <!-- Azioni -->
            <div class="p-5 bg-slate-900/70 flex gap-3 justify-center flex-wrap border-t border-slate-700/50">
                <a id="downloadLink" href="#" class="btn-primary orbitron inline-block px-6 py-3 text-white font-bold rounded-lg tracking-wider text-sm"
                   style="background: linear-gradient(135deg, #22d3ee 0%, #6366f1 50%, #a855f7 100%);">⬇ SCARICA FILE</a>
                <a id="refineLink" href="#" class="orbitron inline-block px-5 py-3 bg-purple-500/20 hover:bg-purple-500/30 border border-purple-500/50 text-purple-400 font-bold rounded-lg tracking-wider text-sm">🔄 AFFINA QUERY</a>
                <button onclick="document.getElementById('userPrompt').focus(); window.scrollTo({top:0,behavior:'smooth'});" class="orbitron inline-block px-5 py-3 bg-slate-700/50 hover:bg-slate-700 border border-slate-600 text-slate-300 font-bold rounded-lg tracking-wider text-sm">+ NUOVA ESTRAZIONE</button>
            </div>
        </div>
    </section>

    <style>
    #previewTable { width: max-content; min-width: 100%; border-collapse: collapse; }
    #previewTable th { padding: 6px 10px; text-align: left; font-family: 'Orbitron', monospace; font-size: 10px; color: #22d3ee; border-bottom: 1px solid rgba(99,102,241,0.3); white-space: nowrap; }
    #previewTable td { padding: 4px 10px; border-bottom: 1px solid rgba(99,102,241,0.1); white-space: nowrap; max-width: 280px; overflow: hidden; text-overflow: ellipsis; }
    #previewTable tr:hover td { background: rgba(34,211,238,0.05); }
    </style>
</main>

<div id="loader" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden flex items-center justify-center">
    <div class="text-center">
        <svg class="spinner-hex mx-auto" viewBox="0 0 60 60">
            <defs><linearGradient id="loaderGrad"><stop offset="0%" stop-color="#22d3ee"/><stop offset="100%" stop-color="#a855f7"/></linearGradient></defs>
            <polygon points="30,5 55,20 55,45 30,55 5,45 5,20" fill="none" stroke="url(#loaderGrad)" stroke-width="3" stroke-dasharray="20 5"/>
        </svg>
        <p id="loaderText" class="orbitron text-cyan-400 mt-4 tracking-widest text-sm">PROCESSING...</p>
    </div>
</div>

<script>
const CSRF = '<?= $csrf ?>';
const API_KEY_OK = <?= $apiKeyConfigured ? 'true' : 'false' ?>;
const PARENT_ID = <?= $refineId > 0 ? $refineId : 0 ?>;
let selectedProduct = null;
let overrideSources = [];
let currentQueryId = null;
let selectedClienteId = <?= $preselClienteId ?: 0 ?>;

// Preselect prodotto da ordine (se presente)
const PRESEL_PRODUCT = '<?= htmlspecialchars($preselProduct) ?>';
if (PRESEL_PRODUCT) {
    window.addEventListener('DOMContentLoaded', () => {
        const card = document.querySelector(`.product-card[data-product="${PRESEL_PRODUCT}"]`);
        if (card) { card.click(); }
    });
}

// Autocomplete clienti
let searchTimeout = null;
const clienteInput = document.getElementById('clienteInput');
const clienteDropdown = document.getElementById('clienteDropdown');

if (clienteInput) {
    clienteInput.addEventListener('input', (e) => {
        clearTimeout(searchTimeout);
        const q = e.target.value.trim();
        if (q.length < 2) { clienteDropdown.classList.add('hidden'); return; }
        searchTimeout = setTimeout(() => searchClienti(q), 300);
    });
    clienteInput.addEventListener('blur', () => setTimeout(() => clienteDropdown.classList.add('hidden'), 200));
    clienteInput.addEventListener('focus', (e) => {
        if (e.target.value.trim().length >= 2) searchClienti(e.target.value.trim());
    });
}

async function searchClienti(q) {
    try {
        const res = await fetch('api/search_clienti.php?q=' + encodeURIComponent(q));
        const data = await res.json();
        if (!data.success || !data.clienti.length) {
            clienteDropdown.innerHTML = '<div class="p-3 text-sm text-slate-500">Nessun cliente trovato. <a href="nuovo_cliente.php" class="text-cyan-400 underline">Crealo</a></div>';
            clienteDropdown.classList.remove('hidden');
            return;
        }
        clienteDropdown.innerHTML = data.clienti.map(c => `
            <div class="p-3 hover:bg-cyan-500/10 cursor-pointer border-b border-slate-800 last:border-0"
                 onclick="pickCliente(${c.id}, '${escAttr(c.ragione_sociale)}', '${escAttr(c.partita_iva || '')}', '${escAttr(c.comune || '')}')">
                <div class="text-sm text-white font-medium">${escapeHtml(c.ragione_sociale)}</div>
                <div class="text-xs text-slate-400 mt-0.5">
                    ${c.partita_iva ? 'P.IVA ' + escapeHtml(c.partita_iva) : ''}
                    ${c.comune ? ' · ' + escapeHtml(c.comune) : ''}
                </div>
            </div>
        `).join('');
        clienteDropdown.classList.remove('hidden');
    } catch (e) { console.error(e); }
}

function escAttr(s) { return String(s || '').replace(/'/g, "\\'").replace(/"/g, '&quot;'); }

function pickCliente(id, nome, piva, comune) {
    selectedClienteId = id;
    document.getElementById('clienteSelName').textContent = nome;
    document.getElementById('clienteSelInfo').textContent = (piva ? 'P.IVA ' + piva : '') + (comune ? ' · ' + comune : '');
    document.getElementById('clienteSelStorico').href = 'cliente_storico.php?id=' + id;
    document.getElementById('clienteSelected').classList.remove('hidden');
    document.getElementById('clienteSearch').classList.add('hidden');
    clienteDropdown.classList.add('hidden');
}

function resetCliente() {
    selectedClienteId = 0;
    document.getElementById('clienteSelected').classList.add('hidden');
    document.getElementById('clienteSearch').classList.remove('hidden');
    clienteInput.value = '';
    clienteInput.focus();
}

// Particles
const pc = document.getElementById('particles');
for (let i = 0; i < 20; i++) {
    const p = document.createElement('div');
    p.className = 'particle';
    p.style.left = Math.random() * 100 + '%';
    p.style.animationDelay = (Math.random() * 15) + 's';
    p.style.animationDuration = (10 + Math.random() * 10) + 's';
    pc.appendChild(p);
}

document.querySelectorAll('.product-card').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('.product-card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        selectedProduct = card.dataset.product;
        const badge = document.getElementById('productBadge');
        badge.classList.remove('hidden');
        badge.innerHTML = `<span class="text-cyan-400 orbitron">PRODOTTO:</span> <span class="text-white">${card.querySelector('.text-white').textContent}</span>`;
        updateUI();
    });
});

document.querySelectorAll('.source-override').forEach(cb => {
    cb.addEventListener('change', () => {
        overrideSources = [...document.querySelectorAll('.source-override:checked')].map(c => c.value);
        updateUI();
    });
});

document.getElementById('userPrompt').addEventListener('input', updateUI);

function updateUI() {
    const prompt = document.getElementById('userPrompt').value.trim();
    const canSubmit = (selectedProduct || overrideSources.length > 0) && prompt.length >= 5 && API_KEY_OK;
    document.getElementById('btnInterpret').disabled = !canSubmit;
}

function showLoader(t) { document.getElementById('loaderText').textContent = t; document.getElementById('loader').classList.remove('hidden'); }
function hideLoader() { document.getElementById('loader').classList.add('hidden'); }

// Render preview tabella
let previewData = [];
let previewCols = [];
function renderPreview(columns, rows, totalCount, limit) {
    previewCols = columns || [];
    previewData = rows || [];
    const thead = document.querySelector('#previewTable thead');
    const tbody = document.querySelector('#previewTable tbody');
    thead.innerHTML = '<tr>' + previewCols.map(c => `<th>${escapeHtml(c)}</th>`).join('') + '</tr>';
    drawPreviewRows(previewData);
    const showing = Math.min(previewData.length, totalCount);
    document.getElementById('previewInfo').textContent = `Mostro ${showing} di ${totalCount} record totali (scarica il file per averli tutti)`;
}
function drawPreviewRows(rows) {
    const tbody = document.querySelector('#previewTable tbody');
    tbody.innerHTML = rows.map(r =>
        '<tr>' + previewCols.map(c => `<td title="${escapeAttr(r[c] || '')}">${escapeHtml(r[c] || '')}</td>`).join('') + '</tr>'
    ).join('');
}
function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function escapeAttr(s) { return String(s || '').replace(/"/g, '&quot;'); }

// Filtro preview
document.addEventListener('DOMContentLoaded', () => {
    const filterInput = document.getElementById('previewFilter');
    if (filterInput) filterInput.addEventListener('input', e => {
        const q = e.target.value.toLowerCase().trim();
        if (!q) { drawPreviewRows(previewData); return; }
        const filtered = previewData.filter(r =>
            Object.values(r).some(v => String(v || '').toLowerCase().includes(q))
        );
        drawPreviewRows(filtered);
    });
});

// Indentazione base SQL per leggibilità
function formatSql(sql) {
    if (!sql) return '';
    let s = sql.replace(/\s+/g, ' ').trim();
    const keywords = ['SELECT','FROM','WHERE','AND','OR','ORDER BY','GROUP BY','HAVING','LIMIT','UNION','LEFT JOIN','RIGHT JOIN','INNER JOIN','JOIN','ON'];
    keywords.forEach(kw => {
        const re = new RegExp('\\b' + kw.replace(' ', '\\s+') + '\\b', 'gi');
        s = s.replace(re, '\n' + kw);
    });
    // Prima riga senza newline iniziale
    s = s.replace(/^\n+/, '');
    // Indenta AND/OR rispetto a WHERE
    s = s.replace(/\n(AND|OR)\b/gi, '\n    $1');
    return s;
}

document.getElementById('btnInterpret').addEventListener('click', async () => {
    const prompt = document.getElementById('userPrompt').value.trim();
    showLoader('INTERROGATING CLAUDE...');
    try {
        const res = await fetch('api/interpret.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: CSRF, prompt,
                product_code: selectedProduct || '',
                sources: overrideSources,
                parent_query_id: PARENT_ID,
                cliente_id: selectedClienteId
            })
        });
        const data = await res.json();
        hideLoader();
        if (!data.success) { alert('Errore: ' + (data.error || '?')); return; }
        currentQueryId = data.query_id;
        document.getElementById('interpretationText').textContent = data.interpretation;
        document.getElementById('sqlText').textContent = formatSql(data.sql);
        document.getElementById('sqlDetails').open = false;
        document.getElementById('estimatedRecords').textContent = 'Stima: ' + (data.estimated_records || '?');
        document.getElementById('queryCost').textContent = '$' + data.cost_usd.toFixed(6);
        document.getElementById('queryTokens').textContent = `${data.input_tokens}/${data.output_tokens} tok`;
        document.getElementById('interpretSection').classList.remove('hidden');
        document.getElementById('resultSection').classList.add('hidden');
        document.getElementById('interpretSection').scrollIntoView({ behavior: 'smooth' });
    } catch (e) { hideLoader(); alert('Errore: ' + e.message); }
});

document.getElementById('btnCancel').addEventListener('click', () => {
    document.getElementById('interpretSection').classList.add('hidden');
    currentQueryId = null;
});

document.getElementById('btnExecute').addEventListener('click', async () => {
    if (!currentQueryId) return;
    showLoader('ESTRAZIONE IN CORSO...');
    try {
        const res = await fetch('api/extract.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF, query_id: currentQueryId })
        });
        const data = await res.json();
        hideLoader();
        if (!data.success) { alert('Errore: ' + (data.error || '?')); return; }
        document.getElementById('resultMessage').textContent = data.records_count + ' record estratti in ' + data.elapsed_ms + 'ms';
        document.getElementById('downloadLink').href = 'api/download.php?id=' + currentQueryId;
        document.getElementById('refineLink').href = 'index.php?refine=' + currentQueryId;

        // Popola preview
        renderPreview(data.columns, data.preview, data.records_count, data.preview_limit);

        document.getElementById('resultSection').classList.remove('hidden');
        document.getElementById('resultSection').scrollIntoView({ behavior: 'smooth' });
    } catch (e) { hideLoader(); alert('Errore: ' + e.message); }
});
</script>

<?php aiRenderFooter(); ?>
