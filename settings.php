<?php
/**
 * LTM AI LAB — Configurazione API key Anthropic
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

aiSecurityHeaders();
aiRequireAuth();

$db = aiDb();
$message = null;
$messageType = 'success';
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && aiVerifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $apiKey = trim($_POST['api_key'] ?? '');
        if ($apiKey === '') {
            $message = 'La API key non può essere vuota.'; $messageType = 'error';
        } elseif (!str_starts_with($apiKey, 'sk-ant-')) {
            $message = 'Formato non valido. Deve iniziare con "sk-ant-".'; $messageType = 'error';
        } else {
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('anthropic_api_key', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$apiKey]);
            $message = 'API key salvata. Clicca "Testa connessione" per verificare.';
        }
    }

    if ($action === 'test') {
        $apiKey = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'anthropic_api_key'")->fetchColumn();
        if (!$apiKey) {
            $testResult = ['ok' => false, 'message' => 'Nessuna API key salvata'];
        } else {
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => 'claude-sonnet-4-5-20250929',
                    'max_tokens' => 20,
                    'messages' => [['role' => 'user', 'content' => 'Reply with just: OK']],
                ]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKey,
                    'anthropic-version: 2023-06-01',
                ],
                CURLOPT_TIMEOUT => 15,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $data = json_decode($resp, true);
            if ($code === 200 && isset($data['content'][0]['text'])) {
                $testResult = [
                    'ok' => true, 'message' => 'Connessione riuscita!',
                    'model' => $data['model'] ?? 'claude-sonnet-4-5',
                    'input_tokens' => $data['usage']['input_tokens'] ?? 0,
                    'output_tokens' => $data['usage']['output_tokens'] ?? 0,
                ];
            } else {
                $errMsg = $data['error']['message'] ?? "HTTP $code";
                $testResult = ['ok' => false, 'message' => 'Errore: ' . $errMsg];
            }
        }
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM settings WHERE setting_key = 'anthropic_api_key'")->execute();
        $message = 'API key eliminata.';
    }
}

$row = $db->query("SELECT setting_value, updated_at FROM settings WHERE setting_key = 'anthropic_api_key'")->fetch(PDO::FETCH_ASSOC);
$currentKey = $row['setting_value'] ?? '';
$lastUpdate = $row['updated_at'] ?? null;
$maskedKey = $currentKey ? substr($currentKey, 0, 14) . '••••••••••••' . substr($currentKey, -6) : '';
$csrf = aiCsrfToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>AI Laboratory — Settings</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Rajdhani:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
body { background: #000510; font-family: 'Rajdhani', sans-serif; color: #e0e7ff; min-height: 100vh; margin: 0; }
.orbitron { font-family: 'Orbitron', monospace; letter-spacing: 0.05em; }
.bg-grid { position: fixed; inset: 0; background-image: linear-gradient(rgba(99,102,241,0.08) 1px, transparent 1px), linear-gradient(90deg, rgba(99,102,241,0.08) 1px, transparent 1px); background-size: 50px 50px; z-index: 0; }
.bg-glow { position: fixed; inset: -10%; background: radial-gradient(circle at 20% 20%, rgba(99,102,241,0.15) 0%, transparent 40%), radial-gradient(circle at 80% 70%, rgba(236,72,153,0.12) 0%, transparent 40%); z-index: 0; pointer-events: none; }
.glass { background: rgba(10, 15, 30, 0.7); backdrop-filter: blur(16px); border: 1px solid rgba(99, 102, 241, 0.25); }
.neon-border { box-shadow: 0 0 30px rgba(99, 102, 241, 0.25); }
.btn-primary { background: linear-gradient(135deg, #22d3ee 0%, #6366f1 50%, #a855f7 100%); transition: all 0.3s; }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(99,102,241,0.5); }
.step-num { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(135deg, #22d3ee, #6366f1); color: #000; font-weight: 900; font-family: 'Orbitron', monospace; box-shadow: 0 0 15px rgba(34,211,238,0.5); }
</style>
</head>
<body>
<div class="bg-grid"></div>
<div class="bg-glow"></div>

<div class="relative z-10 max-w-3xl mx-auto px-6 py-8">

    <div class="flex items-center justify-between mb-8">
        <div>
            <a href="index.php" class="text-cyan-400 hover:text-cyan-300 text-sm mb-2 inline-block">&larr; Torna al Laboratory</a>
            <h1 class="orbitron text-3xl font-black bg-gradient-to-r from-cyan-400 via-purple-500 to-pink-500 bg-clip-text text-transparent mt-2">
                AI CORE CONFIGURATION
            </h1>
            <p class="text-slate-400 text-sm mt-1">Connessione al motore Claude Sonnet 4.5</p>
        </div>
        <a href="logout.php" class="text-xs text-slate-500 hover:text-red-400">Logout</a>
    </div>

    <?php if ($message): ?>
    <div class="glass rounded-xl p-4 mb-6 border-<?= $messageType === 'success' ? 'green' : 'red' ?>-500/50">
        <p class="text-<?= $messageType === 'success' ? 'green' : 'red' ?>-400">
            <?= $messageType === 'success' ? '✓' : '✗' ?> <?= htmlspecialchars($message) ?>
        </p>
    </div>
    <?php endif; ?>

    <?php if ($testResult): ?>
    <div class="glass rounded-xl p-5 mb-6 border-<?= $testResult['ok'] ? 'green' : 'red' ?>-500/50">
        <div class="flex items-start gap-3">
            <div class="text-3xl"><?= $testResult['ok'] ? '✓' : '✗' ?></div>
            <div class="flex-1">
                <p class="orbitron text-<?= $testResult['ok'] ? 'green' : 'red' ?>-400 font-bold"><?= htmlspecialchars($testResult['message']) ?></p>
                <?php if ($testResult['ok']): ?>
                <div class="mt-3 text-xs text-slate-300 font-mono">
                    <div>Modello: <span class="text-cyan-400"><?= htmlspecialchars($testResult['model']) ?></span></div>
                    <div>Token: <span class="text-cyan-400"><?= $testResult['input_tokens'] ?> in + <?= $testResult['output_tokens'] ?> out</span></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="glass neon-border rounded-xl p-6 mb-6">
        <h2 class="orbitron text-sm font-bold text-cyan-400 tracking-widest mb-3">STATO CONFIGURAZIONE</h2>
        <?php if ($currentKey): ?>
            <div class="flex items-center gap-3">
                <div class="w-3 h-3 rounded-full bg-green-400 animate-pulse"></div>
                <div class="flex-1">
                    <p class="text-white font-medium">API key attiva</p>
                    <p class="text-slate-400 text-xs mt-1 font-mono"><?= htmlspecialchars($maskedKey) ?></p>
                    <?php if ($lastUpdate): ?>
                    <p class="text-slate-500 text-xs mt-1">Aggiornata: <?= date('d/m/Y H:i', strtotime($lastUpdate)) ?></p>
                    <?php endif; ?>
                </div>
                <form method="POST" class="flex gap-2">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <button type="submit" name="action" value="test" class="orbitron px-4 py-2 bg-cyan-500/20 hover:bg-cyan-500/30 border border-cyan-500/50 text-cyan-400 text-xs rounded-lg tracking-wider">
                        TESTA CONNESSIONE
                    </button>
                    <button type="submit" name="action" value="delete" onclick="return confirm('Eliminare?')" class="orbitron px-4 py-2 bg-red-500/20 hover:bg-red-500/30 border border-red-500/50 text-red-400 text-xs rounded-lg tracking-wider">
                        ELIMINA
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="flex items-center gap-3">
                <div class="w-3 h-3 rounded-full bg-slate-500"></div>
                <p class="text-slate-400">Nessuna API key configurata</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="glass rounded-xl p-6 mb-6">
        <h2 class="orbitron text-sm font-bold text-yellow-400 tracking-widest mb-4">COME OTTENERE LA API KEY</h2>
        <div class="space-y-4">
            <div class="flex gap-3">
                <span class="step-num">1</span>
                <div class="flex-1">
                    <p class="text-white">Accedi a <a href="https://console.anthropic.com" target="_blank" class="text-cyan-400 hover:underline font-medium">console.anthropic.com</a></p>
                    <p class="text-slate-400 text-sm mt-1">Registrati con email se è la prima volta.</p>
                </div>
            </div>
            <div class="flex gap-3">
                <span class="step-num">2</span>
                <div class="flex-1">
                    <p class="text-white"><span class="text-cyan-400 font-mono">Settings → Billing</span>: aggiungi crediti</p>
                    <p class="text-slate-400 text-sm mt-1">Minimo $5. Con $5 fai ~300 query.</p>
                </div>
            </div>
            <div class="flex gap-3">
                <span class="step-num">3</span>
                <div class="flex-1">
                    <p class="text-white"><span class="text-cyan-400 font-mono">Settings → API Keys → Create Key</span></p>
                    <p class="text-slate-400 text-sm mt-1">Dai un nome (es. "AI Lab") e copia la key (inizia con <code class="text-cyan-300">sk-ant-api03-</code>).</p>
                </div>
            </div>
            <div class="flex gap-3">
                <span class="step-num">4</span>
                <div class="flex-1">
                    <p class="text-white">Incolla qui sotto e salva</p>
                    <p class="text-slate-400 text-sm mt-1">Poi clicca "TESTA CONNESSIONE" per verificare.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="glass neon-border rounded-xl p-6">
        <h2 class="orbitron text-sm font-bold text-cyan-400 tracking-widest mb-4">
            <?= $currentKey ? 'AGGIORNA API KEY' : 'INSERISCI API KEY' ?>
        </h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save">
            <input type="password" name="api_key" required autocomplete="off"
                   placeholder="sk-ant-api03-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                   class="w-full px-4 py-3 bg-slate-900/80 border border-cyan-500/30 rounded-lg text-cyan-100 font-mono text-sm focus:border-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/20">
            <button type="submit" class="btn-primary w-full orbitron py-3 text-white font-bold tracking-wider rounded-lg">
                <?= $currentKey ? 'AGGIORNA E SALVA' : 'SALVA API KEY' ?>
            </button>
        </form>
    </div>

    <div class="glass rounded-xl p-5 mt-6 text-xs text-slate-400">
        <h3 class="orbitron text-pink-400 font-bold mb-2">💰 PREZZI CLAUDE SONNET 4.5</h3>
        <div class="grid grid-cols-2 gap-4 font-mono">
            <div>
                <div class="text-slate-500">Input</div>
                <div class="text-cyan-400 text-base">$3.00 / 1M token</div>
            </div>
            <div>
                <div class="text-slate-500">Output</div>
                <div class="text-cyan-400 text-base">$15.00 / 1M token</div>
            </div>
        </div>
        <p class="mt-3 text-slate-500">
            Query tipica: ~$0.016 (1.5 cent). Con <span class="text-cyan-400">$5</span> fai ~300 query.
        </p>
    </div>

</div>
</body>
</html>
