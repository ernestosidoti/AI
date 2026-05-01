<?php
/**
 * WaitMessenger — manda messaggi periodici "sto lavorando" durante elaborazioni lente.
 *
 * Pattern:
 *   WaitMessenger::start($chatId, 'estrai');
 *   $result = ...query lenta...;
 *   WaitMessenger::stop();
 *
 * Sfrutta pcntl_fork (disponibile in PHP CLI). In ambienti senza fork → no-op silenzioso.
 *
 * Logica:
 *   - Prima frase dopo 30s, poi ogni 30s una diversa (mai la stessa due volte di fila).
 *   - Quando il main process finisce → SIGTERM al child.
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

require_once __DIR__ . '/telegram.php';

class WaitMessenger
{
    /** @var int|null pid del processo child */
    private static $pid = null;

    /** Frasi raggruppate per contesto */
    private static $messages = [
        'estrai' => [
            "🔍 Sto cercando i numeri nelle fonti dati...",
            "⚙️ Sto applicando i filtri geografici e di categoria...",
            "🗄 Sto incrociando con il magazzino per la deduplica...",
            "📊 Sto unificando i risultati delle fonti compatibili...",
            "🔢 Sto contando i mobili univoci...",
            "📋 Sto preparando il file xlsx con anagrafica completa...",
            "⌛ Ancora qualche secondo, sto finendo l'estrazione...",
            "📦 Sto compilando le colonne del file Excel...",
            "🎯 Sto selezionando i record migliori per te...",
            "💾 Sto salvando il file e registrando la spedizione...",
            "⏳ Operazione in corso, le fonti sono grandi (milioni di record)...",
            "🚀 Quasi pronto, sto ottimizzando il risultato...",
            "🔄 Sto controllando i numeri già consegnati per evitare doppioni...",
            "📱 Sto recuperando i numeri di telefono validi...",
            "🏘 Sto filtrando per provincia/comune richiesto...",
            "⌚ Server al lavoro, query intensiva sui database...",
            "🧩 Sto assemblando i pezzi: anagrafica + telefoni + indirizzi...",
            "🛠 Sto facendo il join con il magazzino storico...",
            "📈 Quasi fatto, ultimo passaggio in corso...",
            "🎲 Sto mescolando i risultati per varietà...",
        ],
        'stat' => [
            "📊 Sto contando i record nelle fonti dati...",
            "⚙️ Sto incrociando le 3 fonti principali per dedup mobile...",
            "🔢 Sto aggregando i totali per provincia/regione...",
            "🗄 Sto verificando il magazzino del cliente per anti-join...",
            "📈 Sto calcolando i numeri disponibili al netto dei consegnati...",
            "🌍 Sto raggruppando per area geografica richiesta...",
            "⏳ Operazione su milioni di record, ancora qualche secondo...",
            "🔍 Sto applicando filtri data attivazione...",
            "📚 Sto analizzando le fonti compatibili...",
            "💡 Sto preparando il riepilogo per fonte...",
            "📞 Sto distinguendo mobili da fissi...",
            "🏘 Sto contando per ogni comune/provincia...",
            "⌛ Quasi fatto, sto preparando il report finale...",
            "🎯 Sto calcolando le percentuali per area...",
            "🚀 Server al lavoro su query complesse...",
            "📋 Sto generando i totali aggregati...",
            "⚡ Stat in corso su database multi-fonte...",
            "🔄 Sto deduplicando i mobili tra le fonti...",
            "📐 Sto calcolando le metriche di copertura...",
            "🧮 Quasi pronto, ultimo conteggio in corso...",
        ],
        'generic' => [
            "⏳ Operazione in corso, attendo qualche secondo in più...",
            "🔄 Sto lavorando, resto in attesa per favore...",
            "⚙️ Server al lavoro, un attimo di pazienza...",
            "🚀 Sto elaborando, ancora qualche istante...",
            "💪 Quasi pronto, ci siamo...",
        ],
    ];

    /**
     * Avvia il processo child che manda messaggi ogni $intervalSec.
     * @param string $context 'estrai' | 'stat' | 'generic' — set di frasi
     * @param int $intervalSec — intervallo in secondi (default 30)
     */
    public static function start(int $chatId, string $context = 'generic', int $intervalSec = 30): void
    {
        if (self::$pid !== null) return;  // già attivo
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) return;

        $messages = self::$messages[$context] ?? self::$messages['generic'];
        if (count($messages) < 2) return;

        // Shuffle iniziale per varietà tra sessioni
        shuffle($messages);

        $pid = @pcntl_fork();
        if ($pid === -1) return;  // fork failed

        if ($pid === 0) {
            // === CHILD ===
            // Disconnetti dai db pdo del parent (le connessioni sono condivise dopo fork)
            // Le riapriremo on-demand
            $sentIdx = [];  // tiene traccia degli indici già inviati per non ripetere
            $idx = 0;
            while (true) {
                sleep($intervalSec);
                // Scegli un messaggio non ancora usato (se tutti usati, ricomincia)
                if (count($sentIdx) >= count($messages)) $sentIdx = [];
                $available = array_diff(array_keys($messages), $sentIdx);
                $next = $available ? array_values($available)[array_rand(array_values($available))] : 0;
                $sentIdx[] = $next;
                try { TG::sendMessage($chatId, $messages[$next]); } catch (\Throwable $e) {}
            }
            exit(0);
        }

        // === PARENT ===
        self::$pid = $pid;
    }

    /** Ferma il processo child (SIGTERM) */
    public static function stop(): void
    {
        if (self::$pid === null) return;
        try {
            @posix_kill(self::$pid, 15);  // SIGTERM
            // Reap zombie
            pcntl_waitpid(self::$pid, $status, WNOHANG);
        } catch (\Throwable $e) {}
        self::$pid = null;
    }

    /** Cleanup automatico al shutdown del parent */
    public static function registerShutdown(): void
    {
        register_shutdown_function([self::class, 'stop']);
    }
}
