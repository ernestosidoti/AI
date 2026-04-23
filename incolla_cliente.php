<?php
/**
 * Incolla dati cliente → parsing automatico + check duplicati + assegnazione agente
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/ClientParser.php';

aiSecurityHeaders();
aiRequireAuth();

$backDb = remoteDb(AI_BACKOFFICE_DB);

// L'utente loggato in AI Lab è sempre admin (per ora).
// In futuro: user_id da sessione mappato a backoffice.users.id
$isAdmin = true;
$currentUserId = null; // non ancora mappato

$message = null; $messageType = 'success';
$step = 'paste'; // paste → review → saved
$rawText = '';
$parsed = null;
$duplicate = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && aiVerifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'parse') {
        $rawText = trim($_POST['raw_text'] ?? '');
        if ($rawText === '') {
            $message = 'Incolla qualcosa'; $messageType = 'error';
        } else {
            $parsed = ClientParser::parse($rawText);
            $duplicate = ClientParser::findDuplicate($backDb, $parsed['partita_iva'], $parsed['codice_fiscale']);
            $step = 'review';
        }
    }

    if ($action === 'confirm') {
        // Validazioni
        $data = [
            'user_id' => (int)($_POST['user_id'] ?? 0),
            'ragione_sociale' => trim($_POST['ragione_sociale'] ?? ''),
            'nome' => trim($_POST['nome'] ?? ''),
            'cognome' => trim($_POST['cognome'] ?? ''),
            'partita_iva' => trim($_POST['partita_iva'] ?? ''),
            'codice_fiscale' => trim($_POST['codice_fiscale'] ?? ''),
            'indirizzo' => trim($_POST['indirizzo'] ?? ''),
            'civico' => trim($_POST['civico'] ?? ''),
            'comune' => trim($_POST['comune'] ?? ''),
            'provincia' => trim($_POST['provincia'] ?? ''),
            'cap' => trim($_POST['cap'] ?? ''),
            'stato' => trim($_POST['stato'] ?? 'Italia'),
            'email' => trim($_POST['email'] ?? ''),
            'numero_cellulare' => trim($_POST['numero_cellulare'] ?? ''),
            'note' => trim($_POST['note'] ?? ''),
        ];

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
            'email' => 'Email',
            'numero_cellulare' => 'Cellulare',
        ];
        foreach ($required as $field => $label) {
            if (empty($data[$field])) $errors[] = "$label obbligatorio";
        }
        if ($data['user_id'] <= 0) $errors[] = 'Assegna un agente';
        if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email non valida';
        if (!empty($data['partita_iva']) && !preg_match('/^\d{11}$/', $data['partita_iva'])) $errors[] = 'P.IVA non valida (11 cifre)';
        if (!empty($data['cap']) && !preg_match('/^\d{5}$/', $data['cap'])) $errors[] = 'CAP non valido';
        if (!empty($data['provincia']) && !preg_match('/^[A-Z]{2}$/i', $data['provincia'])) $errors[] = 'Provincia non valida';

        // Check duplicati finale (potrebbe essere cambiato tra parse e confirm)
        if (empty($errors)) {
            $dup = ClientParser::findDuplicate($backDb, $data['partita_iva'] ?: null, $data['codice_fiscale'] ?: null);
            if ($dup) {
                $errors[] = "Cliente già presente (#{$dup['id']} {$dup['ragione_sociale']}, agente: {$dup['agent_name']})";
            }
        }

        if (!empty($errors)) {
            $message = implode(' · ', $errors); $messageType = 'error';
            $step = 'review';
            $parsed = $data;
        } else {
            try {
                $stmt = $backDb->prepare("
                    INSERT INTO clientes
                    (user_id, ragione_sociale, nome, cognome, partita_iva, codice_fiscale,
                     indirizzo, civico, comune, provincia, cap, stato, numero_cellulare, email, note,
                     created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
                ");
                $stmt->execute([
                    $data['user_id'], $data['ragione_sociale'],
                    $data['nome'] ?: null, $data['cognome'] ?: null,
                    $data['partita_iva'] ?: null, $data['codice_fiscale'] ?: null,
                    $data['indirizzo'] ?: null, $data['civico'] ?: null,
                    $data['comune'] ?: null, $data['provincia'] ?: null,
                    $data['cap'] ?: null, $data['stato'] ?: 'Italia',
                    $data['numero_cellulare'] ?: null, $data['email'] ?: null,
                    $data['note'] ?: null,
                ]);
                $newId = (int)$backDb->lastInsertId();
                header('Location: cliente_storico.php?id=' . $newId);
                exit;
            } catch (\Throwable $e) {
                $message = 'Errore DB: ' . $e->getMessage(); $messageType = 'error';
                $step = 'review';
                $parsed = $data;
            }
        }
    }
}

$agents = $backDb->query("SELECT id, name, role FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$csrf = aiCsrfToken();

aiRenderHeader('Incolla cliente', 'clienti');
?>

<main class="relative z-10 max-w-4xl mx-auto px-6 py-8">
    <div class="mb-6">
        <a href="clienti.php" class="text-cyan-400 hover:text-cyan-300 text-sm">&larr; Tutti i clienti</a>
        <h1 class="orbitron text-2xl font-black mt-2 bg-gradient-to-r from-cyan-400 via-purple-500 to-pink-500 bg-clip-text text-transparent">
            INSERIMENTO RAPIDO CLIENTE
        </h1>
        <p class="text-slate-400 text-sm mt-1">Incolla i dati del cliente in qualunque formato. Li organizzo io.</p>
    </div>

    <?php if ($message): ?>
    <div class="glass rounded-xl p-4 mb-6 border-<?= $messageType === 'success' ? 'green' : 'red' ?>-500/50">
        <p class="text-<?= $messageType === 'success' ? 'green' : 'red' ?>-400 text-sm"><?= $messageType === 'success' ? '✓' : '✗' ?> <?= htmlspecialchars($message) ?></p>
    </div>
    <?php endif; ?>

    <?php if ($step === 'paste'): ?>
    <!-- STEP 1: Paste -->
    <div class="glass rounded-xl p-6">
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="parse">
            <div>
                <label class="form-label">Incolla qui i dati del cliente</label>
                <textarea name="raw_text" rows="10" required autofocus
                    class="form-input font-mono text-sm resize-none"
                    placeholder="Es:&#10;Adriano Falchi&#10;Via Trento 1, Corteolona e Genzone 27014 PV&#10;P.IVA 02877400180&#10;C.F. FLCDRN90R27E648N&#10;adriano.falchi@outlook.it&#10;3931234567"></textarea>
                <p class="text-xs text-slate-500 mt-2">Non serve un formato preciso — accetto P.IVA, CF, email, indirizzo, CAP, provincia, telefono in qualsiasi ordine.</p>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="btn-primary orbitron px-6 py-3 text-white font-bold rounded-lg tracking-wider">
                    🔍 ANALIZZA DATI
                </button>
            </div>
        </form>
    </div>

    <?php elseif ($step === 'review'): ?>
    <!-- STEP 2: Review (con eventuale duplicate warning) -->

    <?php if ($duplicate): ?>
    <div class="glass rounded-xl p-5 mb-6 border-2 border-yellow-500/60" style="box-shadow: 0 0 30px rgba(234,179,8,0.25)">
        <div class="flex items-start gap-4">
            <div class="text-yellow-400 text-3xl">⚠</div>
            <div class="flex-1">
                <h3 class="orbitron text-yellow-400 font-bold mb-2">CLIENTE GIÀ PRESENTE</h3>
                <p class="text-white text-sm mb-2">
                    <strong><?= htmlspecialchars($duplicate['ragione_sociale']) ?></strong>
                    (#<?= $duplicate['id'] ?>)
                </p>
                <p class="text-xs text-slate-300 mb-2">
                    P.IVA: <span class="font-mono"><?= htmlspecialchars($duplicate['partita_iva'] ?? '-') ?></span>
                    · CF: <span class="font-mono"><?= htmlspecialchars($duplicate['codice_fiscale'] ?? '-') ?></span>
                </p>
                <p class="text-sm text-slate-200 mb-3">
                    Assegnato a: <span class="text-purple-300 font-bold"><?= htmlspecialchars($duplicate['agent_name'] ?? 'nessuno') ?></span>
                </p>
                <div class="flex gap-2 flex-wrap">
                    <a href="cliente_storico.php?id=<?= $duplicate['id'] ?>" class="orbitron text-xs px-3 py-1.5 bg-cyan-500/20 hover:bg-cyan-500/30 border border-cyan-500/50 text-cyan-400 rounded tracking-wider">📋 VEDI STORICO</a>
                    <a href="index.php?cliente_id=<?= $duplicate['id'] ?>" class="orbitron text-xs px-3 py-1.5 bg-purple-500/20 hover:bg-purple-500/30 border border-purple-500/50 text-purple-400 rounded tracking-wider">🤖 ESTRAI PER LUI</a>
                    <a href="incolla_cliente.php" class="orbitron text-xs px-3 py-1.5 bg-slate-700/50 hover:bg-slate-700 border border-slate-600 text-slate-300 rounded tracking-wider">↩ INSERISCI UN ALTRO</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$duplicate): ?>
    <div class="glass rounded-xl p-5 mb-6 border-green-500/50">
        <p class="text-green-400 text-sm">✓ Dati estratti. Verifica e conferma per salvare.</p>
    </div>
    <?php endif; ?>

    <form method="POST" class="glass rounded-xl p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="confirm">

        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">ANAGRAFICA</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="form-label required">Ragione Sociale / Nome ditta</label>
                    <input type="text" name="ragione_sociale" required class="form-input"
                           value="<?= htmlspecialchars($parsed['ragione_sociale'] ?? '') ?>">
                </div>
                <div>
                    <label class="form-label">Nome</label>
                    <input type="text" name="nome" class="form-input" value="<?= htmlspecialchars($parsed['nome'] ?? '') ?>">
                </div>
                <div>
                    <label class="form-label">Cognome</label>
                    <input type="text" name="cognome" class="form-input" value="<?= htmlspecialchars($parsed['cognome'] ?? '') ?>">
                </div>
            </div>
        </section>

        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">DATI FISCALI</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">P.IVA</label>
                    <input type="text" name="partita_iva" class="form-input font-mono" value="<?= htmlspecialchars($parsed['partita_iva'] ?? '') ?>" <?= $duplicate && $duplicate['partita_iva'] === ($parsed['partita_iva'] ?? '') ? 'style="border-color: #eab308;"' : '' ?>>
                </div>
                <div>
                    <label class="form-label">C.F.</label>
                    <input type="text" name="codice_fiscale" class="form-input font-mono uppercase" value="<?= htmlspecialchars($parsed['codice_fiscale'] ?? '') ?>" <?= $duplicate && $duplicate['codice_fiscale'] === ($parsed['codice_fiscale'] ?? '') ? 'style="border-color: #eab308;"' : '' ?>>
                </div>
            </div>
        </section>

        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">INDIRIZZO</h3>
            <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <div class="md:col-span-4">
                    <label class="form-label">Indirizzo</label>
                    <input type="text" name="indirizzo" class="form-input" value="<?= htmlspecialchars($parsed['indirizzo'] ?? '') ?>">
                </div>
                <div class="md:col-span-2">
                    <label class="form-label">Civico</label>
                    <input type="text" name="civico" class="form-input" value="<?= htmlspecialchars($parsed['civico'] ?? '') ?>">
                </div>
                <div class="md:col-span-3">
                    <label class="form-label">Comune</label>
                    <input type="text" name="comune" class="form-input" value="<?= htmlspecialchars($parsed['comune'] ?? '') ?>">
                </div>
                <div>
                    <label class="form-label">Prov.</label>
                    <input type="text" name="provincia" maxlength="5" class="form-input uppercase" style="text-transform: uppercase;" value="<?= htmlspecialchars($parsed['provincia'] ?? '') ?>">
                </div>
                <div>
                    <label class="form-label">CAP</label>
                    <input type="text" name="cap" maxlength="10" class="form-input font-mono" value="<?= htmlspecialchars($parsed['cap'] ?? '') ?>">
                </div>
                <div>
                    <label class="form-label">Stato</label>
                    <input type="text" name="stato" class="form-input" value="<?= htmlspecialchars($parsed['stato'] ?? 'Italia') ?>">
                </div>
            </div>
        </section>

        <section>
            <h3 class="orbitron text-xs text-pink-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">CONTATTI</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($parsed['email'] ?? '') ?>">
                </div>
                <div>
                    <label class="form-label">Cellulare</label>
                    <input type="tel" name="numero_cellulare" class="form-input font-mono" value="<?= htmlspecialchars($parsed['numero_cellulare'] ?? '') ?>">
                </div>
            </div>
        </section>

        <section>
            <h3 class="orbitron text-xs text-yellow-400 tracking-widest border-b border-slate-700/50 pb-2 mb-4">🔒 ASSEGNAZIONE AGENTE</h3>
            <?php if ($isAdmin): ?>
            <p class="text-xs text-slate-400 mb-3">Sei <strong class="text-yellow-400">admin</strong>. Scegli a quale agente assegnare questo cliente (cliccando il nome):</p>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                <?php foreach ($agents as $a): ?>
                <label class="flex items-center gap-2 p-3 bg-slate-900/40 hover:bg-cyan-500/10 border border-slate-700 hover:border-cyan-500/50 rounded-lg cursor-pointer has-[:checked]:border-cyan-400 has-[:checked]:bg-cyan-500/20">
                    <input type="radio" name="user_id" value="<?= $a['id'] ?>" required class="accent-cyan-400"
                           <?= ($parsed['user_id'] ?? 0) == $a['id'] ? 'checked' : '' ?>>
                    <div class="flex-1">
                        <div class="text-sm text-white"><?= htmlspecialchars($a['name']) ?></div>
                        <?php if ($a['role'] === 'admin'): ?>
                        <div class="text-[10px] text-purple-400">ADMIN</div>
                        <?php endif; ?>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <input type="hidden" name="user_id" value="<?= $currentUserId ?>">
            <p class="text-xs text-cyan-400">✓ Il cliente verrà assegnato a te automaticamente.</p>
            <?php endif; ?>
        </section>

        <section>
            <label class="form-label">Note</label>
            <textarea name="note" rows="2" class="form-input resize-none"><?= htmlspecialchars($parsed['note'] ?? '') ?></textarea>
        </section>

        <div class="flex items-center justify-between pt-4 border-t border-slate-700/50">
            <a href="incolla_cliente.php" class="text-slate-400 hover:text-slate-200 text-sm">↩ Incolla di nuovo</a>
            <button type="submit" class="btn-primary orbitron px-8 py-3 text-white font-bold rounded-lg tracking-wider"
                    <?= $duplicate ? 'disabled title="Cliente duplicato, non inseribile"' : '' ?>
                    style="<?= $duplicate ? 'opacity:0.4;cursor:not-allowed' : '' ?>">
                <?= $duplicate ? '✗ CLIENTE GIÀ PRESENTE' : '✓ SALVA CLIENTE' ?>
            </button>
        </div>
    </form>
    <?php endif; ?>
</main>

<?php aiRenderFooter(); ?>
