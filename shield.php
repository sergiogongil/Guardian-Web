<?php
/**
 * mini-guardianweb — shield.php
 *
 * Drop-in tracker. Add this line at the top of any page you want to track:
 *
 *     require __DIR__ . '/path/to/mini-guardianweb/shield.php';
 *
 * It records one row in the `registros` table per page load. It is
 * deliberately defensive: any error is swallowed so the host page never
 * breaks because of the tracker.
 */

// Guard: never register the same hit twice if shield.php is required from
// multiple places in the same request.
if (defined('MGW_SHIELD_LOADED')) {
    return;
}
define('MGW_SHIELD_LOADED', true);

try {
    $cfg_path = __DIR__ . '/config.php';
    if (!is_file($cfg_path)) {
        // No config yet — fail silently. The user hasn't finished setup.
        return;
    }
    $config = require $cfg_path;
    if (!is_array($config)) {
        return;
    }

    require_once __DIR__ . '/lib/record.php';
    mgw_record($config);
} catch (\Throwable $e) {
    // Silent on purpose: the tracker must never break the host page.
    // For debugging, temporarily uncomment:
    // error_log('[mini-guardianweb] ' . $e->getMessage());
}
