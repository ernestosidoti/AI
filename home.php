<?php
/**
 * Home — "Cosa vuoi fare?" dashboard azioni principali
 */
define('AILAB', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

aiSecurityHeaders();
aiRequireAuth();

$backDb = remoteDb(AI_BACKOFFICE_DB);
$aiDb = aiDb();
$uid = aiCurrentUserId();
$isAdmin = aiCurrentUserRole() === 'admin';

// Stats veloci
$myStats = [
    'clienti' => (int)$backDb->query("SELECT COUNT(*) FROM clientes" . ($isAdmin ? '' : " WHERE user_id = $uid"))->fetchColumn(),
    'ordini_da_evadere' => (int)$backDb->query("SELECT COUNT(*) FROM orders WHERE stato IN ('Da Evadere','Pronto da inviare','Statistica generata')" . ($isAdmin ? '' : " AND creatore = $uid"))->fetchColumn(),
    'ordini_mese' => (int)$backDb->query("SELECT COUNT(*) FROM orders WHERE YEAR(data_ora) = YEAR(NOW()) AND MONTH(data_ora) = MONTH(NOW())" . ($isAdmin ? '' : " AND creatore = $uid"))->fetchColumn(),
    'fatturato_mese' => (float)$backDb->query("SELECT COALESCE(SUM(importo_bonifico), 0) FROM orders WHERE YEAR(data_ora) = YEAR(NOW()) AND MONTH(data_ora) = MONTH(NOW())" . ($isAdmin ? '' : " AND creatore = $uid"))->fetchColumn(),
    'fatture_mese' => (int)$backDb->query("SELECT COUNT(*) FROM fatture WHERE YEAR(data_emissione) = YEAR(NOW()) AND MONTH(data_emissione) = MONTH(NOW())")->fetchColumn(),
    'ai_queries_oggi' => (int)$aiDb->query("SELECT COUNT(*) FROM queries WHERE DATE(created_at) = CURDATE()" . ($isAdmin ? '' : " AND user_name LIKE '%'"))->fetchColumn(),
];

aiRenderHeader('Home', 'home');
?>

<main class="relative z-10 max-w-7xl mx-auto px-6 py-10">

    <div class="mb-10">
        <h1 class="text-3xl font-bold tracking-tight">Ciao <?= htmlspecialchars(aiCurrentUser()) ?> 👋</h1>
        <p class="text-slate-400 mt-2 text-base">Cosa vuoi fare oggi?</p>
    </div>

    <!-- Stats riga superiore -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
        <div class="glass rounded-lg p-4">
            <div class="text-xs text-slate-400 uppercase tracking-wider">Clienti <?= $isAdmin ? '(tutti)' : '(tuoi)' ?></div>
            <div class="text-2xl font-bold mt-1"><?= number_format($myStats['clienti']) ?></div>
        </div>
        <div class="glass rounded-lg p-4">
            <div class="text-xs text-slate-400 uppercase tracking-wider">Ordini da evadere</div>
            <div class="text-2xl font-bold mt-1 <?= $myStats['ordini_da_evadere'] > 0 ? 'text-amber-400' : '' ?>"><?= number_format($myStats['ordini_da_evadere']) ?></div>
        </div>
        <div class="glass rounded-lg p-4">
            <div class="text-xs text-slate-400 uppercase tracking-wider">Ordini questo mese</div>
            <div class="text-2xl font-bold mt-1"><?= number_format($myStats['ordini_mese']) ?></div>
        </div>
        <div class="glass rounded-lg p-4">
            <div class="text-xs text-slate-400 uppercase tracking-wider">Fatturato mese</div>
            <div class="text-2xl font-bold text-emerald-400 mt-1">€<?= number_format($myStats['fatturato_mese'], 2, ',', '.') ?></div>
        </div>
    </div>

    <!-- Grid azioni -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

        <!-- NUOVO CLIENTE -->
        <a href="clienti.php" class="action-card group">
            <div class="icon-wrap" style="background: rgba(59, 130, 246, 0.12); color: #60a5fa;">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                </svg>
            </div>
            <h3 class="card-title">Gestione Clienti</h3>
            <p class="card-desc">Aggiungi, cerca o modifica clienti. Incolla dati anagrafici e il sistema li analizza.</p>
            <div class="card-footer">Vai ai clienti →</div>
        </a>

        <!-- NUOVO ORDINE -->
        <a href="nuovo_ordine.php" class="action-card group">
            <div class="icon-wrap" style="background: rgba(16, 185, 129, 0.12); color: #34d399;">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="card-title">Nuovo ordine</h3>
            <p class="card-desc">Carica un ordine da zero: cliente, prodotto, quantità, zona e specifiche.</p>
            <div class="card-footer">Crea ordine →</div>
        </a>

        <!-- DUPLICA ORDINE -->
        <a href="duplica_ordine.php" class="action-card group">
            <div class="icon-wrap" style="background: rgba(168, 85, 247, 0.12); color: #c084fc;">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
            </div>
            <h3 class="card-title">Duplica ordine</h3>
            <p class="card-desc">Riparti da un ordine precedente. Stessi parametri, nuova esecuzione.</p>
            <div class="card-footer">Trova e duplica →</div>
        </a>

        <!-- CONSULTA ORDINI -->
        <a href="ordini.php" class="action-card group">
            <div class="icon-wrap" style="background: rgba(245, 158, 11, 0.12); color: #fbbf24;">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
            </div>
            <h3 class="card-title">Consulta ordini</h3>
            <p class="card-desc">Elenco completo con filtri per stato, cliente, agente. Modifica o esegui con AI.</p>
            <div class="card-footer"><?= $myStats['ordini_da_evadere'] ?> da evadere →</div>
        </a>

        <!-- CONSULTA FATTURE -->
        <a href="fatture.php" class="action-card group">
            <div class="icon-wrap" style="background: rgba(236, 72, 153, 0.12); color: #f472b6;">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <h3 class="card-title">Fatture</h3>
            <p class="card-desc">Fatture emesse, stato pagamento, importi. Ricerca per cliente e periodo.</p>
            <div class="card-footer"><?= $myStats['fatture_mese'] ?> emesse questo mese →</div>
        </a>

        <!-- STATISTICHE -->
        <a href="dashboard.php" class="action-card group">
            <div class="icon-wrap" style="background: rgba(34, 211, 238, 0.12); color: #22d3ee;">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
            </div>
            <h3 class="card-title">Statistiche</h3>
            <p class="card-desc">Costi AI, query effettuate, storico attività. Report dettagliati.</p>
            <div class="card-footer"><?= $myStats['ai_queries_oggi'] ?> query AI oggi →</div>
        </a>

        <!-- RICERCA AI (quick access) -->
        <a href="index.php" class="action-card group" style="grid-column: span 2 / span 2;">
            <div class="flex items-center gap-5">
                <div class="icon-wrap" style="background: linear-gradient(135deg, #3b82f6, #8b5cf6, #ec4899); color: #fff;">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="card-title">Ricerca AI assistita</h3>
                    <p class="card-desc">Descrivi la lista in linguaggio naturale. Claude genera SQL, filtri e regole automatiche.</p>
                </div>
                <div class="text-blue-400 font-semibold text-sm">Inizia →</div>
            </div>
        </a>

        <!-- GESTIONE UTENTI (solo admin) -->
        <?php if ($isAdmin): ?>
        <a href="utenti.php" class="action-card group">
            <div class="icon-wrap" style="background: rgba(148, 163, 184, 0.12); color: #cbd5e1;">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
            </div>
            <h3 class="card-title">Utenti e commerciali</h3>
            <p class="card-desc">Crea account, gestisci ruoli, reset password, monitora fatturato per agente.</p>
            <div class="card-footer">Solo admin →</div>
        </a>
        <?php endif; ?>
    </div>

</main>

<style>
.action-card {
    display: block;
    padding: 24px;
    border-radius: 12px;
    background: rgba(30, 41, 59, 0.5);
    border: 1px solid rgba(148, 163, 184, 0.12);
    transition: all 0.2s;
    text-decoration: none;
    color: inherit;
}
.action-card:hover {
    background: rgba(51, 65, 85, 0.5);
    border-color: rgba(59, 130, 246, 0.4);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
}
.icon-wrap {
    width: 52px; height: 52px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 14px;
}
.action-card[style*="span 2"] .icon-wrap { margin-bottom: 0; flex-shrink: 0; }
.card-title {
    font-weight: 600;
    font-size: 16px;
    color: #f1f5f9;
    margin: 0 0 6px;
}
.card-desc {
    color: #94a3b8;
    font-size: 13px;
    line-height: 1.5;
    margin: 0 0 12px;
}
.card-footer {
    font-size: 12px;
    color: #60a5fa;
    font-weight: 600;
}
</style>

<?php aiRenderFooter(); ?>
