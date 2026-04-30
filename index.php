<?php
/**
 * mini-guardianweb — index.php
 *
 * Single-page dashboard. Reads from `registros` (recent raw hits) and
 * `resumenes_mensuales` (archived monthly totals from past purges) and
 * renders stats, charts and top-N tables with Tailwind + Chart.js (both
 * via CDN, no build step).
 *
 * i18n: every user-facing string lives in lang/en.json and lang/es.json.
 * Both dictionaries are sent to the client; JS swaps text via data-i18n
 * attributes. The selected language is persisted in localStorage.
 */

declare(strict_types=1);

$cfg_path = __DIR__ . '/config.php';
if (!is_file($cfg_path)) {
    http_response_code(500);
    echo '<h1>mini-guardianweb</h1>';
    echo '<p>Missing <code>config.php</code>. Copy <code>config.example.php</code> to <code>config.php</code> and edit it.</p>';
    echo '<p>Falta <code>config.php</code>. Copia <code>config.example.php</code> a <code>config.php</code> y edítalo.</p>';
    exit;
}
$config = require $cfg_path;

// Optional HTTP Basic Auth gate. No-op if auth_user / auth_password_hash
// are empty in config.php.
require_once __DIR__ . '/lib/auth.php';
mgw_require_auth($config);

require_once __DIR__ . '/lib/db.php';
$pdo = mgw_db($config);

// The tracked domain comes from config — single-site by design.
// Defined early because several queries below reference it.
$site_host = trim((string)($config['site_host'] ?? ''));

// --- i18n: load dictionaries ---------------------------------------------
$lang_dir = __DIR__ . '/lang';
$dictionaries = [];
foreach (['en', 'es'] as $lang_code) {
    $f = $lang_dir . '/' . $lang_code . '.json';
    if (is_file($f)) {
        $dictionaries[$lang_code] = json_decode((string)file_get_contents($f), true) ?: [];
    }
}

// --- Range filter ---------------------------------------------------------
// 'all' here means "every row currently in `registros`" — after a purge
// that's just the unpurged window (typically the current month). The
// "Monthly history" section at the bottom shows the archived totals.
$ranges = [
    '24h' => time() - 86400,
    '7d'  => time() - 7 * 86400,
    '30d' => time() - 30 * 86400,
    'all' => 0,
];
$range_key = $_GET['range'] ?? '7d';
if (!isset($ranges[$range_key])) {
    $range_key = '7d';
}
$since = $ranges[$range_key];

// --- Helpers --------------------------------------------------------------
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function mgw_count(PDO $pdo, int $since): int
{
    $st = $pdo->prepare('SELECT COUNT(*) AS n FROM registros WHERE ts >= :s');
    $st->execute([':s' => $since]);
    return (int)$st->fetch()['n'];
}

function mgw_unique(PDO $pdo, int $since): int
{
    $st = $pdo->prepare('SELECT COUNT(DISTINCT ip_hash) AS n FROM registros WHERE ts >= :s');
    $st->execute([':s' => $since]);
    return (int)$st->fetch()['n'];
}

function mgw_kind_chip(string $kind): string
{
    return match ($kind) {
        'human'        => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200',
        'bot_official' => 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200',
        'bot_ai'       => 'bg-violet-100 dark:bg-violet-900/40 text-violet-800 dark:text-violet-200',
        'bot_other'    => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200',
        default        => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    };
}

// --- Fixed stat cards (independent of range filter) -----------------------
$today_start = strtotime('today') ?: 0;
$stats = [
    'today'    => mgw_count($pdo, $today_start),
    '7d'       => mgw_count($pdo, time() - 7 * 86400),
    '30d'      => mgw_count($pdo, time() - 30 * 86400),
    'unique7d' => mgw_unique($pdo, time() - 7 * 86400),
];

