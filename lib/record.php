<?php
/**
 * mini-guardianweb — record helper.
 *
 * Single source of truth for inserting a hit into the `registros` table.
 * Used by both shield.php (PHP include) and api.php (HTTP endpoint).
 *
 * The IP, user-agent, referrer and language are always taken from the
 * request that hit *this* server — they cannot be spoofed via $overrides.
 * Only `path` and `host` can be overridden, because when api.php is called
 * from a remote page those server vars point at api.php itself, not at the
 * page being tracked.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/classify.php';

function mgw_record(array $config, array $overrides = []): void
{
    $ip  = $_SERVER['REMOTE_ADDR']          ?? '';
    $ua  = $_SERVER['HTTP_USER_AGENT']      ?? '';
    $ref = $_SERVER['HTTP_REFERER']         ?? '';
    $al  = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

    $path = $overrides['path'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
    $host = $overrides['host'] ?? ($_SERVER['HTTP_HOST']   ?? '');

    // Single-site enforcement. mini-guardianweb tracks exactly one site per
    // install (see CLAUDE.md). When site_host is configured, hits coming
    // from any other Host header are dropped silently — without this, a
    // misrouted shield.php include or a stranger calling api.php from
    // another domain would mix foreign data into the stats and the monthly
    // summaries would lose the ability to tell sites apart.
    //
    // The comparison strips a leading 'www.' from both sides, so configuring
    // site_host as 'example.com' accepts hits from both 'example.com' and
    // 'www.example.com' (and vice-versa). Otherwise sites that don't 301
    // to a canonical hostname would silently lose half their traffic.
    //
    // An empty site_host disables the filter (useful in early setup).
    $expected_host = trim((string)($config['site_host'] ?? ''));
    if ($expected_host !== '') {
        $norm_expected = preg_replace('/^www\./i', '', strtolower($expected_host));
        $norm_actual   = preg_replace('/^www\./i', '', strtolower((string)$host));
        if ($norm_expected !== $norm_actual) {
            return;
        }
    }

    // First language token, lowercased and capped (e.g. "es-ES,es;q=0.9" -> "es-es").
    $lang = '';
    if ($al !== '') {
        $first = trim(explode(',', $al)[0]);
        $lang  = strtolower(substr($first, 0, 8));
    }

    $cls     = mgw_classify($ua);
    $salt    = (string)($config['salt'] ?? '');
    $ip_hash = hash('sha256', $salt . '|' . $ip);

    $pdo  = mgw_db($config);
    $stmt = $pdo->prepare("
        INSERT INTO registros
            (ts, ip, ip_hash, user_agent, referrer, lang, path, host, kind, bot_name)
        VALUES
            (:ts, :ip, :ip_hash, :ua, :ref, :lang, :path, :host, :kind, :bot)
    ");
    $stmt->execute([
        ':ts'      => time(),
        ':ip'      => !empty($config['store_ip']) ? $ip : null,
        ':ip_hash' => $ip_hash,
        ':ua'      => $ua   !== '' ? substr($ua,  0, 500) : null,
        ':ref'     => $ref  !== '' ? substr($ref, 0, 500) : null,
        ':lang'    => $lang !== '' ? $lang : null,
        ':path'    => substr((string)$path, 0, 2000),
        ':host'    => $host !== '' ? substr((string)$host, 0, 255) : null,
        ':kind'    => $cls['kind'],
        ':bot'     => $cls['bot_name'],
    ]);

    // Auto-purge gate. We only want to query COUNT(*) on a small sample of
    // hits, otherwise every page view would do a table scan. With a 1%
    // sample, after the threshold is crossed the next sampled hit triggers
    // the purge — typically within a hundred more page loads.
    if (!empty($config['purge_max_rows']) && mt_rand(1, 100) === 1) {
        try {
            require_once __DIR__ . '/purge.php';
            mgw_maybe_purge($config);
        } catch (\Throwable $e) {
            // Silent: never break the host page because of a maintenance task.
        }
    }
}
