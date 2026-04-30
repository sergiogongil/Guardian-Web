<?php
/**
 * mini-guardianweb — database helper.
 *
 * Opens (and on first run, creates) the SQLite database and the `registros`
 * table. Returns a singleton PDO instance.
 */

function mgw_db(array $config): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $path = $config['db_path'] ?? (__DIR__ . '/../data/mini-guardianweb.sqlite');
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // WAL is friendlier under concurrent writes from many page loads.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous  = NORMAL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS registros (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            ts          INTEGER NOT NULL,
            ip          TEXT,
            ip_hash     TEXT    NOT NULL,
            user_agent  TEXT,
            referrer    TEXT,
            lang        TEXT,
            path        TEXT    NOT NULL,
            host        TEXT,
            kind        TEXT    NOT NULL,
            bot_name    TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_registros_ts   ON registros(ts)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_registros_kind ON registros(kind)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_registros_path ON registros(path)");

    // Monthly summaries written by the purge process. One row per
    // (year_month, kind, bot_name). bot_name is '' when not applicable.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS resumenes_mensuales (
            year_month   TEXT    NOT NULL,
            kind         TEXT    NOT NULL,
            bot_name     TEXT    NOT NULL DEFAULT '',
            hits         INTEGER NOT NULL,
            unique_ips   INTEGER NOT NULL,
            PRIMARY KEY (year_month, kind, bot_name)
        )
    ");

    return $pdo;
}
