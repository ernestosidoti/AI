<?php
/**
 * TGArchive — archivio completo conversazioni Telegram (per audit/review).
 *
 * - logIn(): salva messaggio user→bot
 * - logOut(): salva messaggio bot→user
 * - logSystem(): salva evento interno (errore, cambio stato, eccezione)
 *
 * Sessioni: stesso session_id finché c'è attività entro 5 min sulla stessa chat;
 * dopo 5 min inattività, la sessione successiva ha session_id nuovo.
 */

if (!defined('AILAB')) { http_response_code(403); exit('Accesso negato'); }

require_once __DIR__ . '/db.php';

class TGArchive
{
    /** Soglia inattività (secondi) per chiudere una sessione */
    const SESSION_TIMEOUT_SEC = 300;

    /** Cache in-process per non rifare la query session_id ad ogni chiamata */
    private static $sessionCache = [];

    /**
     * Ricava (o crea) il session_id corrente per una chat.
     * Logica: se l'ultima riga per chat_id è entro SESSION_TIMEOUT → riusa quel session_id.
     */
    public static function currentSessionId(int $chatId): string
    {
        if (isset(self::$sessionCache[$chatId])) {
            [$sid, $expiresAt] = self::$sessionCache[$chatId];
            if (time() < $expiresAt) {
                self::$sessionCache[$chatId] = [$sid, time() + self::SESSION_TIMEOUT_SEC];
                return $sid;
            }
        }

        try {
            $pdo = remoteDb('ai_laboratory');
            $st = $pdo->prepare("SELECT session_id, UNIX_TIMESTAMP(ts) FROM tg_conversation_archive
                                 WHERE chat_id = ? ORDER BY ts DESC LIMIT 1");
            $st->execute([$chatId]);
            $row = $st->fetch(PDO::FETCH_NUM);
            if ($row) {
                $lastTs = (int)$row[1];
                if ((time() - $lastTs) < self::SESSION_TIMEOUT_SEC) {
                    self::$sessionCache[$chatId] = [$row[0], time() + self::SESSION_TIMEOUT_SEC];
                    return $row[0];
                }
            }
        } catch (\Throwable $e) {
            // se DB non risponde, fallback su sessione fresca
        }

        $sid = self::makeSessionId($chatId);
        self::$sessionCache[$chatId] = [$sid, time() + self::SESSION_TIMEOUT_SEC];
        return $sid;
    }

    private static function makeSessionId(int $chatId): string
    {
        return substr(hash('sha256', $chatId . '_' . microtime(true) . '_' . random_int(0, 9999)), 0, 32);
    }

    /** Salva messaggio dell'utente (in entrata al bot) */
    public static function logIn(int $chatId, ?int $userId, ?string $userName, ?string $userEmail, string $text, array $meta = []): void
    {
        self::insert($chatId, $userId, $userName, $userEmail, 'in', $text, $meta);
    }

    /** Salva messaggio del bot (in uscita verso utente) */
    public static function logOut(int $chatId, string $text, array $meta = []): void
    {
        self::insert($chatId, null, null, null, 'out', $text, $meta);
    }

    /** Salva evento di sistema (eccezione, cambio stato, debug) */
    public static function logSystem(int $chatId, string $note, array $meta = []): void
    {
        self::insert($chatId, null, null, null, 'system', $note, $meta);
    }

    private static function insert(int $chatId, ?int $userId, ?string $userName, ?string $userEmail, string $dir, string $text, array $meta): void
    {
        try {
            $pdo = remoteDb('ai_laboratory');
            $st = $pdo->prepare("INSERT INTO tg_conversation_archive
                (session_id, chat_id, user_id, user_name, user_email, direction, text, meta, ts)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(3))");
            $st->execute([
                self::currentSessionId($chatId),
                $chatId,
                $userId,
                $userName ? mb_substr($userName, 0, 100) : null,
                $userEmail ? mb_substr($userEmail, 0, 150) : null,
                $dir,
                mb_substr($text, 0, 60000),  // protezione TEXT (max 64k)
                $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('TGArchive insert error: ' . $e->getMessage());
        }
    }

    /**
     * Tagga la sessione corrente con cliente_id + cliente_name + action_type.
     * Aggiorna TUTTE le righe della sessione (retroattivo + prossimi messaggi della stessa sessione).
     * Da chiamare quando il bot identifica un cliente o un'azione concreta (estrai/stat/storico).
     */
    public static function tagSession(int $chatId, ?int $clienteId = null, ?string $clienteName = null, ?string $actionType = null): void
    {
        try {
            $sid = self::currentSessionId($chatId);
            $pdo = remoteDb('ai_laboratory');
            $sets = []; $params = [];
            if ($clienteId !== null)   { $sets[] = 'cliente_id = ?';   $params[] = $clienteId; }
            if ($clienteName !== null) { $sets[] = 'cliente_name = ?'; $params[] = mb_substr($clienteName, 0, 150); }
            if ($actionType !== null)  { $sets[] = 'action_type = ?';  $params[] = mb_substr($actionType, 0, 30); }
            if (!$sets) return;
            $params[] = $sid;
            $pdo->prepare("UPDATE tg_conversation_archive SET " . implode(', ', $sets) . " WHERE session_id = ?")
                ->execute($params);
        } catch (\Throwable $e) {
            error_log('TGArchive::tagSession: ' . $e->getMessage());
        }
    }

    /**
     * Cerca tutte le sessioni che hanno coinvolto un cliente specifico (per nome o id),
     * opzionalmente filtrate per arco temporale.
     */
    public static function sessionsForCliente(?int $clienteId, ?string $clienteName = null, int $limit = 100, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $pdo = remoteDb('ai_laboratory');
        $clienteWhere = []; $p = [];
        if ($clienteId)   { $clienteWhere[] = 'cliente_id = ?';   $p[] = $clienteId; }
        if ($clienteName) { $clienteWhere[] = 'cliente_name LIKE ?'; $p[] = '%' . $clienteName . '%'; }
        if (!$clienteWhere) return [];

        $w = ['(' . implode(' OR ', $clienteWhere) . ')'];
        if ($dateFrom) { $w[] = 'ts >= ?'; $p[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo)   { $w[] = 'ts <= ?'; $p[] = $dateTo . ' 23:59:59'; }
        $where = 'WHERE ' . implode(' AND ', $w);

        $sql = "SELECT session_id,
                       MIN(ts) AS started_at,
                       MAX(ts) AS ended_at,
                       MAX(chat_id) AS chat_id,
                       MAX(user_id) AS user_id,
                       MAX(user_name) AS user_name,
                       MAX(cliente_id) AS cliente_id,
                       MAX(cliente_name) AS cliente_name,
                       MAX(action_type) AS action_type,
                       SUM(direction='in')  AS msg_in,
                       SUM(direction='out') AS msg_out,
                       SUM(direction='system') AS msg_sys,
                       COUNT(*) AS msg_total
                FROM tg_conversation_archive
                $where
                GROUP BY session_id
                ORDER BY ended_at DESC
                LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lista sessioni con preview e contatori, ordinate per ultima attività */
    public static function listSessions(int $limit = 100, ?int $chatId = null, ?string $dateFrom = null, ?string $dateTo = null, ?string $clienteSearch = null, ?string $actionType = null): array
    {
        $pdo = remoteDb('ai_laboratory');
        $w = []; $p = [];
        if ($chatId)        { $w[] = 'chat_id = ?';      $p[] = $chatId; }
        if ($dateFrom)      { $w[] = 'ts >= ?';          $p[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo)        { $w[] = 'ts <= ?';          $p[] = $dateTo . ' 23:59:59'; }
        if ($clienteSearch) { $w[] = 'cliente_name LIKE ?'; $p[] = '%' . $clienteSearch . '%'; }
        if ($actionType)    { $w[] = 'action_type = ?';  $p[] = $actionType; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        $sql = "SELECT session_id,
                       MIN(ts) AS started_at,
                       MAX(ts) AS ended_at,
                       MAX(chat_id) AS chat_id,
                       MAX(user_id) AS user_id,
                       MAX(user_name) AS user_name,
                       MAX(user_email) AS user_email,
                       MAX(cliente_id) AS cliente_id,
                       MAX(cliente_name) AS cliente_name,
                       MAX(action_type) AS action_type,
                       SUM(direction='in')  AS msg_in,
                       SUM(direction='out') AS msg_out,
                       SUM(direction='system') AS msg_sys,
                       COUNT(*) AS msg_total,
                       (SELECT text FROM tg_conversation_archive a2
                          WHERE a2.session_id = a1.session_id AND a2.direction='in'
                          ORDER BY a2.ts ASC LIMIT 1) AS first_user_msg
                FROM tg_conversation_archive a1
                $where
                GROUP BY session_id
                ORDER BY ended_at DESC
                LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Recupera tutti i messaggi di una sessione */
    public static function getSession(string $sessionId): array
    {
        $pdo = remoteDb('ai_laboratory');
        $st = $pdo->prepare("SELECT id, direction, text, meta, ts, user_name FROM tg_conversation_archive
                             WHERE session_id = ? ORDER BY ts ASC, id ASC");
        $st->execute([$sessionId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Statistiche aggregate (per dashboard) */
    public static function stats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $pdo = remoteDb('ai_laboratory');
        $w = []; $p = [];
        if ($dateFrom){ $w[] = 'ts >= ?'; $p[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo)  { $w[] = 'ts <= ?'; $p[] = $dateTo . ' 23:59:59'; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        $sql = "SELECT
            COUNT(DISTINCT session_id) AS sessions,
            COUNT(DISTINCT chat_id) AS unique_users,
            SUM(direction='in')  AS msg_in,
            SUM(direction='out') AS msg_out,
            SUM(direction='system') AS msg_sys,
            COUNT(*) AS msg_total
          FROM tg_conversation_archive $where";
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
