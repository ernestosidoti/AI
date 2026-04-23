<?php
/**
 * Layout — tema corporate/professionale
 * Ispirato a Stripe/Linear: dark slate + blu corporate + smeraldo/amber per stati.
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

function aiRenderHeader(string $title, string $activePage = ''): void
{
    $active = fn($p) => $p === $activePage ? 'text-blue-400 border-blue-400' : 'text-slate-400 border-transparent hover:text-slate-100';
    $user = function_exists('aiCurrentUser') ? aiCurrentUser() : 'guest';
    $userInitial = strtoupper(mb_substr($user, 0, 1));
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — Listetelemarketing</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #0f172a;
    --surface: rgba(30, 41, 59, 0.6);
    --surface-hover: rgba(51, 65, 85, 0.5);
    --border: rgba(148, 163, 184, 0.12);
    --border-hover: rgba(148, 163, 184, 0.25);
    --text: #f1f5f9;
    --text-muted: #94a3b8;
    --text-dim: #64748b;
    --primary: #3b82f6;
    --primary-hover: #2563eb;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
}
* { box-sizing: border-box; }
body {
    background: #0b1220;
    background-image:
        radial-gradient(ellipse 80% 50% at 50% -10%, rgba(59, 130, 246, 0.06), transparent),
        radial-gradient(ellipse 60% 40% at 100% 100%, rgba(16, 185, 129, 0.04), transparent);
    background-attachment: fixed;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    color: var(--text);
    min-height: 100vh;
    margin: 0;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
.mono { font-family: 'JetBrains Mono', monospace; }

.glass {
    background: var(--surface);
    backdrop-filter: blur(8px);
    border: 1px solid var(--border);
    border-radius: 10px;
}
.glass-hover:hover {
    border-color: var(--border-hover);
    background: var(--surface-hover);
}

.btn-primary {
    background: var(--primary);
    color: #fff;
    padding: 10px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.15s;
    border: 1px solid var(--primary);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}
.btn-primary:hover {
    background: var(--primary-hover);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
}

.btn-secondary {
    background: rgba(51, 65, 85, 0.6);
    color: var(--text);
    padding: 10px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    border: 1px solid var(--border-hover);
    transition: all 0.15s;
}
.btn-secondary:hover { background: rgba(71, 85, 105, 0.7); }

.btn-ghost {
    color: var(--text-muted);
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.15s;
}
.btn-ghost:hover { color: var(--text); background: rgba(51, 65, 85, 0.4); }

.btn-danger {
    background: rgba(239, 68, 68, 0.1);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.3);
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    transition: all 0.15s;
}
.btn-danger:hover {
    background: rgba(239, 68, 68, 0.2);
    border-color: rgba(239, 68, 68, 0.5);
}

.form-input, select.form-input, textarea.form-input {
    background: rgba(15, 23, 42, 0.7);
    border: 1px solid var(--border-hover);
    color: var(--text);
    border-radius: 8px;
    padding: 10px 14px;
    width: 100%;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.15s;
}
.form-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}
.form-input.mono, input.mono { font-family: 'JetBrains Mono', monospace; }
.form-label {
    font-size: 12px;
    font-weight: 500;
    color: var(--text-muted);
    display: block;
    margin-bottom: 6px;
    letter-spacing: 0.01em;
}
.form-label.required::after {
    content: ' *';
    color: var(--danger);
}
select.form-input {
    appearance: none;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'><path d='M6 9l6 6 6-6'/></svg>");
    background-repeat: no-repeat;
    background-position: right 10px center;
    padding-right: 36px;
}

/* Badges */
.badge {
    display: inline-flex; align-items: center;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 999px;
    letter-spacing: 0.02em;
}
.badge-blue    { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
.badge-green   { background: rgba(16, 185, 129, 0.15); color: #34d399; }
.badge-amber   { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
.badge-red     { background: rgba(239, 68, 68, 0.15);  color: #f87171; }
.badge-slate   { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; }
.badge-purple  { background: rgba(168, 85, 247, 0.15); color: #c084fc; }

/* Links */
a.link { color: #60a5fa; text-decoration: none; }
a.link:hover { color: #93bbfc; text-decoration: underline; }

/* Tables */
.table { width: 100%; border-collapse: collapse; }
.table thead th {
    text-align: left;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
    letter-spacing: 0.03em;
    text-transform: uppercase;
    background: rgba(15, 23, 42, 0.6);
}
.table tbody td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
}
.table tbody tr:hover { background: rgba(51, 65, 85, 0.25); }

/* Headings */
h1.page-title {
    font-size: 24px;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -0.02em;
    margin: 0;
}
.section-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 16px;
}
</style>
</head>
<body>

<header class="relative z-10 border-b border-slate-800/60 bg-slate-950/40 backdrop-blur sticky top-0">
    <div class="max-w-7xl mx-auto px-6 py-3 flex items-center justify-between flex-wrap gap-4">
        <a href="index.php" class="flex items-center gap-3">
            <svg class="w-8 h-8" viewBox="0 0 32 32" fill="none">
                <rect width="32" height="32" rx="8" fill="#3b82f6"/>
                <path d="M8 12h16M8 16h10M8 20h14" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
            <div>
                <div class="font-bold text-slate-100 text-sm tracking-tight">Listetelemarketing</div>
                <div class="text-[10px] text-slate-500 uppercase tracking-widest">AI Lab</div>
            </div>
        </a>

        <nav class="flex items-center gap-1 flex-wrap">
            <a href="home.php"      class="font-medium text-sm px-3 py-2 border-b-2 <?= $active('home') ?> transition">Home</a>
            <a href="index.php"     class="font-medium text-sm px-3 py-2 border-b-2 <?= $active('ricerca') ?> transition">Ricerca AI</a>
            <a href="clienti.php"   class="font-medium text-sm px-3 py-2 border-b-2 <?= $active('clienti') ?> transition">Clienti</a>
            <a href="ordini.php"    class="font-medium text-sm px-3 py-2 border-b-2 <?= $active('ordini') ?> transition">Ordini</a>
            <a href="fatture.php"   class="font-medium text-sm px-3 py-2 border-b-2 <?= $active('fatture') ?> transition">Fatture</a>
            <?php if (function_exists('aiCurrentUserRole') && aiCurrentUserRole() === 'admin'): ?>
            <a href="utenti.php"    class="font-medium text-sm px-3 py-2 border-b-2 <?= $active('utenti') ?> transition">Utenti</a>
            <a href="metadata.php"  class="font-medium text-sm px-3 py-2 border-b-2 <?= $active('metadata') ?> transition">Config</a>
            <a href="logs.php"      class="font-medium text-sm px-3 py-2 border-b-2 <?= $active('logs') ?> transition">Log</a>
            <?php endif; ?>
            <a href="dashboard.php" class="font-medium text-sm px-3 py-2 border-b-2 <?= $active('dashboard') ?> transition">Stats</a>
        </nav>

        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2 text-xs">
                <div class="w-7 h-7 rounded-full bg-blue-500 text-white font-bold flex items-center justify-center text-xs"><?= htmlspecialchars($userInitial) ?></div>
                <span class="text-slate-300"><?= htmlspecialchars($user) ?></span>
            </div>
            <a href="logout.php" class="btn-ghost">Esci</a>
        </div>
    </div>
</header>
<?php
}

function aiRenderFooter(): void
{
?>
</body>
</html>
<?php
}
