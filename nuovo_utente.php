<?php
/**
 * Crea nuovo utente (admin only)
 * Genera password random, mostrala all'admin. Invio email solo a comando.
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/logger.php';
require_once __DIR__ . '/lib/layout.php';

aiSecurityHeaders();
aiRequireAdmin();

$backDb = remoteDb(AI_BACKOFFICE_DB);

$error = null;
$createdUser = null;
$generatedPassword = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && aiVerifyCsrf($_POST['csrf_token'] ?? '')) {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $role = $_POST['role'] === 'admin' ? 'admin' : 'user';
    $commerciale = isset($_POST['commerciale']) ? 1 : 0;
    $telefono = trim($_POST['telefono'] ?? '');
    $active = isset($_POST['active']) ? 1 : 0;

    $errors = [];
    if (!$name) $errors[] = 'Nome obbligatorio';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email non valida';

    // Check duplicati
    if (!$errors) {
        $stmt = $backDb->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors[] = 'Email già registrata';
    }

    if ($errors) {
        $error = implode(' · ', $errors);
    } else {
        // Genera password casuale
        $generatedPassword = aiGenerateRandomPassword(12);
        $hash = password_hash($generatedPassword, PASSWORD_BCRYPT);

        try {
            $stmt = $backDb->prepare("INSERT INTO users (name, email, password, role, commerciale, telefono, active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())");
            $stmt->execute([$name, $email, $hash, $role, $commerciale, $telefono ?: null, $active]);
            $newId = (int)$backDb->lastInsertId();
            aiLog('admin', 'user_created', "Creato utente #$newId ($email)", ['role' => $role, 'commerciale' => $commerciale]);
            $createdUser = [
                'id' => $newId,
                'name' => $name, 'email' => $email, 'role' => $role,
            ];
        } catch (\Throwable $e) {
            $error = 'Errore DB: ' . $e->getMessage();
        }
    }
}

$csrf = aiCsrfToken();
aiRenderHeader('Nuovo utente', 'utenti');
?>

<main class="relative z-10 max-w-2xl mx-auto px-6 py-8">
    <div class="mb-6">
        <a href="utenti.php" class="link text-sm">← Utenti</a>
        <h1 class="page-title mt-2">Nuovo utente</h1>
    </div>

    <?php if ($error): ?>
    <div class="glass rounded-lg p-3 mb-4">
        <p class="text-sm text-red-400">✗ <?= htmlspecialchars($error) ?></p>
    </div>
    <?php endif; ?>

    <?php if ($createdUser && $generatedPassword): ?>
    <div class="glass rounded-lg p-5 mb-5" style="border-color: rgba(16, 185, 129, 0.35); background: rgba(16,185,129,0.06)">
        <h3 class="text-emerald-400 font-semibold mb-2">✓ Utente creato</h3>
        <div class="space-y-2 text-sm">
            <div><span class="text-slate-400">Nome:</span> <span class="font-medium"><?= htmlspecialchars($createdUser['name']) ?></span></div>
            <div><span class="text-slate-400">Email:</span> <span class="mono text-blue-400"><?= htmlspecialchars($createdUser['email']) ?></span></div>
            <div><span class="text-slate-400">Password generata:</span> <span class="mono text-emerald-400 font-bold bg-slate-900 px-2 py-1 rounded"><?= htmlspecialchars($generatedPassword) ?></span></div>
        </div>
        <p class="text-xs text-slate-400 mt-3">⚠️ Copia questa password <strong>ora</strong>. Non verrà più mostrata. Puoi consegnarla all'utente manualmente o inviargliela dalla scheda utente (pulsante <em>Invia credenziali via email</em>).</p>
        <div class="mt-4 flex gap-2">
            <a href="edit_utente.php?id=<?= $createdUser['id'] ?>" class="btn-primary">Apri scheda utente</a>
            <a href="nuovo_utente.php" class="btn-secondary">+ Crea un altro</a>
            <a href="utenti.php" class="btn-ghost">Torna alla lista</a>
        </div>
    </div>
    <?php else: ?>

    <div class="glass rounded-lg p-6">
        <form method="POST" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="form-label required">Nome completo</label>
                    <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" class="form-input">
                </div>
                <div>
                    <label class="form-label required">Email</label>
                    <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" class="form-input mono">
                </div>
                <div>
                    <label class="form-label">Telefono</label>
                    <input type="tel" name="telefono" value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>" class="form-input mono">
                </div>
                <div>
                    <label class="form-label required">Ruolo</label>
                    <select name="role" required class="form-input">
                        <option value="user" <?= (($_POST['role'] ?? 'user') === 'user') ? 'selected' : '' ?>>User (commerciale standard)</option>
                        <option value="admin" <?= (($_POST['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin (accesso completo)</option>
                    </select>
                </div>
            </div>

            <div class="space-y-2 pt-2">
                <label class="flex items-center gap-3 p-3 rounded-lg bg-slate-800/40 border border-slate-700/50 cursor-pointer">
                    <input type="checkbox" name="commerciale" checked class="w-4 h-4">
                    <div>
                        <div class="text-sm font-medium">È un commerciale</div>
                        <div class="text-xs text-slate-500">Riceverà clienti e ordini. Gli admin possono essere anche commerciali.</div>
                    </div>
                </label>
                <label class="flex items-center gap-3 p-3 rounded-lg bg-slate-800/40 border border-slate-700/50 cursor-pointer">
                    <input type="checkbox" name="active" checked class="w-4 h-4">
                    <div>
                        <div class="text-sm font-medium">Account attivo</div>
                        <div class="text-xs text-slate-500">Se disattivo, non può più accedere.</div>
                    </div>
                </label>
            </div>

            <div class="pt-4 border-t border-slate-800 flex items-center justify-between">
                <a href="utenti.php" class="btn-ghost">Annulla</a>
                <button type="submit" class="btn-primary">Crea utente</button>
            </div>
            <p class="text-xs text-slate-500">Alla creazione verrà generata automaticamente una password casuale che ti verrà mostrata una sola volta.</p>
        </form>
    </div>
    <?php endif; ?>
</main>

<?php aiRenderFooter(); ?>
