<?php
/**
 * DB — Connessione al server MySQL remoto con gestione multi-database
 */

if (!defined('AILAB')) {
    http_response_code(403);
    exit('Accesso negato');
}

/**
 * Connessione al DB AI Laboratory (settings, logs)
 */
function aiDb(): PDO
{
    static $pdo = null;
    $connect = function (): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            AI_DB_HOST, AI_DB_PORT, AI_DB_NAME
        );
        return new PDO($dsn, AI_DB_USER, AI_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    };
    if ($pdo === null) { $pdo = $connect(); return $pdo; }
    try {
        $pdo->query('SELECT 1');
    } catch (\PDOException $e) {
        $code = $e->errorInfo[1] ?? 0;
        if (in_array($code, [2006, 2013], true) || str_contains($e->getMessage(), 'gone away')) {
            $pdo = $connect();
        } else { throw $e; }
    }
    return $pdo;
}

/**
 * Connessione fresca a qualsiasi DB del server remoto (usata per le query utente)
 */
function remoteDb(string $dbName): PDO
{
    static $pdos = [];
    $connect = function () use ($dbName): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            AI_DB_HOST, AI_DB_PORT, $dbName
        );
        return new PDO($dsn, AI_DB_USER, AI_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    };

    if (!isset($pdos[$dbName])) {
        $pdos[$dbName] = $connect();
        return $pdos[$dbName];
    }

    // Ping: se la connessione è morta (poller long-running), riconnetti.
    try {
        $pdos[$dbName]->query('SELECT 1');
    } catch (\PDOException $e) {
        $code = $e->errorInfo[1] ?? 0;
        // 2006 = server has gone away, 2013 = lost connection
        if (in_array($code, [2006, 2013], true) || str_contains($e->getMessage(), 'gone away')) {
            $pdos[$dbName] = $connect();
        } else {
            throw $e;
        }
    }
    return $pdos[$dbName];
}

/**
 * Connessione senza specificare DB (per cross-database queries)
 */
function rawDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            AI_DB_HOST, AI_DB_PORT
        );
        $pdo = new PDO($dsn, AI_DB_USER, AI_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
