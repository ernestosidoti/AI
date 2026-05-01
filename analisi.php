<?php
/**
 * /ai/analisi.php — Analisi statistica + estrazione contatti
 * Design corporate slate/blue/emerald (lib/layout.php)
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/Analisi.php';

aiSecurityHeaders();
aiRequireAuth();

$regioni = Analisi::listRegioni();
sort($regioni);

aiRenderHeader('Analisi & Estrazione', 'analisi');
?>

<style>
.tab-group {
    display: flex;
    gap: 4px;
    background: rgba(15, 23, 42, 0.5);
    padding: 4px;
    border-radius: 8px;
    border: 1px solid var(--border);
}
.tab-btn {
    flex: 1;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    text-align: center;
    transition: all 0.15s;
    border: none;
    background: transparent;
}
.tab-btn:hover { color: var(--text); }
.tab-btn.active {
    background: var(--primary);
    color: #fff;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.upload-zone {
    border: 2px dashed var(--border-hover);
    padding: 20px;
    text-align: center;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s;
    background: rgba(15, 23, 42, 0.3);
}
.upload-zone:hover, .upload-zone.dragging {
    border-color: var(--primary);
    background: rgba(59, 130, 246, 0.05);
}

.kpi-card {
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 16px;
}
.kpi-card .label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    font-weight: 600;
}
.kpi-card .value {
    font-size: 28px;
    font-weight: 700;
    margin-top: 4px;
    color: var(--text);
    letter-spacing: -0.02em;
}
.kpi-card.primary .value { color: #60a5fa; }
.kpi-card.success .value { color: #34d399; }

.geo-bar {
    height: 4px;
    background: linear-gradient(90deg, var(--primary), #60a5fa);
    border-radius: 2px;
    margin-top: 4px;
}

.spinner {
    display: inline-block;
    width: 16px; height: 16px;
    border: 2px solid rgba(148, 163, 184, 0.2);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    vertical-align: middle;
}
@keyframes spin { to { transform: rotate(360deg); } }

.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 12px;
    font-size: 14px;
    border: 1px solid;
}
.alert-error { background: rgba(239, 68, 68, 0.08); border-color: rgba(239, 68, 68, 0.3); color: #fca5a5; }
.alert-success { background: rgba(16, 185, 129, 0.08); border-color: rgba(16, 185, 129, 0.3); color: #6ee7b7; }
.alert-info { background: rgba(59, 130, 246, 0.08); border-color: rgba(59, 130, 246, 0.3); color: #93c5fd; }

select[multiple].form-input { min-height: 100px; padding: 6px; }
select[multiple].form-input option { padding: 4px 8px; }
</style>

<main class="relative z-10 max-w-7xl mx-auto px-6 py-6">

    <!-- Header pagina -->
    <div class="mb-6 flex items-end justify-between flex-wrap gap-3">
        <div>
            <h1 class="page-title">Analisi & Estrazione</h1>
            <p class="text-slate-400 text-sm mt-1">
                Statistiche e estrazioni con filtri geografici, ATECO, magazzino dedup
            </p>
        </div>
        <div class="text-xs text-slate-500">
            <span class="badge badge-blue mono">v1.0</span>
        </div>
    </div>

    <!-- Layout grid: filtri sinistra (380px) / risultati destra (flex) -->
    <div class="grid gap-5" style="grid-template-columns: 380px 1fr;" id="layoutGrid">

        <!-- ============ COLONNA SINISTRA: FILTRI ============ -->
        <div class="space-y-4">

            <!-- Target -->
            <div class="glass p-4">
                <div class="section-label">🎯 Target</div>
                <div class="tab-group" id="targetTabs">
                    <button class="tab-btn active" data-target="business">Business</button>
                    <button class="tab-btn" data-target="consumer">Consumer</button>
                    <button class="tab-btn" data-target="both">Entrambi</button>
                </div>
                <div class="text-xs text-slate-500 mt-2 mono" id="targetDesc">
                    business.master_piva_numeri (~5,3M righe)
                </div>
            </div>

            <!-- Geografia -->
            <div class="glass p-4">
                <div class="section-label">🌍 Geografia</div>
                <div class="space-y-3">
                    <div>
                        <label class="form-label">Regioni</label>
                        <select id="filterRegioni" class="form-input" multiple>
                            <?php foreach ($regioni as $r): ?>
                                <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-xs text-slate-500 mt-1">Cmd/Ctrl+click per selezione multipla</div>
                    </div>
                    <div>
                        <label class="form-label">Province <span class="text-slate-500">(sigle, separate da virgola)</span></label>
                        <input type="text" id="filterProvince" class="form-input mono" placeholder="MI,RM,NA,TO">
                    </div>
                    <div>
                        <label class="form-label">Comuni <span class="text-slate-500">(separati da virgola, ricerca LIKE)</span></label>
                        <input type="text" id="filterComuni" class="form-input" placeholder="Milano, Loano, Napoli">
                    </div>
                    <div>
                        <label class="form-label">CAP <span class="text-slate-500">(opzionale)</span></label>
                        <input type="text" id="filterCap" class="form-input mono" placeholder="20100, 16100">
                    </div>
                </div>
            </div>

            <!-- Filtri Business -->
            <div class="glass p-4" id="cardBiz">
                <div class="section-label">🏢 Filtri Business</div>
                <div class="space-y-3">
                    <div>
                        <label class="form-label">ATECO <span class="text-slate-500">(sezione 2 cifre, codice, o keyword)</span></label>
                        <input type="text" id="filterAteco" class="form-input mono" placeholder="es. 47 / 472101 / alimentari">
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="withEmail" class="rounded">
                            <span>Solo con email</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="withPec" class="rounded">
                            <span>Solo con PEC</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="withSito" class="rounded">
                            <span>Solo con sito web</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Filtri Consumer -->
            <div class="glass p-4 hidden" id="cardConsumer">
                <div class="section-label">👤 Filtri Consumer</div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">Età min</label>
                        <input type="number" id="etaMin" class="form-input mono" min="18" max="99" placeholder="18">
                    </div>
                    <div>
                        <label class="form-label">Età max</label>
                        <input type="number" id="etaMax" class="form-input mono" min="18" max="99" placeholder="65">
                    </div>
                </div>
                <div class="text-xs text-slate-500 mt-2">
                    Filtro su anno nascita derivato dal CF (posizioni 7-8)
                </div>
            </div>

            <!-- Tipo telefono -->
            <div class="glass p-4">
                <div class="section-label">📞 Tipo telefono</div>
                <div class="tab-group" id="telTabs">
                    <button class="tab-btn active" data-tel="entrambi">Entrambi</button>
                    <button class="tab-btn" data-tel="mobile">Mobile</button>
                    <button class="tab-btn" data-tel="fisso">Fisso</button>
                </div>
            </div>

            <!-- Magazzino -->
            <div class="glass p-4">
                <div class="section-label">🗄 Magazzino dedup</div>
                <div class="tab-group" id="magTabs">
                    <button class="tab-btn active" data-mag="cold">Cold</button>
                    <button class="tab-btn" data-mag="existing">Cliente</button>
                    <button class="tab-btn" data-mag="upload">Upload</button>
                </div>

                <div id="magExisting" class="hidden mt-3 space-y-2">
                    <input type="text" id="magSearch" class="form-input" placeholder="🔍 Cerca cliente...">
                    <select id="magList" class="form-input" size="6"></select>
                </div>

                <div id="magUpload" class="hidden mt-3">
                    <div class="upload-zone" id="uploadZone">
                        <div class="text-slate-300 text-sm font-medium">📁 Trascina xlsx/csv qui</div>
                        <div class="text-xs text-slate-500 mt-1">oppure clicca per scegliere</div>
                        <input type="file" id="uploadFile" accept=".csv,.xlsx" style="display:none">
                    </div>
                    <div id="uploadResult" class="text-xs mt-2"></div>
                </div>

                <div id="magSelected" class="hidden mt-3 text-xs">
                    <span class="badge badge-blue" id="magSelectedLabel">—</span>
                </div>
            </div>

            <!-- Bottoni azione -->
            <div class="space-y-2">
                <button class="btn-primary w-full justify-center text-base py-3" id="btnStat" style="width:100%; justify-content:center; padding:12px 18px;">
                    📊 Calcola statistica
                </button>
                <button class="btn-secondary w-full justify-center" id="btnReset" style="width:100%; justify-content:center; padding:10px 18px;">
                    🔄 Azzera ricerca
                </button>
            </div>

        </div>

        <!-- ============ COLONNA DESTRA: RISULTATI ============ -->
        <div>
            <div class="glass p-6" id="resultArea">
                <div class="text-center py-16">
                    <div class="text-5xl mb-4">📊</div>
                    <div class="text-slate-400">
                        Imposta i filtri a sinistra e clicca <strong class="text-blue-400">Calcola statistica</strong>
                    </div>
                    <div class="text-xs text-slate-500 mt-3">
                        Ottieni totali, breakdown per regione e provincia, poi estrai xlsx
                    </div>
                </div>
            </div>
        </div>

    </div>
</main>

<script>
const state = {
    target: 'business',
    tipo_tel: 'entrambi',
    mag_mode: 'cold',
    magazzino: null,
    uploadInfo: null,
    lastStat: null,
};

// ============ TAB SWITCHING ============
document.querySelectorAll('.tab-group').forEach(group => {
    group.addEventListener('click', (e) => {
        const btn = e.target.closest('.tab-btn');
        if (!btn) return;
        group.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        onTabChange(group, btn);
    });
});

function onTabChange(group, btn) {
    if (btn.dataset.target) {
        state.target = btn.dataset.target;
        document.getElementById('cardBiz').classList.toggle('hidden', state.target === 'consumer');
        document.getElementById('cardConsumer').classList.toggle('hidden', state.target === 'business');
        const desc = {
            business: 'business.master_piva_numeri (~5,3M righe)',
            consumer: 'trovacodicefiscale2.master_cf_numeri (~40,5M righe)',
            both: 'business + consumer (entrambi)',
        };
        document.getElementById('targetDesc').textContent = desc[state.target];
    }
    if (btn.dataset.tel) state.tipo_tel = btn.dataset.tel;
    if (btn.dataset.mag) {
        state.mag_mode = btn.dataset.mag;
        state.magazzino = null;
        document.getElementById('magExisting').classList.toggle('hidden', btn.dataset.mag !== 'existing');
        document.getElementById('magUpload').classList.toggle('hidden', btn.dataset.mag !== 'upload');
        document.getElementById('magSelected').classList.add('hidden');
        if (btn.dataset.mag === 'existing') loadMagazzini();
    }
}

function setMagazzino(key, label) {
    state.magazzino = key;
    if (key) {
        document.getElementById('magSelected').classList.remove('hidden');
        document.getElementById('magSelectedLabel').textContent = label || key;
    } else {
        document.getElementById('magSelected').classList.add('hidden');
    }
}

// ============ MAGAZZINI ESISTENTI ============
let magazziniLoaded = [];
async function loadMagazzini() {
    if (magazziniLoaded.length) return renderMagList(magazziniLoaded);
    document.getElementById('magList').innerHTML = '<option>Caricamento...</option>';
    const r = await fetch('/ai/api/analisi_magazzini.php').then(r => r.json());
    if (!r.ok) {
        document.getElementById('magList').innerHTML = '<option>Errore: ' + r.error + '</option>';
        return;
    }
    magazziniLoaded = r.magazzini;
    renderMagList(magazziniLoaded);
}
function renderMagList(list) {
    const sel = document.getElementById('magList');
    sel.innerHTML = '';
    list.slice(0, 100).forEach(m => {
        const opt = document.createElement('option');
        opt.value = m.key;
        opt.textContent = `${m.label} · ${m.rows.toLocaleString('it')} righe`;
        sel.appendChild(opt);
    });
}
document.getElementById('magSearch').addEventListener('input', (e) => {
    const q = e.target.value.toLowerCase();
    renderMagList(magazziniLoaded.filter(m => m.label.toLowerCase().includes(q)));
});
document.getElementById('magList').addEventListener('change', (e) => {
    const opt = e.target.selectedOptions[0];
    if (opt) setMagazzino(opt.value, opt.textContent);
});

// ============ UPLOAD ============
const uploadZone = document.getElementById('uploadZone');
const uploadFile = document.getElementById('uploadFile');
uploadZone.addEventListener('click', () => uploadFile.click());
uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('dragging'); });
uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragging'));
uploadZone.addEventListener('drop', e => {
    e.preventDefault(); uploadZone.classList.remove('dragging');
    if (e.dataTransfer.files[0]) handleUpload(e.dataTransfer.files[0]);
});
uploadFile.addEventListener('change', e => {
    if (e.target.files[0]) handleUpload(e.target.files[0]);
});

async function handleUpload(file) {
    const out = document.getElementById('uploadResult');
    out.innerHTML = '<span class="text-blue-400"><span class="spinner"></span> Carico ' + file.name + '...</span>';
    const fd = new FormData();
    fd.append('file', file);
    try {
        const r = await fetch('/ai/api/analisi_upload_magazzino.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!r.ok) throw new Error(r.error);
        state.uploadInfo = r;
        setMagazzino(r.magazzino_key, `Upload: ${file.name} · ${r.count.toLocaleString('it')} numeri`);
        out.innerHTML = `<span class="text-emerald-400">✅ ${r.count.toLocaleString('it')} numeri caricati</span>`;
    } catch (e) {
        out.innerHTML = '<span class="text-red-400">❌ ' + e.message + '</span>';
    }
}

// ============ CALCOLA STAT ============
function buildFilters() {
    return {
        target: state.target,
        regioni: Array.from(document.getElementById('filterRegioni').selectedOptions).map(o => o.value),
        province: (document.getElementById('filterProvince').value || '').split(',').map(s => s.trim()).filter(Boolean),
        comuni: (document.getElementById('filterComuni').value || '').split(',').map(s => s.trim()).filter(Boolean),
        cap: (document.getElementById('filterCap').value || '').split(',').map(s => s.trim()).filter(Boolean),
        ateco: document.getElementById('filterAteco').value || '',
        tipo_tel: state.tipo_tel,
        eta_min: document.getElementById('etaMin').value || null,
        eta_max: document.getElementById('etaMax').value || null,
        magazzino: state.magazzino,
        with_email: document.getElementById('withEmail').checked,
        with_pec: document.getElementById('withPec').checked,
        with_sito: document.getElementById('withSito').checked,
    };
}

// === RESET FILTRI ===
document.getElementById('btnReset').addEventListener('click', () => {
    // multi-select Regioni
    Array.from(document.getElementById('filterRegioni').options).forEach(o => o.selected = false);
    // text inputs
    ['filterProvince','filterComuni','filterCap','filterAteco','etaMin','etaMax'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    // checkbox
    ['withEmail','withPec','withSito'].forEach(id => {
        const el = document.getElementById(id); if (el) el.checked = false;
    });
    // tabs target → Business
    document.querySelectorAll('#targetTabs .tab-btn').forEach(b => b.classList.toggle('active', b.dataset.target === 'business'));
    state.target = 'business';
    document.getElementById('cardBiz').classList.remove('hidden');
    document.getElementById('cardConsumer').classList.add('hidden');
    document.getElementById('targetDesc').textContent = 'business.master_piva_numeri (~5,3M righe)';
    // tabs tel → Entrambi
    document.querySelectorAll('#telTabs .tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tel === 'entrambi'));
    state.tipo_tel = 'entrambi';
    // tabs magazzino → Cold
    document.querySelectorAll('#magTabs .tab-btn').forEach(b => b.classList.toggle('active', b.dataset.mag === 'cold'));
    state.mag_mode = 'cold';
    state.magazzino = null;
    state.uploadInfo = null;
    document.getElementById('magExisting').classList.add('hidden');
    document.getElementById('magUpload').classList.add('hidden');
    document.getElementById('magSelected').classList.add('hidden');
    document.getElementById('uploadResult').innerHTML = '';
    document.getElementById('magSearch').value = '';
    // reset risultati area
    document.getElementById('resultArea').innerHTML = `
        <div class="text-center py-16">
            <div class="text-5xl mb-4">📊</div>
            <div class="text-slate-400">
                Imposta i filtri a sinistra e clicca <strong class="text-blue-400">Calcola statistica</strong>
            </div>
            <div class="text-xs text-slate-500 mt-3">
                Ottieni totali, breakdown per regione e provincia, poi estrai xlsx
            </div>
        </div>`;
    state.lastStat = null;
});

document.getElementById('btnStat').addEventListener('click', async () => {
    const filters = buildFilters();
    const ra = document.getElementById('resultArea');
    ra.innerHTML = '<div class="text-center py-16"><span class="spinner"></span><div class="text-slate-400 mt-3">Calcolo statistica...</div></div>';
    try {
        const r = await fetch('/ai/api/analisi_stat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(filters),
        }).then(r => r.json());
        if (!r.ok) throw new Error(r.error);
        state.lastStat = r;
        renderStat(r);
    } catch (e) {
        ra.innerHTML = '<div class="alert alert-error">❌ ' + e.message + '</div>';
    }
});

function renderStat(r) {
    const ra = document.getElementById('resultArea');
    let html = '<div class="flex justify-between items-end mb-4">';
    html += '<div><div class="text-xs text-slate-500 mono">Calcolato in ' + r.elapsed_ms + 'ms</div></div>';
    html += '</div>';

    // KPI
    html += '<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">';
    html += `<div class="kpi-card primary"><div class="label">Totale</div><div class="value">${r.total.toLocaleString('it')}</div></div>`;
    if (r.business) html += `<div class="kpi-card"><div class="label">Business</div><div class="value">${r.business.total.toLocaleString('it')}</div></div>`;
    if (r.consumer) html += `<div class="kpi-card success"><div class="label">Consumer</div><div class="value">${r.consumer.total.toLocaleString('it')}</div></div>`;
    html += '</div>';

    const groups = [];
    if (r.business) groups.push({ name: 'Business', data: r.business });
    if (r.consumer) groups.push({ name: 'Consumer', data: r.consumer });

    groups.forEach(g => {
        if (groups.length > 1) {
            html += `<div class="section-label mt-4">${g.name}</div>`;
        }
        html += '<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">';

        // Per regione
        html += '<div><div class="section-label">Per regione</div>';
        html += '<table class="table"><thead><tr><th>Regione</th><th class="text-right">Righe</th></tr></thead><tbody>';
        const regs = g.data.per_regione || {};
        const max = Math.max(...Object.values(regs), 1);
        for (const [reg, c] of Object.entries(regs)) {
            const pct = (c / max * 100).toFixed(1);
            html += `<tr><td>${reg}</td><td class="text-right mono">${c.toLocaleString('it')}<div class="geo-bar" style="width:${pct}%"></div></td></tr>`;
        }
        html += '</tbody></table></div>';

        // Per provincia
        html += '<div><div class="section-label">Per provincia (top 25)</div>';
        html += '<table class="table"><thead><tr><th>Prov</th><th class="text-right">Righe</th></tr></thead><tbody>';
        let i = 0;
        for (const [p, c] of Object.entries(g.data.per_provincia || {})) {
            if (++i > 25) break;
            html += `<tr><td class="mono">${p || '(N/A)'}</td><td class="text-right mono">${c.toLocaleString('it')}</td></tr>`;
        }
        html += '</tbody></table></div>';

        html += '</div>';
    });

    // Bottoni azione
    html += '<div class="glass p-4 mt-4">';
    html += '<div class="section-label">📥 Estrai xlsx</div>';
    html += '<div class="flex gap-3 items-end flex-wrap">';
    html += '<div class="flex-1 min-w-[180px]"><label class="form-label">Quantità</label>';
    html += '<input type="number" id="extractLimit" class="form-input mono" value="5000" min="1" max="500000"></div>';
    html += '<button class="btn-primary" id="btnExtract">📥 Estrai e scarica</button>';
    html += '</div>';
    html += '<div id="extractResult" class="mt-3"></div>';
    html += '</div>';

    ra.innerHTML = html;

    document.getElementById('btnExtract').addEventListener('click', doExtract);
}

async function doExtract() {
    const limit = parseInt(document.getElementById('extractLimit').value || '5000', 10);
    const filters = buildFilters();
    const out = document.getElementById('extractResult');
    out.innerHTML = '<div class="alert alert-info"><span class="spinner"></span> Estrazione in corso...</div>';

    try {
        const r = await fetch('/ai/api/analisi_estrai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ filters, limit }),
        }).then(r => r.json());
        if (!r.ok) throw new Error(r.error);

        let html = '';
        r.files.forEach(f => {
            if (f.error) {
                html += `<div class="alert alert-error">❌ ${f.target}: ${f.error}</div>`;
            } else {
                html += `<div class="alert alert-success">
                    ✅ <strong>${f.target}</strong>: ${f.count.toLocaleString('it')} righe estratte —
                    <a href="${f.xlsx_url}" download class="link" style="font-weight:600;">📥 Scarica ${f.xlsx_name}</a>
                    <span class="text-xs text-slate-400">(${f.size_kb} KB)</span>
                </div>`;
            }
        });
        out.innerHTML = html;
    } catch (e) {
        out.innerHTML = '<div class="alert alert-error">❌ ' + e.message + '</div>';
    }
}
</script>

<?php aiRenderFooter(); ?>
