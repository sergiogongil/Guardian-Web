<?php
/**
 * mini-guardianweb — auth helper.
 *
 * Optional HTTP Basic Auth gate for the dashboard and the guide page.
 * Reads credentials from $config['auth_user'] and $config['auth_password_hash'].
 * If either is empty, auth is disabled and the function returns immediately
 * (default behaviour — the install is public out of the box).
 *
 * Important caveats:
 *
 *   - HTTP Basic Auth sends credentials in base64 with EVERY request. Without
 *     HTTPS the password is trivially recoverable on the wire. Always enable
 *     HTTPS before relying on this.
 *
 *   - The check is stateless: each request re-validates. There is no session,
 *     no cookie, no logout endpoint. To "log out", the user closes the tab.
 *
 *   - For production-grade protection, web-server-level auth (Apache .htaccess
 *     + .htpasswd, or the Nginx equivalent) is preferable because the gate
 *     runs before any PHP code. This in-app auth is a convenience.
 *
 *   - Tracking endpoints (shield.php, api.php) are intentionally NOT
 *     protected: they need to receive hits from the public.
 *
 *   - purge.php uses its own token-based gate (purge_token) so cron / curl
 *     automations can run without a browser. It is independent from this.
 */

function mgw_require_auth(array $config): void
{
    $user = trim((string)($config['auth_user']          ?? ''));
    $hash = trim((string)($config['auth_password_hash'] ?? ''));

    // Both must be set, otherwise auth is disabled.
    if ($user === '' || $hash === '') {
        return;
    }

    $given_user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $given_pass = (string)($_SERVER['PHP_AUTH_PW']   ?? '');

    // Fallback for PHP-CGI / PHP-FPM setups where PHP_AUTH_USER and
    // PHP_AUTH_PW are NOT populated automatically. The credentials arrive
    // raw in the Authorization header instead. Without this fallback the
    // browser would loop forever on the auth dialog.
    if ($given_user === '' && $given_pass === '') {
        $auth_header = (string)($_SERVER['HTTP_AUTHORIZATION']
                              ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                              ?? '');
        if (stripos($auth_header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($auth_header, 6), true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                [$given_user, $given_pass] = explode(':', $decoded, 2);
            }
        }
    }

    // hash_equals is constant-time on the username; password_verify is the
    // standard secure compare against the bcrypt/argon2 hash.
    $user_ok = hash_equals($user, $given_user);
    $pass_ok = password_verify($given_pass, $hash);

    if (!$user_ok || !$pass_ok) {
        header('WWW-Authenticate: Basic realm="mini-guardianweb", charset="UTF-8"');
        http_response_code(401);
        // Body is just a fallback — the browser shows its own dialog.
        echo "Unauthorized\n";
        exit;
    }
}
