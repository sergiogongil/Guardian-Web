<?php
/**
 * mini-guardianweb — purge helper.
 *
 * Archives completed-month data from `registros` into `resumenes_mensuales`
 * and then deletes the archived rows. The current calendar month is never
 * touched, so the dashboard's recent-window queries keep their raw data.
 *
 * The summary insert uses INSERT OR REPLACE semantics, so running purge
 * twice on the same month is safe: the row is recomputed from whatever
 * raw data is still there for that month (which after the first purge is
 * none, so the values stay accurate).
 */

require_once __DIR__ . '/db.php';

/**
 * Run a full purge. Aggregates every completed month and deletes those rows.
 *
 * @param array $config  Application config.
 * @param bool  $vacuum  Run VACUUM after the delete to reclaim disk space.
 *                       Defaults to true. Disabled by the auto-purge path
 *                       to avoid blocking a web request for too long.
 *
 * @return array{months_archived:int, rows_deleted:int, rows_total_after:int}
 */
function mgw_purge(array $config, bool $vacuum = true): array
{
    $pdo = mgw_db($config);

    // Cutoff = first second of the current calendar month, local time.
    $cutoff = (int)strtotime(date('Y-m-01 00:00:00'));

    // Find the months we are going to archive.
    $stmt = $pdo->prepare("
        SELECT DISTINCT strftime('%Y-%m', ts, 'unixepoch', 'localtime') AS ym
        FROM registros
        WHERE ts < :cutoff
        ORDER BY ym
    ");
    $stmt->execute([':cutoff' => $cutoff]);
    $months = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$months) {
        return [
            'months_archived'  => 0,
            'rows_deleted'     => 0,
            'rows_total_after' => (int)$pdo->query('SELECT COUNT(*) FROM registros')->fetchColumn(),
        ];
    }

    $upsert = $pdo->prepare("
        INSERT INTO resumenes_mensuales (year_month, kind, bot_name, hits, unique_ips)
        VALUES (:ym, :kind, :bot, :hits, :uniq)
        ON CONFLICT(year_month, kind, bot_name) DO UPDATE SET
            hits       = excluded.hits,
            unique_ips = excluded.unique_ips
    ");

    $agg = $pdo->prepare("
        SELECT
            kind,
            COALESCE(bot_name, '') AS bot_name,
            COUNT(*)               AS hits,
            COUNT(DISTINCT ip_hash) AS uniq
        FROM registros
        WHERE strftime('%Y-%m', ts, 'unixepoch', 'localtime') = :ym
        GROUP BY kind, COALESCE(bot_name, '')
    ");

    $del = $pdo->prepare('DELETE FROM registros WHERE ts < :cutoff');

    $pdo->beginTransaction();
    try {
        foreach ($months as $ym) {
            $agg->execute([':ym' => $ym]);
            foreach ($agg->fetchAll() as $r) {
                $upsert->execute([
                    ':ym'   => $ym,
                    ':kind' => $r['kind'],
                    ':bot'  => $r['bot_name'],
                    ':hits' => (int)$r['hits'],
                    ':uniq' => (int)$r['uniq'],
                ]);
            }
        }

        $del->execute([':cutoff' => $cutoff]);
        $deleted = $del->rowCount();

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // VACUUM cannot run inside a transaction. It rewrites the database file
    // and reclaims space; a few hundred ms on a 100k-row file.
    if ($vacuum) {
        $pdo->exec('VACUUM');
    }

    return [
        'months_archived'  => count($months),
        'rows_deleted'     => $deleted,
        'rows_total_after' => (int)$pdo->query('SELECT COUNT(*) FROM registros')->fetchColumn(),
    ];
}

/**
 * Auto-purge gate: if `purge_max_rows` is set and the table is over the
 * threshold, run a purge without VACUUM (we don't want to block a web
 * request that long). Returns true if a purge actually ran.
 */
function mgw_maybe_purge(array $config): bool
{
    if (empty($config['purge_max_rows'])) {
        return false;
    }
    $pdo   = mgw_db($config);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM registros')->fetchColumn();
    if ($count <= (int)$config['purge_max_rows']) {
        return false;
    }
    mgw_purge($config, vacuum: false);
    return true;
}
