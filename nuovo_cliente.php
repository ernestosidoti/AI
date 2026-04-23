<?php
/**
 * Form cliente — create / edit / delete
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

aiSecurityHeaders();
aiRequireAuth();

$backDb = remoteDb(AI_BACKOFFICE_DB);

$editId = (int)($_GET['id'] ?? 0);
$isEdit = $editId > 0;

$message = null;
$messageType = 'success';
$formData = [];

if ($isEdit) {
    $stmt = $backDb->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$editId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) { header('Location: clienti.php'); exit; }
    $formData = $existing;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && aiVerifyCsrf($_POST['csrf_token'] ?? '')) {
    $formData = array_map(fn($v) => is_array($v) ? $v : trim($v), $_POST);

    // Handle delete
    if (($_POST['action'] ?? '') === 'delete' && $isEdit) {
        // Check se ha ordini
        $hasOrders = (int)$backDb->prepare("SELECT COUNT(*) FROM orders WHERE cliente_id = ?");
        $hasOrders = $backDb->prepare("SELECT COUNT(*) FROM orders WHERE cliente_id = ?");
        $hasOrders->execute([$editId]);
        $nOrders = (int)$hasOrders->fetchColumn();
        if ($nOrders > 0) {
            $message = "Impossibile eliminare: il cliente ha $nOrders ordini associati";
            $messageType = 'error';
        } else {
            $backDb->prepare("DELETE FROM clientes WHERE id = ?")->execute([$editId]);
            header('Location: clienti.php?deleted=' . $editId);
            exit;
        }
    }

    if (($_POST['action'] ?? '') !== 'delete') {
        // TUTTI i campi obbligatori tranne magazzino e note
        $errors = [];
        $required = [
            'ragione_sociale' => 'Ragione sociale',
            'nome' => 'Nome',
            'cognome' => 'Cognome',
            'partita_iva' => 'Partita IVA',
            'codice_fiscale' => 'Codice fiscale',
            'indirizzo' => 'Indirizzo',
            'civico' => 'Civico',
            'comune' => 'Comune',
            'provincia' => 'Provincia',
            'cap' => 'CAP',
            'stato' => 'Stato',
            'email' => 'Email',
            'numero_cellulare' => 'Cellulare',
            'user_id' => 'Agente di riferimento',
        ];
        foreach ($required as $field => $label) {
            if (empty($formData[$field])) $errors[] = "$label obbligatorio";
        }

        // Validazioni di formato
        if (!empty($formData['email']) && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email non valida';
        }
        if (!empty($formData['partita_iva']) && !preg_match('/^\d{11}$/', $formData['partita_iva'])) {
            $errors[] = 'Partita IVA non valida (11 cifre)';
        }
        if (!empty($formData['codice_fiscale'])) {
            $cf = strtoupper(trim($formData['codice_fiscale']));
            if (!preg_match('/^[A-Z0-9]{16}$/', $cf) && !preg_match('/^\d{11}$/', $cf)) {
                $errors[] = 'Codice fiscale non valido (16 caratteri o 11 cifre)';
            }
        }
        if (!empty($formData['cap']) && !preg_match('/^\d{5}$/', $formData['cap'])) {
            $errors[] = 'CAP non valido (5 cifre)';
        }
        if (!empty($formData['provincia']) && !preg_match('/^[A-Za-z]{2}$/', $formData['provincia'])) {
            $errors[] = 'Provincia non valida (2 lettere)';
        }

        // Check duplicati P.IVA/CF (escludi se stesso)
        if (empty($errors) && !empty($formData['partita_iva'])) {
            $q = "SELECT id, ragione_sociale FROM clientes WHERE partita_iva = ?";
            $p = [$formData['partita_iva']];
            if ($isEdit) { $q .= " AND id != ?"; $p[] = $editId; }
            $stmt = $backDb->prepare($q);
            $stmt->execute($p);
            if ($dup = $stmt->fetch()) $errors[] = "P.IVA già presente: cliente #{$dup['id']} ({$dup['ragione_sociale']})";
        }
        if (empty($errors) && !empty($formData['codice_fiscale'])) {
            $q = "SELECT id, ragione_sociale FROM clientes WHERE codice_fiscale = ?";
            $p = [$formData['codice_fiscale']];
            if ($isEdit) { $q .= " AND id != ?"; $p[] = $editId; }
            $stmt = $backDb->prepare($q);
            $stmt->execute($p);
            if ($dup = $stmt->fetch()) $errors[] = "CF già presente: cliente #{$dup['id']} ({$dup['ragione_sociale']})";
        }

        if (!empty($errors)) {
            $message = implode(' · ', $errors);
            $messageType = 'error';
        } else {
            try {
                if ($isEdit) {
                    $stmt = $backDb->prepare("
                        UPDATE clientes SET
                        user_id=?, ragione_sociale=?, nome=?, cognome=?, partita_iva=?, codice_fiscale=?,
                        indirizzo=?, civico=?, comune=?, provincia=?, cap=?, stato=?,
                        numero_cellulare=?, email=?, magazzino=?, note=?, updated_at=NOW()
                        WHERE id=?
                    ");
                    $stmt->execute([
                        (int)$formData['user_id'],
                        $formData['ragione_sociale'],
                        $formData['nome'] ?: null,
                        $formData['cognome'] ?: null,
                        $formData['partita_iva'] ?: null,
                        $formData['codice_fiscale'] ?: null,
                        $formData['indirizzo'] ?: null,
                        $formData['civico'] ?: null,
                        $formData['comune'] ?: null,
                        $formData['provincia'] ?: null,
                        $formData['cap'] ?: null,
                        $formData['stato'] ?: 'Italia',
                        $formData['numero_cellulare'] ?: null,
                        $formData['email'] ?: null,
                        $formData['magazzino'] ?: null,
                        $formData['note'] ?: null,
                        $editId,
                    ]);
                    header('Location: clienti.php?updated=' . $editId);
                    exit;
                } else {
                    $stmt = $backDb->prepare("
                        INSERT INTO clientes
                        (user_id, ragione_sociale, nome, cognome, partita_iva, codice_fiscale,
                         indirizzo, civico, comune, provincia, cap, stato,
                         numero_cellulare, email, magazzino, note, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        (int)$formData['user_id'],
                        $formData['ragione_sociale'],
                        $formData['nome'] ?? null,
                        $formData['cognome'] ?? null,
                        $formData['partita_iva'] ?: null,
                        $formData['codice_fiscale'] ?: null,
                        $formData['indirizzo'] ?? null,
                        $formData['civico'] ?? null,
                        $formData['comune'] ?? null,
                        $formData['provincia'] ?? null,
                        $formData['cap'] ?? null,
                        $formData['stato'] ?? 'Italia',
                        $formData['numero_cellulare'] ?? null,
                        $formData['email'] ?? null,
                        $formData['magazzino'] ?? null,
                        $formData['note'] ?? null,
                    ]);
                    $newId = (int)$backDb->lastInsertId();
                    header('Location: clienti.php?created=' . $newId);
                    exit;
                }
            } catch (\Throwable $e) {
                $message = 'Errore DB: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

$agents = $backDb->query("SELECT id, name, role FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$csrf = aiCsrfToken();

aiRenderHeader($isEdit ? "Modifica Cliente #$editId" : 'Nuovo Cliente', 'clienti');
?>

<main class="relative z-10 max-w-4xl mx-auto px-6 py-8">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <a href="clienti.php" class="text-cyan-400 hover:text-cyan-300 text-sm">&larr; Elenco clienti</a>
            <h1 class="orbitron text-2xl font-black mt-2 bg-gradient-to-r from-cyan-400 via-purple-500 to-pink-500 bg-clip-text text-transparent">
                <?= $isEdit ? 'MODIFICA CLIENTE #' . $editId : 'NUOVO CLIENTE' ?>
            </h1>
            <?php if ($isEdit && !empty($existing['created_at'])): ?>
            <p class="text-xs text-slate-500 mt-1">
                Creato il <?= date('d/m/Y H:i', strtotime($existing['created_at'])) ?>
                <?php if (!empty($existing['updated_at']) && $existing['updated_at'] !== $existing['created_at']): ?>
                · ultima modifica <?= date('d/m/Y H:i', strtotime($existing['updated_at'])) ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        <?php if ($isEdit): ?>
        <div class="flex gap-2 flex-wrap">
            <a href="cliente_storico.php?id=<?= $editId ?>" class="orbitron px-4 py-2.5 text-sm bg-purple-500/20 hover:bg-purple-500/30 border border-purple-500/50 text-purple-400 rounded-lg tracking-wider">📋 STORICO</a>
            <a href="index.php?cliente_id=<?= $editId ?>" class="orbitron px-4 py-2.5 text-sm bg-cyan-500/20 hover:bg-cyan-500/30 border border-cyan-500/50 text-cyan-400 rounded-lg tracking-wider">🤖 ESTRAI</a>
            <form method="POST" onsubmit="return confirm('Eliminare definitivamente questo cliente?');">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="orbitron px-4 py-2.5 text-sm bg-red-500/20 hover:bg-red-500/30 border border-red-500/50 text-red-400 rounded-lg tracking-wider">🗑 ELIMINA</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
    <div class="glass rounded-xl p-4 mb-6 border-<?= $messageType === 'success' ? 'green' : 'red' ?>-500/50">
        <p class="text-<?= $messageType === 'success' ? 'green' : 'red' ?>-400 text-sm"><?= htmlspecialchars($message) ?></p>
    </div>
    <?php endif; ?>

    <form method="POST" class="glass rounded-xl p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <!-- Anagrafica -->
        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">ANAGRAFICA</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="form-label required">Ragione Sociale</label>
                    <input type="text" name="ragione_sociale" required maxlength="255"
                           value="<?= htmlspecialchars($formData['ragione_sociale'] ?? '') ?>"
                           placeholder="Es. ROSSI SRL oppure nome ditta individuale" class="form-input">
                </div>
                <div>
                    <label class="form-label required">Nome (referente)</label>
                    <input type="text" name="nome" required maxlength="255"
                           value="<?= htmlspecialchars($formData['nome'] ?? '') ?>" class="form-input">
                </div>
                <div>
                    <label class="form-label required">Cognome (referente)</label>
                    <input type="text" name="cognome" required maxlength="255"
                           value="<?= htmlspecialchars($formData['cognome'] ?? '') ?>" class="form-input">
                </div>
            </div>
        </section>

        <!-- Fiscale -->
        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">DATI FISCALI</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label required">Partita IVA</label>
                    <input type="text" name="partita_iva" required maxlength="20" pattern="\d{11}"
                           value="<?= htmlspecialchars($formData['partita_iva'] ?? '') ?>"
                           placeholder="11 cifre" class="form-input font-mono">
                </div>
                <div>
                    <label class="form-label required">Codice Fiscale</label>
                    <input type="text" name="codice_fiscale" required maxlength="20"
                           value="<?= htmlspecialchars($formData['codice_fiscale'] ?? '') ?>"
                           placeholder="16 caratteri o 11 cifre" class="form-input font-mono uppercase" style="text-transform: uppercase;">
                </div>
            </div>
        </section>

        <!-- Indirizzo -->
        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">INDIRIZZO</h3>
            <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <div class="md:col-span-4 relative">
                    <label class="form-label required flex items-center gap-2">
                        Indirizzo
                        <span class="text-[9px] text-cyan-400 font-normal">🔍 autocomplete OSM</span>
                    </label>
                    <input type="text" name="indirizzo" id="addrInput" required maxlength="255" autocomplete="off"
                           value="<?= htmlspecialchars($formData['indirizzo'] ?? '') ?>"
                           placeholder="Inizia a digitare: via, comune, CAP..." class="form-input">
                    <div id="addrDropdown" class="hidden absolute z-40 left-0 right-0 top-full mt-1 bg-slate-900 border border-cyan-500/40 rounded-lg max-h-72 overflow-y-auto shadow-xl"></div>
                </div>
                <div class="md:col-span-2">
                    <label class="form-label required">Civico</label>
                    <input type="text" name="civico" required maxlength="20"
                           value="<?= htmlspecialchars($formData['civico'] ?? '') ?>" class="form-input">
                </div>
                <div class="md:col-span-3 relative">
                    <label class="form-label required flex items-center gap-2">
                        Comune
                        <span class="text-[9px] text-cyan-400 font-normal">🔍 auto CAP</span>
                    </label>
                    <input type="text" name="comune" id="comuneInput" required maxlength="100" autocomplete="off"
                           value="<?= htmlspecialchars($formData['comune'] ?? '') ?>" class="form-input">
                    <div id="comuneDropdown" class="hidden absolute z-40 left-0 right-0 top-full mt-1 bg-slate-900 border border-cyan-500/40 rounded-lg max-h-72 overflow-y-auto shadow-xl"></div>
                </div>
                <div>
                    <label class="form-label required">Provincia</label>
                    <input type="text" name="provincia" required maxlength="2" pattern="[A-Za-z]{2}"
                           value="<?= htmlspecialchars($formData['provincia'] ?? '') ?>"
                           placeholder="MI" class="form-input uppercase" style="text-transform: uppercase;">
                </div>
                <div>
                    <label class="form-label required">CAP</label>
                    <input type="text" name="cap" required maxlength="5" pattern="\d{5}"
                           value="<?= htmlspecialchars($formData['cap'] ?? '') ?>" class="form-input font-mono">
                </div>
                <div>
                    <label class="form-label required">Stato</label>
                    <input type="text" name="stato" required maxlength="50"
                           value="<?= htmlspecialchars($formData['stato'] ?? 'Italia') ?>" class="form-input">
                </div>
            </div>
        </section>

        <!-- Contatti -->
        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">CONTATTI</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label required">Email</label>
                    <input type="email" name="email" required maxlength="255"
                           value="<?= htmlspecialchars($formData['email'] ?? '') ?>"
                           placeholder="cliente@azienda.it" class="form-input">
                </div>
                <div>
                    <label class="form-label required">Cellulare</label>
                    <input type="tel" name="numero_cellulare" required maxlength="20"
                           value="<?= htmlspecialchars($formData['numero_cellulare'] ?? '') ?>"
                           placeholder="3931234567" class="form-input font-mono">
                </div>
            </div>
        </section>

        <!-- Gestione -->
        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">GESTIONE INTERNA</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label required">Agente di riferimento</label>
                    <select name="user_id" required class="form-input">
                        <option value="">— Seleziona agente —</option>
                        <?php foreach ($agents as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= (($formData['user_id'] ?? '') == $a['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['name']) ?><?= $a['role'] === 'admin' ? ' (admin)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Magazzino / Riferimento</label>
                    <input type="text" name="magazzino" maxlength="255"
                           value="<?= htmlspecialchars($formData['magazzino'] ?? '') ?>" class="form-input">
                </div>
            </div>
            <div class="mt-4">
                <label class="form-label">Note</label>
                <textarea name="note" rows="3" class="form-input resize-none"
                          placeholder="Note libere"><?= htmlspecialchars($formData['note'] ?? '') ?></textarea>
            </div>
        </section>

        <div class="flex items-center justify-between pt-4 border-t border-slate-700/50">
            <a href="clienti.php" class="text-slate-400 hover:text-slate-200 text-sm">Annulla</a>
            <button type="submit" class="btn-primary orbitron px-8 py-3 text-white font-bold rounded-lg tracking-wider">
                <?= $isEdit ? 'SALVA MODIFICHE' : 'CREA CLIENTE' ?>
            </button>
        </div>
    </form>
</main>

<script>
// Autocomplete indirizzo via OpenStreetMap (proxy PHP api/geocode.php)
(function() {
    const input = document.getElementById('addrInput');
    const dropdown = document.getElementById('addrDropdown');
    if (!input || !dropdown) return;

    let timer = null;
    let lastQuery = '';

    function esc(s) { return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function showLoading() {
        dropdown.innerHTML = '<div class="p-3 text-sm text-slate-400 animate-pulse">🔍 Cerco...</div>';
        dropdown.classList.remove('hidden');
    }
    function hideDropdown() { dropdown.classList.add('hidden'); }

    async function search(q) {
        if (q === lastQuery) return;
        lastQuery = q;
        showLoading();
        try {
            const res = await fetch('api/geocode.php?q=' + encodeURIComponent(q));
            const data = await res.json();
            if (!data.success || !data.results || data.results.length === 0) {
                dropdown.innerHTML = '<div class="p-3 text-sm text-slate-500">Nessun risultato. Compila i campi manualmente.</div>';
                return;
            }
            dropdown.innerHTML = data.results.map((r, i) => {
                const hasStreet = r.indirizzo && r.indirizzo.trim() !== '';
                const hasCap = r.cap && r.cap.trim() !== '';
                const badge = !hasStreet
                    ? '<span class="text-[9px] ml-1 px-1.5 py-0.5 bg-yellow-500/20 text-yellow-400 rounded">solo comune</span>'
                    : '';
                const capBadge = !hasCap && !hasStreet
                    ? '<span class="text-[9px] ml-1 px-1.5 py-0.5 bg-orange-500/20 text-orange-400 rounded">CAP da compilare</span>'
                    : '';
                const title = hasStreet
                    ? `${esc(r.indirizzo)}${r.civico ? ' ' + esc(r.civico) : ''}`
                    : esc(r.comune);
                return `
                    <div class="addr-item p-3 hover:bg-cyan-500/10 cursor-pointer border-b border-slate-800 last:border-0"
                         data-idx="${i}">
                        <div class="text-sm text-white font-medium">${title}${badge}${capBadge}</div>
                        <div class="text-xs text-slate-400 mt-0.5">
                            ${esc(r.cap)} ${hasStreet ? esc(r.comune) : ''} ${r.provincia ? '(' + esc(r.provincia) + ')' : ''} · ${esc(r.stato)}
                        </div>
                    </div>
                `;
            }).join('');
            dropdown.querySelectorAll('.addr-item').forEach((el, i) => {
                el.addEventListener('mousedown', (e) => {
                    e.preventDefault(); // evita che il blur del input chiuda il dropdown prima del click
                    pickAddress(data.results[i]);
                });
            });
        } catch (e) {
            dropdown.innerHTML = '<div class="p-3 text-sm text-red-400">Errore: ' + esc(e.message) + '</div>';
        }
    }

    function pickAddress(r) {
        // Popola tutti i campi in modo intelligente (non sovrascrivere se utente ha già valore diverso non vuoto)
        const set = (name, value) => {
            if (!value) return;
            const el = document.querySelector(`[name="${name}"]`);
            if (el) el.value = value;
        };
        set('indirizzo', r.indirizzo);
        set('civico', r.civico);
        set('comune', r.comune);
        set('provincia', r.provincia);
        set('cap', r.cap);
        set('stato', r.stato || 'Italia');
        hideDropdown();
    }

    input.addEventListener('input', (e) => {
        const q = e.target.value.trim();
        clearTimeout(timer);
        if (q.length < 3) { hideDropdown(); return; }
        // debounce 800ms per rispettare rate limit Nominatim (1 req/sec)
        timer = setTimeout(() => search(q), 800);
    });

    input.addEventListener('blur', () => setTimeout(hideDropdown, 200));
    input.addEventListener('focus', (e) => {
        const q = e.target.value.trim();
        if (q.length >= 3 && dropdown.innerHTML.trim() !== '') {
            dropdown.classList.remove('hidden');
        }
    });

    document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) hideDropdown();
    });
})();

// Autocomplete Comune → popola CAP + Provincia
(function() {
    const cInput = document.getElementById('comuneInput');
    const cDropdown = document.getElementById('comuneDropdown');
    if (!cInput || !cDropdown) return;

    let cTimer = null;
    let cLastQuery = '';

    function esc(s) { return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    async function searchComune(q) {
        if (q === cLastQuery) return;
        cLastQuery = q;
        cDropdown.innerHTML = '<div class="p-3 text-sm text-slate-400 animate-pulse">🔍 Cerco comune...</div>';
        cDropdown.classList.remove('hidden');
        try {
            const res = await fetch('api/geocode.php?type=city&q=' + encodeURIComponent(q));
            const data = await res.json();
            if (!data.success || !data.results || data.results.length === 0) {
                cDropdown.innerHTML = '<div class="p-3 text-sm text-slate-500">Nessun comune trovato. Compila manualmente.</div>';
                return;
            }
            cDropdown.innerHTML = data.results.map((r, i) => `
                <div class="comune-item p-3 hover:bg-cyan-500/10 cursor-pointer border-b border-slate-800 last:border-0"
                     data-idx="${i}">
                    <div class="text-sm text-white font-medium">${esc(r.comune || r.display.split(',')[0])}</div>
                    <div class="text-xs text-slate-400 mt-0.5">
                        ${r.cap ? esc(r.cap) + ' · ' : ''}${r.provincia ? '(' + esc(r.provincia) + ')' : ''} · ${esc(r.stato)}
                    </div>
                </div>
            `).join('');
            cDropdown.querySelectorAll('.comune-item').forEach((el, i) => {
                el.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    pickComune(data.results[i]);
                });
            });
        } catch (e) {
            cDropdown.innerHTML = '<div class="p-3 text-sm text-red-400">Errore di rete</div>';
        }
    }

    function pickComune(r) {
        const set = (name, value) => {
            if (!value) return;
            const el = document.querySelector(`[name="${name}"]`);
            if (el) el.value = value;
        };
        set('comune', r.comune || r.display.split(',')[0]);
        set('provincia', r.provincia);
        if (r.cap) set('cap', r.cap);
        set('stato', r.stato || 'Italia');
        cDropdown.classList.add('hidden');
    }

    cInput.addEventListener('input', (e) => {
        const q = e.target.value.trim();
        clearTimeout(cTimer);
        if (q.length < 2) { cDropdown.classList.add('hidden'); return; }
        cTimer = setTimeout(() => searchComune(q), 600);
    });

    cInput.addEventListener('blur', () => setTimeout(() => cDropdown.classList.add('hidden'), 200));
    cInput.addEventListener('focus', (e) => {
        const q = e.target.value.trim();
        if (q.length >= 2 && cDropdown.innerHTML.trim() !== '') {
            cDropdown.classList.remove('hidden');
        }
    });

    document.addEventListener('click', (e) => {
        if (!cInput.contains(e.target) && !cDropdown.contains(e.target)) cDropdown.classList.add('hidden');
    });
})();
</script>

<?php aiRenderFooter(); ?>
