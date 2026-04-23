<?php
/**
 * Password dimenticata — genera token e mostra istruzioni
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

aiSecurityHeaders();

$sent = false;
$resetLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $token = aiCreateResetToken($email);
    // Per sicurezza, mostra sempre "abbiamo inviato" anche se l'email non esiste
    $sent = true;
    if ($token) {
        // In produzione: invia email. Per ora mostro il link (debug)
        $resetLink = sprintf(
            "http%s://%s%s/reset_password.php?token=%s",
            isset($_SERVER['HTTPS']) ? 's' : '',
            $_SERVER['HTTP_HOST'],
            AI_BASE_URL,
            $token
        );
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Password dimenticata</title>
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
            <h1 class="text-lg font-semibold mb-1">Password dimenticata</h1>
            <p class="text-sm text-slate-400 mb-5">Inserisci la tua email. Riceverai un link per impostare una nuova password.</p>

            <?php if ($sent): ?>
                <div class="p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm mb-4">
                    Se l'email è registrata, ti abbiamo inviato un link per reimpostare la password. Controlla la casella di posta.
                </div>
                <?php if ($resetLink): ?>
                <!-- DEV: link mostrato in locale. In produzione invia via email. -->
                <div class="p-4 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs mb-4 break-all">
                    <strong>DEV ONLY</strong> (in produzione verrà inviato via email):<br>
                    <a href="<?= htmlspecialchars($resetLink) ?>" class="underline text-blue-400"><?= htmlspecialchars($resetLink) ?></a>
                </div>
                <?php endif; ?>
                <a href="login.php" class="btn-primary inline-block text-center">Torna al login</a>
            <?php else: ?>
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1.5">Email</label>
                        <input type="email" name="email" required autofocus class="input" placeholder="tu@listetelemarketing.eu">
                    </div>
                    <button type="submit" class="btn-primary">Invia link di reset</button>
                </form>
                <p class="text-xs text-slate-500 mt-5 text-center">
                    <a href="login.php" class="text-blue-400 hover:underline">← Torna al login</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
