<?php
/**
 * mini-guardianweb — configuration template.
 *
 * Copy this file to config.php and edit the values.
 * config.php is gitignored so each install keeps its own settings.
 */
return [
    // Secret string used to hash visitor IPs.
    // Change it to any random value. If you change it later, "unique visitor"
    // counts will reset (old hashes won't match new ones).
    'salt'     => 'change-me-to-a-long-random-string',

    // Path to the SQLite file. It will be created automatically on first hit.
    'db_path'  => __DIR__ . '/data/mini-guardianweb.sqlite',

    // The single domain this install is tracking. mini-guardianweb is
    // single-site by design — one install, one site. When this value is
    // set, any hit whose Host header does NOT match is silently dropped,
    // so the database can never be polluted with data from another site.
    // The dashboard also displays this value as the tracked domain.
    //
    // Leave empty for first-run / development (no filtering, nothing shown
    // in the header). Set it as soon as you know your production host
    // (e.g. 'mini.guardianweb.es') for clean and coherent stats.
    'site_host' => '',

    // Privacy mode.
    //   true  = store the raw IP in the `ip` column (default).
    //   false = store NULL in `ip`, keep only `ip_hash`. Unique-visitor counts
    //           still work, but you cannot recover the original IP.
    'store_ip' => true,

    // --- Auth (optional) --------------------------------------------------
    // If both fields are filled, the dashboard (index.php) and the guide
    // (guide.php) require HTTP Basic Auth with these credentials. Leave
    // both empty to keep the install public (default).
    //
    // To generate the password hash, run from the project root:
    //
    //     php -r "echo password_hash('your-password', PASSWORD_DEFAULT), \"\n\";"
    //
    // Then paste the resulting string (starts with $2y$ or $argon2…) into
    // 'auth_password_hash' below.
    //
    // IMPORTANT: HTTP Basic Auth sends credentials in base64 on every
    // request. Without HTTPS they are trivially recoverable. For
    // production, prefer web-server-level protection (e.g. Apache
    // .htaccess + .htpasswd). See the Guide page for instructions.
    'auth_user'          => '',
    'auth_password_hash' => '',

    // --- Purge ------------------------------------------------------------
    // Maximum number of rows in `registros` before the auto-purge fires.
    // When exceeded, completed months are aggregated into the
    // `resumenes_mensuales` table and removed from `registros`. The current
    // calendar month is always kept intact.
    // Set to null to disable auto-purge (you can still run it manually with
    // `php purge.php`).
    'purge_max_rows' => 100000,

    // Token required to trigger purge.php via HTTP (?token=...).
    // Leave empty to allow CLI execution only ("php purge.php"), which is
    // the safe default for an unauthenticated install.
    'purge_token'    => '',
];