// --- Kind distribution (donut) -------------------------------------------
// Counts DISTINCT visitors (ip_hash) per kind, not raw hits. A bot that
// hits 1000 times with the same IP contributes 1 to bot_other, not 1000.
// Otherwise heavy crawlers would dominate the "Visitor mix" donut and
// drown out real human visitors.
$st = $pdo->prepare("
    SELECT kind, COUNT(DISTINCT ip_hash) AS n
    FROM registros
    WHERE ts >= :s
    GROUP BY kind
");
$st->execute([':s' => $since]);
$kind_counts = [
    'human'        => 0,
    'bot_official' => 0,
    'bot_ai'       => 0,
    'bot_other'    => 0,
];
foreach ($st->fetchAll() as $row) {
    $kind_counts[$row['kind']] = (int)$row['n'];
}

// --- Hourly line chart (last 24h, by kind) -------------------------------
$line_since = time() - 86400;
$st = $pdo->prepare("
    SELECT (ts / 3600) * 3600 AS hour, kind, COUNT(*) AS n
    FROM registros
    WHERE ts >= :s
    GROUP BY hour, kind
    ORDER BY hour
");
$st->execute([':s' => $line_since]);
$by_hour = [];
foreach ($st->fetchAll() as $row) {
    $by_hour[(int)$row['hour']][$row['kind']] = (int)$row['n'];
}

// Build 24 hour-buckets ending at current hour, even if empty.
$current_hour = (int)(time() / 3600) * 3600;
$labels = [];
$series = ['human' => [], 'bot_official' => [], 'bot_ai' => [], 'bot_other' => []];
for ($i = 23; $i >= 0; $i--) {
    $h_ts    = $current_hour - $i * 3600;
    $labels[] = date('H:i', $h_ts);
    foreach (array_keys($series) as $k) {
        $series[$k][] = $by_hour[$h_ts][$k] ?? 0;
    }
}

// --- Top AI bots ----------------------------------------------------------
$st = $pdo->prepare("
    SELECT bot_name, COUNT(*) AS n
    FROM registros
    WHERE kind = 'bot_ai' AND ts >= :s AND bot_name IS NOT NULL
    GROUP BY bot_name
    ORDER BY n DESC
    LIMIT 10
");
$st->execute([':s' => $since]);
$top_ai = $st->fetchAll();

// --- Top pages (humans only) ---------------------------------------------
// Normalises the path before grouping, so:
//   - the query string is stripped (so "/?utm=a" and "/?utm=b" merge as "/")
//   - any trailing slash is removed (except for the root "/"), so
//     "/about" and "/about/" merge as "/about".
$st = $pdo->prepare("
    WITH norm AS (
        SELECT
            CASE
                WHEN instr(path, '?') > 0 THEN substr(path, 1, instr(path, '?') - 1)
                ELSE path
            END AS raw_path
        FROM registros
        WHERE kind = 'human' AND ts >= :s
    )
    SELECT
        COALESCE(NULLIF(rtrim(raw_path, '/'), ''), '/') AS path,
        COUNT(*) AS n
    FROM norm
    GROUP BY path
    ORDER BY n DESC
    LIMIT 10
");
$st->execute([':s' => $since]);
$top_pages = $st->fetchAll();

// --- Top referrers (host only, external) ---------------------------------
// Two corrections versus the naive approach:
//   1. Self-referrals are EXCLUDED. Internal navigation generates a Referer
//      pointing at the site's own host; without filtering, that domain
//      always tops the list and drowns out real external sources.
//   2. Visits with no Referer (typed URL, bookmark, mobile app, strict
//      Referrer-Policy) are aggregated into a synthetic "(direct)" bucket
//      so they remain visible.

// The site's own host, normalised the same way as in lib/record.php so
// "proxxi.es" and "www.proxxi.es" both count as self-referrals.
$site_host_norm = $site_host !== ''
    ? preg_replace('/^www\./i', '', strtolower($site_host))
    : '';

$st = $pdo->prepare("
    SELECT referrer, COUNT(*) AS n
    FROM registros
    WHERE referrer IS NOT NULL AND referrer <> '' AND ts >= :s
    GROUP BY referrer
    ORDER BY n DESC
    LIMIT 200
");
$st->execute([':s' => $since]);
$ref_by_host = [];
foreach ($st->fetchAll() as $r) {
    $host = parse_url($r['referrer'], PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        continue; // unparseable Referer
    }
    $host_norm = preg_replace('/^www\./i', '', strtolower($host));
    if ($site_host_norm !== '' && $host_norm === $site_host_norm) {
        continue; // self-referral — skip
    }
    if (!isset($ref_by_host[$host])) {
        $ref_by_host[$host] = 0;
    }
    $ref_by_host[$host] += (int)$r['n'];
}

// Direct visits: hits with no Referer at all.
$st = $pdo->prepare("
    SELECT COUNT(*) AS n
    FROM registros
    WHERE (referrer IS NULL OR referrer = '') AND ts >= :s
");
$st->execute([':s' => $since]);
$direct_count = (int)$st->fetch()['n'];

// Use a sentinel key for "(direct)" so the HTML can render it specially.
if ($direct_count > 0) {
    $ref_by_host['__direct__'] = $direct_count;
}
arsort($ref_by_host);
$top_referrers = array_slice($ref_by_host, 0, 10, true);

// --- Top user-agents (with classifier verdict) ---------------------------
// Audit aid: shows raw UA strings together with how the classifier labelled
// them. Lets the operator spot misclassifications and judge whether what
// counts as "human" actually looks like a real browser.
$st = $pdo->prepare("
    SELECT user_agent, kind, bot_name,
           COUNT(*)              AS hits,
           COUNT(DISTINCT ip_hash) AS uniq
    FROM registros
    WHERE ts >= :s
    GROUP BY user_agent
    ORDER BY hits DESC
    LIMIT 15
");
$st->execute([':s' => $since]);
$top_uas = $st->fetchAll();

// --- Monthly history (from `resumenes_mensuales`, populated by the purge) -
// Only metrics that survive aggregation are reconstructed: total hits per
// month per kind, and lifetime AI-bot totals. Page paths and referrers are
// not preserved at this granularity — that's documented in the UI text.
$st = $pdo->query("
    SELECT year_month, kind, SUM(hits) AS hits
    FROM resumenes_mensuales
    GROUP BY year_month, kind
    ORDER BY year_month
");
$by_month = [];
foreach ($st->fetchAll() as $row) {
    $by_month[$row['year_month']][$row['kind']] = (int)$row['hits'];
}
$history_months = array_keys($by_month);
$history_series = ['human' => [], 'bot_official' => [], 'bot_ai' => [], 'bot_other' => []];
foreach ($history_months as $ym) {
    foreach (array_keys($history_series) as $k) {
        $history_series[$k][] = $by_month[$ym][$k] ?? 0;
    }
}

$st = $pdo->query("
    SELECT bot_name, SUM(hits) AS total_hits
    FROM resumenes_mensuales
    WHERE kind = 'bot_ai' AND bot_name <> ''
    GROUP BY bot_name
    ORDER BY total_hits DESC
    LIMIT 10
");
$top_ai_history = $st->fetchAll();

$has_history = !empty($history_months);

// Palette shared with JS. Kind labels now come from the dictionary.
$palette = [
    'human'        => '#10b981',
    'bot_official' => '#3b82f6',
    'bot_ai'       => '#a855f7',
    'bot_other'    => '#f59e0b',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>mini-guardianweb</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">

<!-- Apply theme + language as early as possible to avoid flash. -->
<script>
(function () {
    // Theme
    var storedTheme = localStorage.getItem('mgw-theme');
    var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (storedTheme === 'dark' || (!storedTheme && prefersDark)) {
        document.documentElement.classList.add('dark');
    }
    // Language: stored > navigator > 'en'
    var storedLang = localStorage.getItem('mgw-lang');
    var navLang = (navigator.language || 'en').slice(0, 2).toLowerCase();
    var initial = (storedLang === 'en' || storedLang === 'es') ? storedLang
                : (navLang === 'es' ? 'es' : 'en');
    document.documentElement.setAttribute('lang', initial);
    window.__MGW_INITIAL_LANG = initial;
})();
</script>

<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { darkMode: 'class' };</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header -->
    <header class="flex flex-wrap items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">mini-guardianweb</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400" data-i18n="subtitle">Visit counter with bot &amp; AI-crawler awareness.</p>
            <?php if ($site_host !== ''): ?>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span data-i18n="tracking">Tracking</span>
                    <span class="font-mono px-1.5 py-0.5 rounded bg-slate-200/60 dark:bg-slate-800/60"><?= h($site_host) ?></span>
                </p>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <!-- Range filter -->
            <nav class="flex rounded-lg overflow-hidden border border-slate-300 dark:border-slate-700 text-sm">
                <?php foreach ($ranges as $key => $_since): ?>
                    <a href="?range=<?= h($key) ?>"
                       data-i18n="range_<?= h($key) ?>"
                       class="px-3 py-1.5 <?= $key === $range_key
                           ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
                           : 'bg-white text-slate-700 hover:bg-slate-100 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800' ?>">
                        <?= h($key) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <!-- Guide link -->
            <a href="guide.php"
               class="px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-semibold tracking-wide"
               data-i18n="nav_guide">Guide</a>
            <!-- Language toggle -->
            <button id="lang-toggle" type="button"
                    class="px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-semibold tracking-wide"
                    data-i18n-attr="aria-label:toggle_lang">
                <span data-lang-label>EN</span>
            </button>
            <!-- Dark mode toggle -->
            <button id="theme-toggle" type="button"
                    class="p-2 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800"
                    data-i18n-attr="aria-label:toggle_dark">
                <svg class="w-5 h-5 hidden dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                <svg class="w-5 h-5 block dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                </svg>
            </button>
        </div>
    </header>

    <!-- Stat cards (fixed windows, independent of range filter) -->
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <?php
        // Each card: [title_key, value, hint_key]
        $cards = [
            ['card_today',    $stats['today'],    'card_today_hint'],
            ['card_7d',       $stats['7d'],       'card_7d_hint'],
            ['card_30d',      $stats['30d'],      'card_30d_hint'],
            ['card_unique7d', $stats['unique7d'], 'card_unique7d_hint'],
        ];
        foreach ($cards as $c): ?>
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400" data-i18n="<?= h($c[0]) ?>"><?= h($c[0]) ?></div>
                <div class="mt-1 text-3xl font-semibold tabular-nums"><?= number_format($c[1]) ?></div>
                <div class="text-xs text-slate-400 dark:text-slate-500 mt-1" data-i18n="<?= h($c[2]) ?>"><?= h($c[2]) ?></div>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- Charts row -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">
        <div class="lg:col-span-2 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold" data-i18n="chart_hourly">Hits per hour — last 24h</h2>
                <span class="text-xs text-slate-500 dark:text-slate-400" data-i18n="chart_hourly_sub">stacked by visitor type</span>
            </div>
            <div class="relative h-72">
                <canvas id="chart-hourly"></canvas>
            </div>
        </div>
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold" data-i18n="chart_mix">Visitor mix</h2>
                <span class="text-xs text-slate-500 dark:text-slate-400" data-i18n="range_<?= h($range_key) ?>"><?= h($range_key) ?></span>
            </div>
            <div class="relative h-72">
                <canvas id="chart-donut"></canvas>
            </div>
        </div>
    </section>

    <!-- Tables -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Top AI bots -->
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
            <h2 class="font-semibold mb-3" data-i18n="top_ai">Top AI crawlers</h2>
            <?php if (!$top_ai): ?>
                <p class="text-sm text-slate-500 dark:text-slate-400" data-i18n="top_ai_empty">No AI crawlers seen in this range.</p>
            <?php else: ?>
                <table class="w-full text-sm">
                    <tbody>
                    <?php foreach ($top_ai as $row): ?>
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-1.5 truncate"><?= h($row['bot_name']) ?></td>
                            <td class="py-1.5 text-right tabular-nums text-slate-500 dark:text-slate-400"><?= number_format((int)$row['n']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Top pages -->
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
            <h2 class="font-semibold mb-3">
                <span data-i18n="top_pages">Top pages</span>
                <span class="text-xs font-normal text-slate-500 dark:text-slate-400" data-i18n="top_pages_humans">(humans)</span>
            </h2>
            <?php if (!$top_pages): ?>
                <p class="text-sm text-slate-500 dark:text-slate-400" data-i18n="top_pages_empty">No human visits yet.</p>
            <?php else: ?>
                <table class="w-full text-sm">
                    <tbody>
                    <?php foreach ($top_pages as $row): ?>
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="py-1.5 max-w-0 truncate" title="<?= h($row['path']) ?>"><?= h($row['path']) ?></td>
                            <td class="py-1.5 text-right tabular-nums text-slate-500 dark:text-slate-400 pl-2"><?= number_format((int)$row['n']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Top referrers -->
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
            <h2 class="font-semibold mb-3" data-i18n="top_refs">Top referrers</h2>
            <?php if (!$top_referrers): ?>
                <p class="text-sm text-slate-500 dark:text-slate-400" data-i18n="top_refs_empty">No referrers yet.</p>
            <?php else: ?>
                <table class="w-full text-sm">
                    <tbody>
                    <?php foreach ($top_referrers as $host_name => $n): ?>
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <?php if ($host_name === '__direct__'): ?>
                                <td class="py-1.5 italic text-slate-500 dark:text-slate-400" data-i18n="top_refs_direct">(direct)</td>
                            <?php else: ?>
                                <td class="py-1.5 max-w-0 truncate" title="<?= h($host_name) ?>"><?= h($host_name) ?></td>
                            <?php endif; ?>
                            <td class="py-1.5 text-right tabular-nums text-slate-500 dark:text-slate-400 pl-2"><?= number_format((int)$n) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <!-- Top user-agents — transparency / audit row -->
    <section class="mt-8">
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
            <header class="mb-4">
                <h2 class="font-semibold" data-i18n="top_uas_title">User-agents seen</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1" data-i18n="top_uas_subtitle">What's actually hitting your site, with the classifier's verdict for each.</p>
            </header>
            <?php if (!$top_uas): ?>
                <p class="text-sm text-slate-500 dark:text-slate-400" data-i18n="top_uas_empty">No user-agents recorded yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <tr class="border-b border-slate-200 dark:border-slate-800">
                            <th class="text-left py-2 font-normal" data-i18n="top_uas_col_ua">User-agent</th>
                            <th class="text-left py-2 font-normal" data-i18n="top_uas_col_kind">Kind</th>
                            <th class="text-left py-2 font-normal" data-i18n="top_uas_col_bot">Detected as</th>
                            <th class="text-right py-2 font-normal" data-i18n="top_uas_col_hits">Hits</th>
                            <th class="text-right py-2 font-normal" data-i18n="top_uas_col_uniq">Unique IPs</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($top_uas as $row): ?>
                        <tr class="border-b border-slate-100 dark:border-slate-800 align-top">
                            <td class="py-2 max-w-md font-mono text-xs break-all" title="<?= h($row['user_agent'] ?? '') ?>"><?= h(($row['user_agent'] ?? '') !== '' ? $row['user_agent'] : '(empty)') ?></td>
                            <td class="py-2 whitespace-nowrap">
                                <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= mgw_kind_chip($row['kind']) ?>"
                                      data-i18n="kind_<?= h($row['kind']) ?>"><?= h($row['kind']) ?></span>
                            </td>
                            <td class="py-2 text-xs text-slate-600 dark:text-slate-400 whitespace-nowrap"><?= h($row['bot_name'] ?: '—') ?></td>
                            <td class="py-2 text-right tabular-nums"><?= number_format((int)$row['hits']) ?></td>
                            <td class="py-2 text-right tabular-nums text-slate-500 dark:text-slate-400"><?= number_format((int)$row['uniq']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($has_history): ?>
    <!-- Monthly history (from `resumenes_mensuales`, populated by purges) -->
    <section class="mt-12 pt-8 border-t border-slate-200 dark:border-slate-800">
        <header class="mb-6">
            <h2 class="text-xl font-semibold" data-i18n="history_title">Monthly history</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1" data-i18n="history_subtitle">Aggregated totals from purged months.</p>
        </header>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                <h3 class="font-semibold mb-3" data-i18n="history_chart_title">Hits per month</h3>
                <div class="relative h-72">
                    <canvas id="chart-history"></canvas>
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                <h3 class="font-semibold mb-3" data-i18n="history_top_ai_title">Top AI crawlers (all time)</h3>
                <?php if (!$top_ai_history): ?>
                    <p class="text-sm text-slate-500 dark:text-slate-400" data-i18n="history_top_ai_empty">No AI crawlers in the archive yet.</p>
                <?php else: ?>
                    <table class="w-full text-sm">
                        <tbody>
                        <?php foreach ($top_ai_history as $row): ?>
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="py-1.5 truncate"><?= h($row['bot_name']) ?></td>
                                <td class="py-1.5 text-right tabular-nums text-slate-500 dark:text-slate-400"><?= number_format((int)$row['total_hits']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <footer class="mt-10 text-center text-xs text-slate-400 dark:text-slate-600">
        mini-guardianweb · MIT ·
        <a href="https://github.com/sergiogongil/Guardian-Web" class="underline hover:text-slate-600 dark:hover:text-slate-400" data-i18n="footer_source">source</a>
        · <span data-i18n="footer_inspired">inspired by</span>
        <a href="https://guardianweb.es" target="_blank" rel="noopener" class="underline hover:text-slate-600 dark:hover:text-slate-400">guardianweb.es</a>
    </footer>
</div>

<script>
// --- Data injected from PHP --------------------------------------------
const MGW = {
    labels: <?= json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    series: <?= json_encode($series) ?>,
    kindCounts: <?= json_encode($kind_counts) ?>,
    palette: <?= json_encode($palette) ?>,
    historyMonths: <?= json_encode($history_months, JSON_UNESCAPED_UNICODE) ?>,
    historySeries: <?= json_encode($history_series) ?>
};

// Both dictionaries shipped together; switching is instant, no reload.
const MGW_I18N = <?= json_encode($dictionaries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

// Resolved by the early-render script in <head>.
let MGW_LANG = window.__MGW_INITIAL_LANG || 'en';

// --- i18n: apply current language to the DOM --------------------------
function applyI18n() {
    const dict = MGW_I18N[MGW_LANG] || MGW_I18N.en || {};

    document.documentElement.setAttribute('lang', MGW_LANG);

    document.querySelectorAll('[data-i18n]').forEach(function (el) {
        const key = el.getAttribute('data-i18n');
        if (dict[key] !== undefined) el.textContent = dict[key];
    });

    document.querySelectorAll('[data-i18n-html]').forEach(function (el) {
        const key = el.getAttribute('data-i18n-html');
        if (dict[key] !== undefined) el.innerHTML = dict[key];
    });

    document.querySelectorAll('[data-i18n-attr]').forEach(function (el) {
        el.getAttribute('data-i18n-attr').split(',').forEach(function (pair) {
            const parts = pair.split(':');
            if (parts.length !== 2) return;
            const attr = parts[0].trim(), key = parts[1].trim();
            if (dict[key] !== undefined) el.setAttribute(attr, dict[key]);
        });
    });

    const langLabel = document.querySelector('[data-lang-label]');
    if (langLabel) langLabel.textContent = MGW_LANG.toUpperCase();
}

document.getElementById('lang-toggle').addEventListener('click', function () {
    MGW_LANG = (MGW_LANG === 'en') ? 'es' : 'en';
    localStorage.setItem('mgw-lang', MGW_LANG);
    applyI18n();
    renderCharts();
});

document.getElementById('theme-toggle').addEventListener('click', function () {
    const html = document.documentElement;
    const wasDark = html.classList.contains('dark');
    html.classList.toggle('dark', !wasDark);
    localStorage.setItem('mgw-theme', wasDark ? 'light' : 'dark');
    renderCharts();
});

// --- Charts -----------------------------------------------------------
let hourlyChart  = null;
let donutChart   = null;
let historyChart = null;

function themeColors() {
    const dark = document.documentElement.classList.contains('dark');
    return {
        text: dark ? '#cbd5e1' : '#334155',
        grid: dark ? 'rgba(148,163,184,0.15)' : 'rgba(51,65,85,0.10)'
    };
}

function kindLabel(k) {
    const dict = MGW_I18N[MGW_LANG] || MGW_I18N.en || {};
    return dict['kind_' + k] || k;
}

function renderCharts() {
    if (hourlyChart)  hourlyChart.destroy();
    if (donutChart)   donutChart.destroy();
    if (historyChart) historyChart.destroy();

    const c = themeColors();
    Chart.defaults.color       = c.text;
    Chart.defaults.borderColor = c.grid;

    // Stacked bar by hour (last 24h).
    hourlyChart = new Chart(document.getElementById('chart-hourly'), {
        type: 'bar',
        data: {
            labels: MGW.labels,
            datasets: ['human', 'bot_official', 'bot_ai', 'bot_other'].map(function (k) {
                return {
                    label: kindLabel(k),
                    data: MGW.series[k],
                    backgroundColor: MGW.palette[k],
                    borderWidth: 0,
                    stack: 'all'
                };
            })
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true, grid: { color: c.grid } },
                y: { stacked: true, grid: { color: c.grid }, beginAtZero: true, ticks: { precision: 0 } }
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12 } }
            }
        }
    });

    // Donut by kind.
    const donutLabels = [], donutData = [], donutColors = [];
    ['human', 'bot_official', 'bot_ai', 'bot_other'].forEach(function (k) {
        donutLabels.push(kindLabel(k));
        donutData.push(MGW.kindCounts[k] || 0);
        donutColors.push(MGW.palette[k]);
    });
    donutChart = new Chart(document.getElementById('chart-donut'), {
        type: 'doughnut',
        data: {
            labels: donutLabels,
            datasets: [{ data: donutData, backgroundColor: donutColors, borderWidth: 0 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12 } }
            }
        }
    });

    // Monthly history chart (only if the section exists in the DOM, i.e.
    // there is archived data from past purges).
    const histCanvas = document.getElementById('chart-history');
    if (histCanvas && MGW.historyMonths.length > 0) {
        historyChart = new Chart(histCanvas, {
            type: 'bar',
            data: {
                labels: MGW.historyMonths,
                datasets: ['human', 'bot_official', 'bot_ai', 'bot_other'].map(function (k) {
                    return {
                        label: kindLabel(k),
                        data: MGW.historySeries[k],
                        backgroundColor: MGW.palette[k],
                        borderWidth: 0,
                        stack: 'all'
                    };
                })
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, grid: { color: c.grid } },
                    y: { stacked: true, grid: { color: c.grid }, beginAtZero: true, ticks: { precision: 0 } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12 } }
                }
            }
        });
    }
}

// Initial render order matters: translate the DOM first so charts pick
// up the right kind labels on first paint.
applyI18n();
renderCharts();
</script>
</body>
</html>
