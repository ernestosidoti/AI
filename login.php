<?php
/**
 * Login — email + password
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

aiSecurityHeaders();

if (aiIsAuthenticated()) { header('Location: ' . AI_BASE_URL . '/home.php'); exit; }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = aiLogin($_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($res['success']) {
        header('Location: ' . AI_BASE_URL . '/home.php');
        exit;
    }
    $error = $res['error'];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Listetelemarketing — Accedi</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body { background: #0b1220; font-family: 'Inter', sans-serif; color: #f1f5f9; min-height: 100vh; margin: 0; -webkit-font-smoothing: antialiased; }
body::before {
    content: ''; position: fixed; inset: 0;
    background: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(59, 130, 246, 0.08), transparent),
                radial-gradient(ellipse 60% 40% at 100% 100%, rgba(16, 185, 129, 0.05), transparent);
    pointer-events: none; z-index: 0;
}
.card { background: rgba(30, 41, 59, 0.6); backdrop-filter: blur(10px); border: 1px solid rgba(148, 163, 184, 0.15); }
.input {
    background: rgba(15, 23, 42, 0.7);
    border: 1px solid rgba(148, 163, 184, 0.25);
    color: #f1f5f9; border-radius: 8px;
    padding: 11px 14px; width: 100%; font-size: 14px;
    transition: all 0.15s;
}
.input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
.btn-primary {
    background: #3b82f6; color: #fff;
    padding: 11px 18px; border-radius: 8px;
    font-weight: 600; font-size: 14px;
    transition: all 0.15s; width: 100%;
    border: 1px solid #3b82f6;
}
.btn-primary:hover { background: #2563eb; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25); }
</style>
</head>
<body>
<div class="relative z-10 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center gap-3 mb-2">
                <svg class="w-10 h-10" viewBox="0 0 32 32" fill="none">
                    <rect width="32" height="32" rx="8" fill="#3b82f6"/>
                    <path d="M8 12h16M8 16h10M8 20h14" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
                </svg>
                <div class="text-left">
                    <div class="font-bold text-xl tracking-tight">Listetelemarketing</div>
                    <div class="text-[11px] text-slate-500 uppercase tracking-[0.2em]">AI Laboratory</div>
                </div>
            </div>
        </div>

        <div class="card rounded-xl p-7">
            <h2 class="text-lg font-semibold mb-1">Accedi al tuo account</h2>
            <p class="text-sm text-slate-400 mb-6">Usa le credenziali fornite dall'amministratore.</p>

            <?php if ($error): ?>
            <div class="mb-4 p-3 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1.5">Email</label>
                    <input type="email" name="email" required autofocus autocomplete="username"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           class="input" placeholder="nome@listetelemarketing.eu">
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-medium text-slate-400">Password</label>
                        <a href="password_dimenticata.php" class="text-xs text-blue-400 hover:text-blue-300">Dimenticata?</a>
                    </div>
                    <input type="password" name="password" required autocomplete="current-password" class="input">
                </div>
                <button type="submit" class="btn-primary">Accedi</button>
            </form>

            <p class="text-xs text-slate-500 mt-5 text-center">
                Non hai accesso? Chiedi a un amministratore.
            </p>
        </div>

        <p class="text-center text-xs text-slate-600 mt-6">
            © <?= date('Y') ?> Listetelemarketing · Tutti i diritti riservati
        </p>
    </div>
</div>
</body>
</html>
