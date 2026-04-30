<?php
/**
 * mini-guardianweb — api.php
 *
 * HTTP tracking endpoint for sites that cannot include shield.php
 * (static HTML, non-PHP backends, cross-domain tracking).
 *
 * Two ways to use it:
 *
 *   1) Pixel (GET) — works without JavaScript:
 *
 *        <img src="https://your-host/api.php?p=/about&h=example.com"
 *             alt="" width="1" height="1" style="position:absolute">
 *
 *      Always returns a 1x1 transparent GIF.
 *
 *   2) JSON (POST) — for fetch() from JavaScript:
 *
 *        fetch('https://your-host/api.php', {
 *            method: 'POST',
 *            headers: {'Content-Type': 'application/json'},
 *            body: JSON.stringify({
 *                path: location.pathname + location.search,
 *                host: location.host
 *            })
 *        });
 *
 *      Returns {"ok": true}.
 *
 * Accepted parameters (GET query, POST body, or POST JSON):
 *   p / path  — page path being tracked. Falls back to the Referer's path.
 *   h / host  — host being tracked.       Falls back to the Referer's host.
 *
 * Note: IP, user-agent, referrer and Accept-Language are always taken from
 * the actual request headers — they cannot be spoofed via parameters.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// CORS preflight.
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $cfg_path = __DIR__ . '/config.php';
    if (!is_file($cfg_path)) {
        throw new RuntimeException('config.php missing');
    }
    $config = require $cfg_path;
    if (!is_array($config)) {
        throw new RuntimeException('config.php invalid');
    }

    require_once __DIR__ . '/lib/record.php';

    // --- Resolve path + host ----------------------------------------------
    $path = $_GET['p'] ?? $_GET['path'] ?? $_POST['p'] ?? $_POST['path'] ?? null;
    $host = $_GET['h'] ?? $_GET['host'] ?? $_POST['h'] ?? $_POST['host'] ?? null;

    // JSON body fallback.
    if ($path === null || $host === null) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $body = json_decode($raw, true);
            if (is_array($body)) {
                $path = $path ?? ($body['path'] ?? null);
                $host = $host ?? ($body['host'] ?? null);
            }
        }
    }

    // Last fallback: parse Referer.
    if (($path === null || $host === null) && !empty($_SERVER['HTTP_REFERER'])) {
        $parts = parse_url($_SERVER['HTTP_REFERER']);
        if (is_array($parts)) {
            $path = $path ?? ($parts['path'] ?? '/');
            $host = $host ?? ($parts['host'] ?? '');
        }
    }

    mgw_record($config, [
        'path' => $path ?? '/',
        'host' => $host ?? '',
    ]);
} catch (\Throwable $e) {
    // Silent: don't leak errors from a public endpoint.
    // For debugging, temporarily uncomment:
    // error_log('[mini-guardianweb api] ' . $e->getMessage());
}

// --- Response -------------------------------------------------------------
if ($method === 'POST') {
    header('Content-Type: application/json');
    echo '{"ok":true}';
} else {
    // 1x1 transparent GIF.
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
}
