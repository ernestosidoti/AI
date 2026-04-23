<?php
/**
 * Form nuovo ordine — scrive in backoffice.orders
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

aiSecurityHeaders();
aiRequireAuth();

$backDb = remoteDb(AI_BACKOFFICE_DB);

$message = null;
$messageType = 'success';
$editId = (int)($_GET['id'] ?? 0);
$isEdit = $editId > 0;
$currentUserId = aiCurrentUserId();
$isAdmin = aiCurrentUserRole() === 'admin';

// Carica ordine esistente se modalità edit, altrimenti pre-seleziona l'utente corrente
$formData = [
    'cliente_id' => (int)($_GET['cliente_id'] ?? 0),
    'creatore' => $currentUserId,
];
if ($isEdit) {
    $stmt = $backDb->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$editId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) { header('Location: ordini.php'); exit; }
    $formData = $existing;
    $formData['creatore'] = $formData['creatore'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && aiVerifyCsrf($_POST['csrf_token'] ?? '')) {
    $formData = array_map(fn($v) => is_array($v) ? $v : trim($v), $_POST);
    // Sicurezza: agenti non-admin non possono assegnare l'ordine a qualcun altro
    if (!$isAdmin) {
        $formData['creatore'] = $currentUserId;
    }

    // Handle delete
    if (($_POST['action'] ?? '') === 'delete' && $isEdit) {
        $backDb->prepare("DELETE FROM orders WHERE id = ?")->execute([$editId]);
        header('Location: ordini.php?deleted=' . $editId);
        exit;
    }

    $errors = [];
    if (empty($formData['cliente_id'])) $errors[] = 'Cliente obbligatorio';
    if (empty($formData['prodotto_id'])) $errors[] = 'Prodotto obbligatorio';
    if (empty($formData['creatore'])) $errors[] = 'Agente creatore obbligatorio';
    if (empty($formData['tipo'])) $errors[] = 'Tipo obbligatorio';
    if (empty($formData['stato'])) $errors[] = 'Stato obbligatorio';

    $valid_tipi = ['Residenziale', 'Business', 'Entrambi'];
    if (!empty($formData['tipo']) && !in_array($formData['tipo'], $valid_tipi)) {
        $errors[] = 'Tipo non valido';
    }

    $valid_stati = ['Statistica da effettuare', 'Statistica generata', 'Da Evadere', 'Pronto da inviare', 'Annullato', 'Evaso', 'Errore di Vendita'];
    if (!empty($formData['stato']) && !in_array($formData['stato'], $valid_stati)) {
        $errors[] = 'Stato non valido';
    }

    if (!empty($errors)) {
        $message = implode(' · ', $errors);
        $messageType = 'error';
    } else {
        try {
            if ($isEdit) {
                $stmt = $backDb->prepare("
                    UPDATE orders SET
                    prodotto_id=?, cliente_id=?, creatore=?, tipo=?, quantita=?, zona=?,
                    data_stimata=?, importo_bonifico=?, metodo_pagamento_id=?, stato=?,
                    note=?, link_file=?, updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([
                    (int)$formData['prodotto_id'], (int)$formData['cliente_id'], (int)$formData['creatore'],
                    $formData['tipo'],
                    !empty($formData['quantita']) ? (int)$formData['quantita'] : null,
                    $formData['zona'] ?? null,
                    !empty($formData['data_stimata']) ? $formData['data_stimata'] : null,
                    !empty($formData['importo_bonifico']) ? (float)$formData['importo_bonifico'] : null,
                    !empty($formData['metodo_pagamento_id']) ? (int)$formData['metodo_pagamento_id'] : null,
                    $formData['stato'],
                    $formData['note'] ?? null,
                    $formData['link_file'] ?? null,
                    $editId,
                ]);
                header('Location: ordini.php?updated=' . $editId);
                exit;
            } else {
                $stmt = $backDb->prepare("
                    INSERT INTO orders
                    (prodotto_id, cliente_id, creatore, tipo, quantita, zona, data_stimata,
                     importo_bonifico, metodo_pagamento_id, stato, note, link_file,
                     data_ora, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ");
                $stmt->execute([
                    (int)$formData['prodotto_id'], (int)$formData['cliente_id'], (int)$formData['creatore'],
                    $formData['tipo'],
                    !empty($formData['quantita']) ? (int)$formData['quantita'] : null,
                    $formData['zona'] ?? null,
                    !empty($formData['data_stimata']) ? $formData['data_stimata'] : null,
                    !empty($formData['importo_bonifico']) ? (float)$formData['importo_bonifico'] : null,
                    !empty($formData['metodo_pagamento_id']) ? (int)$formData['metodo_pagamento_id'] : null,
                    $formData['stato'],
                    $formData['note'] ?? null,
                    $formData['link_file'] ?? null,
                ]);
                $newId = (int)$backDb->lastInsertId();
                header('Location: ordini.php?created=' . $newId);
                exit;
            }
        } catch (\Throwable $e) {
            $message = 'Errore DB: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Dropdown data
$clienti = $backDb->query("SELECT id, ragione_sociale, partita_iva, comune, provincia FROM clientes ORDER BY ragione_sociale")->fetchAll(PDO::FETCH_ASSOC);
$prodotti = $backDb->query("SELECT id, nome FROM prodotti ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$agents = $backDb->query("SELECT id, name, role FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$metodi = $backDb->query("SELECT id, nome FROM payment_methods ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Se cliente preselezionato, carica i suoi dati per pre-fill
$clientePreselezionato = null;
if ($formData['cliente_id']) {
    $stmt = $backDb->prepare("SELECT ragione_sociale, partita_iva, comune, provincia FROM clientes WHERE id = ?");
    $stmt->execute([$formData['cliente_id']]);
    $clientePreselezionato = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$csrf = aiCsrfToken();
aiRenderHeader($isEdit ? "Modifica Ordine #$editId" : 'Nuovo Ordine', 'ordini');
?>

<main class="relative z-10 max-w-4xl mx-auto px-6 py-8">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <a href="ordini.php" class="text-cyan-400 hover:text-cyan-300 text-sm">&larr; Elenco ordini</a>
            <h1 class="orbitron text-2xl font-black mt-2 bg-gradient-to-r from-cyan-400 via-purple-500 to-pink-500 bg-clip-text text-transparent">
                <?= $isEdit ? 'MODIFICA ORDINE #' . $editId : 'NUOVO ORDINE' ?>
            </h1>
            <?php if ($isEdit && !empty($existing['created_at'])): ?>
            <p class="text-xs text-slate-500 mt-1">Creato il <?= date('d/m/Y H:i', strtotime($existing['created_at'])) ?>
                <?php if (!empty($existing['updated_at']) && $existing['updated_at'] !== $existing['created_at']): ?>
                · ultima modifica <?= date('d/m/Y H:i', strtotime($existing['updated_at'])) ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        <?php if ($isEdit): ?>
        <div class="flex gap-2">
            <a href="index.php?order_id=<?= $editId ?>" class="btn-primary orbitron px-4 py-2.5 text-sm text-white font-bold rounded-lg tracking-wider"
               style="background: linear-gradient(135deg, #22d3ee, #6366f1, #a855f7);">🤖 ESEGUI CON AI</a>
            <form method="POST" onsubmit="return confirm('Eliminare definitivamente questo ordine?');">
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
    <?php if ($isEdit && !empty($_GET['duplicated'])): ?>
    <div class="glass rounded-xl p-4 mb-6 border-purple-500/50">
        <p class="text-purple-400 text-sm">⎘ Ordine duplicato con successo. Rivedi i dati e salva le modifiche se necessario, poi clicca "ESEGUI CON AI" per generare la lista.</p>
    </div>
    <?php endif; ?>

    <?php if ($clientePreselezionato): ?>
    <div class="glass rounded-xl p-4 mb-6 border-cyan-500/30">
        <p class="text-xs text-cyan-400 orbitron tracking-widest mb-1">CLIENTE PRE-SELEZIONATO</p>
        <p class="text-white font-bold"><?= htmlspecialchars($clientePreselezionato['ragione_sociale']) ?></p>
        <p class="text-xs text-slate-400 mt-1">
            P.IVA: <?= htmlspecialchars($clientePreselezionato['partita_iva'] ?? '-') ?>
            &middot; <?= htmlspecialchars($clientePreselezionato['comune'] ?? '') ?>
            <?= $clientePreselezionato['provincia'] ? '(' . htmlspecialchars($clientePreselezionato['provincia']) . ')' : '' ?>
        </p>
    </div>
    <?php endif; ?>

    <form method="POST" class="glass rounded-xl p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">CLIENTE E AGENTE</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label required">Cliente</label>
                    <select name="cliente_id" required class="form-input">
                        <option value="">— Seleziona cliente —</option>
                        <?php foreach ($clienti as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= (($formData['cliente_id'] ?? '') == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['ragione_sociale']) ?>
                                <?= $c['partita_iva'] ? ' [' . htmlspecialchars($c['partita_iva']) . ']' : '' ?>
                                <?= $c['comune'] ? ' — ' . htmlspecialchars($c['comune']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">
                        Non c'è? <a href="nuovo_cliente.php" class="text-cyan-400 hover:underline">Crea nuovo cliente</a>
                    </p>
                </div>
                <div>
                    <label class="form-label required">Agente creatore</label>
                    <?php if ($isAdmin): ?>
                    <select name="creatore" required class="form-input">
                        <?php foreach ($agents as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= (($formData['creatore'] ?? '') == $a['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Come admin puoi assegnare l'ordine a un altro agente.</p>
                    <?php else:
                        // User non-admin: fisso a sé stesso, non modificabile
                        $myName = '';
                        foreach ($agents as $a) { if ((int)$a['id'] === $currentUserId) { $myName = $a['name']; break; } }
                    ?>
                    <input type="hidden" name="creatore" value="<?= $currentUserId ?>">
                    <div class="form-input flex items-center gap-2 cursor-not-allowed" style="background: rgba(15, 23, 42, 0.4); opacity: 0.8;">
                        <span class="w-6 h-6 rounded-full bg-blue-500 text-white font-bold flex items-center justify-center text-xs flex-shrink-0"><?= strtoupper(mb_substr($myName, 0, 1)) ?></span>
                        <span><?= htmlspecialchars($myName) ?></span>
                        <span class="text-xs text-slate-500 ml-auto">🔒</span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">L'ordine è assegnato a te. Solo un admin può assegnarlo a un altro agente.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">PRODOTTO E TIPOLOGIA</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label required">Prodotto</label>
                    <select name="prodotto_id" required class="form-input">
                        <option value="">— Seleziona prodotto —</option>
                        <?php foreach ($prodotti as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= (($formData['prodotto_id'] ?? '') == $p['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label required">Tipo</label>
                    <select name="tipo" required class="form-input">
                        <option value="">— Seleziona tipo —</option>
                        <?php foreach (['Residenziale', 'Business', 'Entrambi'] as $t): ?>
                            <option value="<?= $t ?>" <?= (($formData['tipo'] ?? '') === $t) ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Quantità</label>
                    <input type="number" name="quantita" min="0"
                           value="<?= htmlspecialchars($formData['quantita'] ?? '') ?>"
                           placeholder="Numero contatti" class="form-input font-mono">
                </div>
                <div>
                    <label class="form-label">Zona / Area</label>
                    <input type="text" name="zona" maxlength="100"
                           value="<?= htmlspecialchars($formData['zona'] ?? '') ?>"
                           placeholder="Es. Lombardia, Milano, Vedi note" class="form-input">
                </div>
            </div>
        </section>

        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">PAGAMENTO</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="form-label">Importo (€)</label>
                    <input type="number" name="importo_bonifico" step="0.01" min="0"
                           value="<?= htmlspecialchars($formData['importo_bonifico'] ?? '') ?>"
                           placeholder="0.00" class="form-input font-mono">
                </div>
                <div class="md:col-span-2">
                    <label class="form-label">Metodo pagamento</label>
                    <select name="metodo_pagamento_id" class="form-input">
                        <option value="">—</option>
                        <?php foreach ($metodi as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= (($formData['metodo_pagamento_id'] ?? '') == $m['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </section>

        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">STATO E CONSEGNA</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label required">Stato</label>
                    <select name="stato" required class="form-input">
                        <?php foreach (['Statistica da effettuare', 'Statistica generata', 'Da Evadere', 'Pronto da inviare', 'Annullato', 'Evaso', 'Errore di Vendita'] as $s):
                            $default = $s === 'Statistica da effettuare';
                            $selected = ($formData['stato'] ?? ($default ? $s : '')) === $s;
                        ?>
                            <option value="<?= $s ?>" <?= $selected ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Data stimata consegna</label>
                    <input type="date" name="data_stimata"
                           value="<?= htmlspecialchars($formData['data_stimata'] ?? '') ?>" class="form-input">
                </div>
                <div class="md:col-span-2">
                    <label class="form-label">Link file (cloud / download)</label>
                    <input type="url" name="link_file" maxlength="255"
                           value="<?= htmlspecialchars($formData['link_file'] ?? '') ?>"
                           placeholder="https://..." class="form-input">
                </div>
            </div>
        </section>

        <section>
            <label class="form-label">Note ordine</label>
            <textarea name="note" rows="5" class="form-input resize-none"
                      placeholder="Specifiche del cliente: zone, esclusioni, età, sesso, trader, CAP, periodo, integrazioni, duplicati, ecc."><?= htmlspecialchars($formData['note'] ?? '') ?></textarea>
        </section>

        <div class="flex items-center justify-between pt-4 border-t border-slate-700/50">
            <a href="ordini.php" class="text-slate-400 hover:text-slate-200 text-sm">Annulla</a>
            <button type="submit" class="btn-primary orbitron px-8 py-3 text-white font-bold rounded-lg tracking-wider">
                <?= $isEdit ? 'SALVA MODIFICHE' : 'CREA ORDINE' ?>
            </button>
        </div>
    </form>
</main>

<?php aiRenderFooter(); ?>
