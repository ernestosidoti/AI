<?php
/**
 * Scheda utente — admin: modifica, reset password, invio credenziali, fatturato
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
$userId = (int)($_GET['id'] ?? 0);
if (!$userId) { header('Location: utenti.php'); exit; }

$stmt = $backDb->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header('Location: utenti.php'); exit; }

$message = null;
$messageType = 'success';
$newTempPassword = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && aiVerifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $name = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $role = $_POST['role'] === 'admin' ? 'admin' : 'user';
            $commerciale = isset($_POST['commerciale']) ? 1 : 0;
            $telefono = trim($_POST['telefono'] ?? '');
            $active = isset($_POST['active']) ? 1 : 0;

            // Protezione: admin non può auto-disattivarsi
            if ($userId === aiCurrentUserId() && !$active) {
                $message = 'Non puoi disattivare il tuo account';
                $messageType = 'error';
            } elseif ($userId === aiCurrentUserId() && $role !== 'admin') {
                $message = 'Non puoi rimuovere il ruolo admin al tuo account';
                $messageType = 'error';
            } else {
                $backDb->prepare("UPDATE users SET name=?, email=?, role=?, commerciale=?, telefono=?, active=?, updated_at=NOW() WHERE id=?")
                    ->execute([$name, $email, $role, $commerciale, $telefono ?: null, $active, $userId]);
                aiLog('admin', 'user_updated', "Aggiornato utente #$userId ($email)", ['role' => $role, 'active' => $active, 'commerciale' => $commerciale]);
                $message = 'Utente aggiornato';
                // Reload
                $stmt = $backDb->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        if ($action === 'reset_password') {
            $newTempPassword = aiGenerateRandomPassword(12);
            $hash = password_hash($newTempPassword, PASSWORD_BCRYPT);
            $backDb->prepare("UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL, updated_at=NOW() WHERE id=?")
                ->execute([$hash, $userId]);
            aiLogSecurity('password_reset_admin', "Admin ha generato nuova password per uid=$userId ({$user['email']})");
            $message = 'Nuova password generata. Copiala e/o invia via email.';
        }

        if ($action === 'send_credentials') {
            // Per ora: richiede password appena generata oppure genera link reset
            $tempPwd = trim($_POST['temp_password'] ?? '');
            if (!$tempPwd) {
                $message = 'Prima genera una password con "Reset password", poi copia il valore e invialo.';
                $messageType = 'error';
            } else {
                // Invia email (uso mail() di PHP come fallback. In produzione sostituire con PHPMailer/SMTP)
                $subject = 'Le tue credenziali di accesso — Listetelemarketing AI Lab';
                $loginUrl = sprintf('http%s://%s%s/login.php',
                    isset($_SERVER['HTTPS']) ? 's' : '',
                    $_SERVER['HTTP_HOST'], AI_BASE_URL);
                $body = "Ciao {$user['name']},\n\n"
                      . "Un amministratore ti ha creato/aggiornato le credenziali di accesso.\n\n"
                      . "URL: $loginUrl\n"
                      . "Email: {$user['email']}\n"
                      . "Password: $tempPwd\n\n"
                      . "Ti consigliamo di cambiare la password dopo il primo accesso.\n\n"
                      . "— Listetelemarketing";
                $headers = "From: " . AI_SENDER_NAME . " <" . AI_SENDER_EMAIL . ">\r\n"
                         . "Reply-To: " . AI_SENDER_EMAIL . "\r\n"
                         . "Content-Type: text/plain; charset=UTF-8\r\n";

                $sent = @mail($user['email'], $subject, $body, $headers);
                if ($sent) {
                    aiLog('admin', 'credentials_sent', "Credenziali inviate a {$user['email']} per uid=$userId");
                    header('Location: utenti.php?pwdsent=' . $userId);
                    exit;
                } else {
                    aiLogError('admin', 'credentials_send_failed', "Invio credenziali fallito per {$user['email']}", ['uid' => $userId]);
                    $message = 'Invio email fallito. Controlla la configurazione SMTP del server. La password generata resta valida — puoi consegnarla a mano.';
                    $messageType = 'error';
                }
            }
        }

        if ($action === 'delete') {
            if ($userId === aiCurrentUserId()) {
                $message = 'Non puoi eliminare il tuo account';
                $messageType = 'error';
            } else {
                // Verifica dipendenze
                $nClienti = (int)$backDb->query("SELECT COUNT(*) FROM clientes WHERE user_id = $userId")->fetchColumn();
                $nOrdini = (int)$backDb->query("SELECT COUNT(*) FROM orders WHERE creatore = $userId")->fetchColumn();
                if ($nClienti > 0 || $nOrdini > 0) {
                    $message = "Impossibile eliminare: l'utente ha $nClienti clienti e $nOrdini ordini. Disattivalo invece di eliminarlo.";
                    $messageType = 'error';
                } else {
                    $backDb->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
                    aiLog('admin', 'user_deleted', "Eliminato utente #$userId ({$user['email']})", null, 'warning');
                    header('Location: utenti.php?deleted=' . $userId);
                    exit;
                }
            }
        }
    } catch (\Throwable $e) {
        $message = 'Errore: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Statistiche commerciale
$fatt = $backDb->prepare("SELECT
    YEAR(data_ora) AS anno,
    MONTH(data_ora) AS mese,
    COUNT(*) AS n_ordini,
    COALESCE(SUM(importo_bonifico), 0) AS importo,
    COALESCE(SUM(quantita), 0) AS quantita
    FROM orders WHERE creatore = ? AND data_ora >= DATE_SUB(NOW(), INTERVAL 24 MONTH)
    GROUP BY anno, mese ORDER BY anno DESC, mese DESC");
$fatt->execute([$userId]);
$fatturatoMesi = $fatt->fetchAll(PDO::FETCH_ASSOC);

$totStmt = $backDb->prepare("SELECT
    COUNT(*) AS tot_ordini,
    COALESCE(SUM(importo_bonifico), 0) AS tot_fatturato,
    COALESCE(SUM(CASE WHEN YEAR(data_ora)=YEAR(NOW()) THEN importo_bonifico END), 0) AS anno_corrente,
    COALESCE(SUM(CASE WHEN YEAR(data_ora)=YEAR(NOW()) AND MONTH(data_ora)=MONTH(NOW()) THEN importo_bonifico END), 0) AS mese_corrente
    FROM orders WHERE creatore = ?");
$totStmt->execute([$userId]);
$totStats = $totStmt->fetch(PDO::FETCH_ASSOC);

$nClienti = (int)$backDb->query("SELECT COUNT(*) FROM clientes WHERE user_id = $userId")->fetchColumn();

$csrf = aiCsrfToken();
aiRenderHeader('Utente #' . $userId, 'utenti');

$meseIt = ['', 'Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
?>

<main class="relative z-10 max-w-6xl mx-auto px-6 py-8">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <a href="utenti.php" class="link text-sm">← Utenti</a>
            <h1 class="page-title mt-2"><?= htmlspecialchars($user['name']) ?></h1>
            <p class="text-sm text-slate-400 mt-1 mono"><?= htmlspecialchars($user['email']) ?></p>
        </div>
        <div class="flex gap-2 flex-wrap">
            <?php if ((int)$user['active'] === 1): ?>
            <span class="badge badge-green">Attivo</span>
            <?php else: ?>
            <span class="badge badge-red">Disattivo</span>
            <?php endif; ?>
            <?php if ($user['role'] === 'admin'): ?>
            <span class="badge badge-purple">Admin</span>
            <?php endif; ?>
            <?php if ((int)$user['commerciale'] === 1): ?>
            <span class="badge badge-blue">Commerciale</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="glass rounded-lg p-3 mb-5 <?= $messageType === 'success' ? 'text-emerald-400' : 'text-red-400' ?>" style="border-color: rgba(<?= $messageType === 'success' ? '16,185,129' : '239,68,68' ?>, 0.35)">
        <p class="text-sm"><?= $messageType === 'success' ? '✓' : '✗' ?> <?= htmlspecialchars($message) ?></p>
    </div>
    <?php endif; ?>

    <?php if ($newTempPassword): ?>
    <div class="glass rounded-lg p-5 mb-5" style="border-color: rgba(245, 158, 11, 0.4); background: rgba(245,158,11,0.05)">
        <h3 class="text-amber-400 font-semibold mb-2">🔑 Nuova password generata</h3>
        <div class="text-sm mb-3">
            <span class="text-slate-400">Password temporanea:</span>
            <span class="mono text-emerald-400 font-bold bg-slate-900 px-3 py-1.5 rounded ml-2"><?= htmlspecialchars($newTempPassword) ?></span>
        </div>
        <form method="POST" class="flex items-center gap-2">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="send_credentials">
            <input type="hidden" name="temp_password" value="<?= htmlspecialchars($newTempPassword) ?>">
            <button type="submit" class="btn-primary">📧 Invia credenziali via email ora</button>
            <span class="text-xs text-slate-500">Oppure copia la password e consegnala a mano.</span>
        </form>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <!-- Stats -->
        <div class="lg:col-span-1 space-y-4">
            <div class="glass rounded-lg p-5">
                <h3 class="section-label">Panoramica</h3>
                <div class="space-y-3">
                    <div>
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Clienti assegnati</div>
                        <div class="text-2xl font-bold"><?= $nClienti ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Ordini totali</div>
                        <div class="text-2xl font-bold"><?= $totStats['tot_ordini'] ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Fatturato questo mese</div>
                        <div class="text-2xl font-bold text-emerald-400">€<?= number_format($totStats['mese_corrente'], 2, ',', '.') ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Fatturato <?= date('Y') ?></div>
                        <div class="text-2xl font-bold text-emerald-400">€<?= number_format($totStats['anno_corrente'], 2, ',', '.') ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-500 uppercase tracking-wider">Fatturato totale storico</div>
                        <div class="text-xl font-semibold text-slate-300">€<?= number_format($totStats['tot_fatturato'], 2, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <div class="glass rounded-lg p-5">
                <h3 class="section-label">Account</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-slate-400">ID</span><span class="mono">#<?= $user['id'] ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Telefono</span><span class="mono"><?= htmlspecialchars($user['telefono'] ?? '—') ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Creato</span><span class="mono text-xs"><?= date('d/m/Y', strtotime($user['created_at'])) ?></span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Ultimo login</span><span class="mono text-xs"><?= $user['ultimo_login'] ? date('d/m H:i', strtotime($user['ultimo_login'])) : '—' ?></span></div>
                </div>
            </div>

            <!-- Azioni password -->
            <div class="glass rounded-lg p-5">
                <h3 class="section-label">Password</h3>
                <form method="POST" onsubmit="return confirm('Generare una nuova password per questo utente? La vecchia verrà invalidata.');">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <button type="submit" class="btn-secondary w-full">🔑 Genera nuova password</button>
                </form>
                <p class="text-xs text-slate-500 mt-2">La password viene <strong>mostrata una volta</strong>. Puoi poi decidere se inviarla via email o consegnarla a mano.</p>
            </div>
        </div>

        <!-- Form dati + fatturato -->
        <div class="lg:col-span-2 space-y-5">

            <div class="glass rounded-lg p-5">
                <h3 class="section-label">Dati utente</h3>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="save">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label required">Nome</label>
                            <input type="text" name="name" required value="<?= htmlspecialchars($user['name']) ?>" class="form-input">
                        </div>
                        <div>
                            <label class="form-label required">Email</label>
                            <input type="email" name="email" required value="<?= htmlspecialchars($user['email']) ?>" class="form-input mono">
                        </div>
                        <div>
                            <label class="form-label">Telefono</label>
                            <input type="tel" name="telefono" value="<?= htmlspecialchars($user['telefono'] ?? '') ?>" class="form-input mono">
                        </div>
                        <div>
                            <label class="form-label required">Ruolo</label>
                            <select name="role" required class="form-input">
                                <option value="user" <?= $user['role']==='user'?'selected':'' ?>>User</option>
                                <option value="admin" <?= $user['role']==='admin'?'selected':'' ?>>Admin</option>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-2 pt-2">
                        <label class="flex items-center gap-3 p-3 rounded-lg bg-slate-800/40 border border-slate-700/50 cursor-pointer">
                            <input type="checkbox" name="commerciale" <?= (int)$user['commerciale']===1?'checked':'' ?> class="w-4 h-4">
                            <div>
                                <div class="text-sm font-medium">È un commerciale</div>
                                <div class="text-xs text-slate-500">Riceve clienti e ordini. Gli admin possono essere anche commerciali.</div>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 p-3 rounded-lg bg-slate-800/40 border border-slate-700/50 cursor-pointer">
                            <input type="checkbox" name="active" <?= (int)$user['active']===1?'checked':'' ?> class="w-4 h-4" <?= $userId === aiCurrentUserId() ? 'disabled' : '' ?>>
                            <div>
                                <div class="text-sm font-medium">Account attivo</div>
                                <div class="text-xs text-slate-500"><?= $userId === aiCurrentUserId() ? 'Non puoi disattivare il tuo account' : 'Se disattivo, non può più accedere' ?></div>
                            </div>
                        </label>
                    </div>

                    <div class="pt-4 border-t border-slate-800 flex items-center justify-between">
                        <?php if ($userId !== aiCurrentUserId()): ?>
                        <button type="button" onclick="if(confirm('Eliminare definitivamente questo utente?')) { document.getElementById('deleteForm').submit(); }" class="btn-danger">🗑 Elimina</button>
                        <?php else: ?>
                        <span></span>
                        <?php endif; ?>
                        <button type="submit" class="btn-primary">Salva modifiche</button>
                    </div>
                </form>
                <?php if ($userId !== aiCurrentUserId()): ?>
                <form id="deleteForm" method="POST" style="display:none">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete">
                </form>
                <?php endif; ?>
            </div>

            <!-- Fatturato mese per mese -->
            <?php if ((int)$user['commerciale'] === 1): ?>
            <div class="glass rounded-lg p-5">
                <h3 class="section-label">Fatturato ultimi 24 mesi</h3>
                <?php if (empty($fatturatoMesi)): ?>
                <p class="text-sm text-slate-500">Nessun ordine trovato per questo commerciale.</p>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Mese</th>
                            <th class="text-right">Ordini</th>
                            <th class="text-right">Quantità</th>
                            <th class="text-right">Importo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fatturatoMesi as $r): ?>
                        <tr>
                            <td><?= $meseIt[$r['mese']] ?> <?= $r['anno'] ?></td>
                            <td class="text-right mono"><?= $r['n_ordini'] ?></td>
                            <td class="text-right mono"><?= number_format($r['quantita'], 0, ',', '.') ?></td>
                            <td class="text-right mono text-emerald-400">€<?= number_format($r['importo'], 2, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php aiRenderFooter(); ?>
