<?php
/**
 * mini-guardianweb — purge.php
 *
 * Manual entrypoint for the purge process. Two ways to invoke it:
 *
 *   1) CLI (recommended for cron / sysadmin):
 *
 *        php purge.php
 *
 *      Always works. No token required.
 *
 *   2) HTTP, only if `purge_token` is set in config.php:
 *
 *        curl "https://your-host/purge.php?token=YOUR_TOKEN"
 *
 *      Without a token configured, HTTP access is rejected with 403 so a
 *      stranger cannot drop data by hitting the URL.
 *
 * Output:
 *   CLI  → human-readable summary.
 *   HTTP → JSON: {"ok": true, "months_archived": N, "rows_deleted": M, ...}
 */

$cfg_path = __DIR__ . '/config.php';
if (!is_file($cfg_path)) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Missing config.php — copy config.example.php and edit it.\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing config.php\n";
    exit;
}
$config = require $cfg_path;
if (!is_array($config)) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "config.php is invalid.\n");
        exit(1);
    }
    http_response_code(500);
    echo "Invalid config\n";
    exit;
}

require_once __DIR__ . '/lib/purge.php';

$is_cli = (php_sapi_name() === 'cli');

// --- Auth (HTTP only) -----------------------------------------------------
if (!$is_cli) {
    $token_required = (string)($config['purge_token'] ?? '');
    if ($token_required === '') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden: HTTP purge is disabled. Set purge_token in config.php to enable it.\n";
        exit;
    }
    $token_given = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    if (!hash_equals($token_required, $token_given)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden: invalid token.\n";
        exit;
    }
}

// --- Run -----------------------------------------------------------------
try {
    $result = mgw_purge($config);

    if ($is_cli) {
        echo "Purge complete.\n";
        echo "  Months archived:    {$result['months_archived']}\n";
        echo "  Rows deleted:       {$result['rows_deleted']}\n";
        echo "  Rows in registros:  {$result['rows_total_after']}\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true] + $result);
    }
} catch (\Throwable $e) {
    if ($is_cli) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
