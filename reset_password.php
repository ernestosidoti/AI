<?php
/**
 * Reset password via token
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

aiSecurityHeaders();

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$user = $token ? aiValidateResetToken($token) : null;
$error = null;
$done = false;

if (!$user && $token) {
    $error = 'Link non valido o scaduto. Richiedi un nuovo reset.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $p1 = $_POST['password'] ?? '';
    $p2 = $_POST['password2'] ?? '';
    if (strlen($p1) < 8) $error = 'La password deve avere almeno 8 caratteri';
    elseif ($p1 !== $p2) $error = 'Le password non coincidono';
    else {
        if (aiConsumeResetToken((int)$user['id'], $p1)) {
            $done = true;
        } else {
            $error = 'Errore nel salvataggio';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Reset password</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
body { background: #0b1220; font-family: 'Inter', sans-serif; color: #f1f5f9; min-height: 100vh; margin: 0; }
body::before { content: ''; position: fixed; inset: 0; background: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(59,130,246,0.08), transparent); pointer-events: none; }
.card { background: rgba(30, 41, 59, 0.6); backdrop-filter: blur(10px); border: 1px solid rgba(148, 163, 184, 0.15); }
.input { background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(148, 163, 184, 0.25); color: #f1f5f9; border-radius: 8px; padding: 11px 14px; width: 100%; font-size: 14px; }
.input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
.btn-primary { background: #3b82f6; color: #fff; padding: 11px 18px; border-radius: 8px; font-weight: 600; font-size: 14px; width: 100%; border: 1px solid #3b82f6; }
.btn-primary:hover { background: #2563eb; }
</style>
</head>
<body>
<div class="relative z-10 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="card rounded-xl p-7">
            <?php if ($done): ?>
                <h1 class="text-lg font-semibold mb-1 text-emerald-400">✓ Password aggiornata</h1>
                <p class="text-sm text-slate-400 mb-5">Puoi accedere con la nuova password.</p>
                <a href="login.php" class="btn-primary inline-block text-center">Vai al login</a>
            <?php elseif (!$user): ?>
                <h1 class="text-lg font-semibold mb-1 text-red-400">Link non valido</h1>
                <p class="text-sm text-slate-400 mb-5"><?= htmlspecialchars($error ?? 'Il link è scaduto o già stato usato.') ?></p>
                <a href="password_dimenticata.php" class="btn-primary inline-block text-center">Richiedi nuovo link</a>
            <?php else: ?>
                <h1 class="text-lg font-semibold mb-1">Nuova password</h1>
                <p class="text-sm text-slate-400 mb-5">Utente: <span class="text-blue-400"><?= htmlspecialchars($user['email']) ?></span></p>

                <?php if ($error): ?>
                <div class="mb-4 p-3 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-sm"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1.5">Nuova password (min 8 caratteri)</label>
                        <input type="password" name="password" required minlength="8" class="input" autofocus>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1.5">Conferma password</label>
                        <input type="password" name="password2" required minlength="8" class="input">
                    </div>
                    <button type="submit" class="btn-primary">Imposta nuova password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
