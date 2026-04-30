<?php
/**
 * mini-guardianweb — guide.php
 *
 * Static guide / manual page. Uses the same i18n dictionaries as
 * index.php; nothing is rendered from the database.
 */

declare(strict_types=1);

// Config is optional here (the guide works even before setup), but if it
// exists we read site_host so the header chip matches the dashboard.
$cfg_path = __DIR__ . '/config.php';
$config = is_file($cfg_path) ? (require $cfg_path) : [];
if (!is_array($config)) {
    $config = [];
}

// Optional HTTP Basic Auth gate. No-op if auth_user / auth_password_hash
// are empty in config.php, so the guide stays accessible during early
// setup before the user has configured credentials.
require_once __DIR__ . '/lib/auth.php';
mgw_require_auth($config);

$site_host = trim((string)($config['site_host'] ?? ''));

// Load both dictionaries.
$lang_dir = __DIR__ . '/lang';
$dictionaries = [];
foreach (['en', 'es'] as $lang_code) {
    $f = $lang_dir . '/' . $lang_code . '.json';
    if (is_file($f)) {
        $dictionaries[$lang_code] = json_decode((string)file_get_contents($f), true) ?: [];
    }
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// --- Build the ready-to-paste embed snippet -------------------------------
// We need the absolute URL of api.php as it would be reached from the
// outside world. We build it from the current request's host + the
// directory portion of SCRIPT_NAME. If the page was opened via CLI or
// without an HTTP host, $api_url stays empty and the section degrades to
// a friendly message.
$scheme       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$req_host     = (string)($_SERVER['HTTP_HOST'] ?? '');
$api_url      = '';
if ($req_host !== '') {
    $base    = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    $api_url = $scheme . '://' . $req_host . $base . '/api.php';
}

// Snippet only renders cleanly when we know both ends: where api.php lives
// (so the visitor's browser can reach it) and which domain to declare in
// the h= parameter (so the single-site filter accepts the hit).
$embed_ready = ($api_url !== '' && $site_host !== '');
$snippet = '';
if ($embed_ready) {
    $snippet = '<img src="' . $api_url . '?h=' . $site_host
             . '" alt="" width="1" height="1" style="position:absolute">';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>mini-guardianweb · Guide</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">

<!-- Apply theme + language as early as possible to avoid flash. -->
<script>
(function () {
    var storedTheme = localStorage.getItem('mgw-theme');
    var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (storedTheme === 'dark' || (!storedTheme && prefersDark)) {
        document.documentElement.classList.add('dark');
    }
    var storedLang = localStorage.getItem('mgw-lang');
    var navLang    = (navigator.language || 'en').slice(0, 2).toLowerCase();
    var initial    = (storedLang === 'en' || storedLang === 'es') ? storedLang
                    : (navLang === 'es' ? 'es' : 'en');
    document.documentElement.setAttribute('lang', initial);
    window.__MGW_INITIAL_LANG = initial;
})();
</script>

<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { darkMode: 'class' };</script>

<style>
    /* Inline code styling (Tailwind CDN doesn't ship a typography plugin). */
    .mgw-prose code {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 0.875em;
        padding: 0.1em 0.4em;
        border-radius: 0.25rem;
        background: rgba(100, 116, 139, 0.15);
    }
    .dark .mgw-prose code { background: rgba(148, 163, 184, 0.15); }
    .mgw-prose strong { font-weight: 600; }
    .mgw-prose em     { font-style: italic; }
</style>
</head>
<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header -->
    <header class="flex flex-wrap items-center justify-between gap-4 mb-10">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">mini-guardianweb</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400" data-i18n="guide_subtitle">User guide and reference.</p>
            <?php if ($site_host !== ''): ?>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 inline-flex items-center gap-1.5">
                    <span data-i18n="tracking">Tracking</span>
                    <span class="font-mono px-1.5 py-0.5 rounded bg-slate-200/60 dark:bg-slate-800/60"><?= h($site_host) ?></span>
                </p>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <a href="index.php"
               class="px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-sm"
               data-i18n="nav_back_dashboard">← Dashboard</a>
            <button id="lang-toggle" type="button"
                    class="px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-semibold tracking-wide"
                    data-i18n-attr="aria-label:toggle_lang">
                <span data-lang-label>EN</span>
            </button>
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

    <article class="mgw-prose space-y-10 leading-relaxed">

        <!-- Intro -->
        <section>
            <h2 class="text-xl font-semibold mb-3" data-i18n="guide_intro_title">What is mini-guardianweb?</h2>
            <p class="text-slate-700 dark:text-slate-300" data-i18n="guide_intro_body">A minimalist single-site visit counter…</p>
        </section>

        <!-- Installation -->
        <section>
            <h2 class="text-xl font-semibold mb-3" data-i18n="guide_install_title">Installation</h2>
            <ol class="list-decimal list-outside ml-5 space-y-2 text-slate-700 dark:text-slate-300">
                <li data-i18n-html="guide_install_step1">Copy <code>config.example.php</code>…</li>
                <li data-i18n-html="guide_install_step2">Include <code>shield.php</code>…</li>
                <li data-i18n-html="guide_install_step3">For non-PHP sites…</li>
                <li data-i18n-html="guide_install_step4">Open <code>index.php</code>…</li>
            </ol>
        </section>

        <!-- Embed snippet for non-PHP sites -->
        <section>
            <h2 class="text-xl font-semibold mb-3" data-i18n="guide_embed_title">Embed code for non-PHP sites</h2>
            <p class="text-slate-700 dark:text-slate-300 mb-4" data-i18n-html="guide_embed_intro">For static HTML sites…</p>

            <?php if (!$embed_ready): ?>
                <div class="rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-100/60 dark:bg-slate-900 p-4 text-sm text-slate-600 dark:text-slate-400">
                    <?php if ($site_host === ''): ?>
                        <span data-i18n-html="guide_embed_no_site_host">Set <code>site_host</code> in <code>config.php</code> first…</span>
                    <?php else: ?>
                        <span data-i18n="guide_embed_cli">Open this page over HTTP to see the snippet…</span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="relative rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900 p-4 pr-24">
                    <pre class="text-xs leading-relaxed text-slate-800 dark:text-slate-200 whitespace-pre-wrap break-all m-0"><code id="mgw-snippet"><?= h($snippet) ?></code></pre>
                    <button id="snippet-copy" type="button"
                            class="absolute top-3 right-3 px-3 py-1 rounded-md bg-slate-900 text-white dark:bg-slate-200 dark:text-slate-900 text-xs font-semibold hover:opacity-90 transition-opacity"
                            data-i18n="guide_embed_copy">Copy</button>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2" data-i18n-html="guide_embed_path_note">
                    The page path is auto-detected from the <code>Referer</code>. Append <code>&amp;p=/your-path</code> to set it explicitly.
                </p>
            <?php endif; ?>
        </section>

        <!-- Dashboard -->
        <section>
            <h2 class="text-xl font-semibold mb-3" data-i18n="guide_dashboard_title">What the dashboard shows</h2>
            <p class="text-slate-700 dark:text-slate-300 mb-4" data-i18n-html="guide_dashboard_intro">Everything is computed on demand…</p>

            <h3 class="font-semibold mt-4 mb-1" data-i18n="guide_dashboard_cards_title">Stat cards (top row)</h3>
            <p class="text-slate-700 dark:text-slate-300" data-i18n-html="guide_dashboard_cards_body">Hits today…</p>

            <h3 class="font-semibold mt-4 mb-1" data-i18n="guide_dashboard_charts_title">Charts</h3>
            <p class="text-slate-700 dark:text-slate-300" data-i18n-html="guide_dashboard_charts_body">Hits per hour…</p>

            <h3 class="font-semibold mt-4 mb-1" data-i18n="guide_dashboard_tables_title">Tables</h3>
            <p class="text-slate-700 dark:text-slate-300" data-i18n-html="guide_dashboard_tables_body">Top AI crawlers…</p>
        </section>

        <!-- Caveat (highlighted) -->
        <section class="rounded-xl border border-amber-300 dark:border-amber-700/60 bg-amber-50 dark:bg-amber-950/30 p-6">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 flex-shrink-0 text-amber-600 dark:text-amber-400 mt-0.5"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01M5.072 19h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div class="flex-1">
                    <h2 class="text-xl font-semibold mb-2 text-amber-900 dark:text-amber-100"
                        data-i18n="guide_caveat_title">Important · Bot detection is heuristic</h2>
                    <p class="text-amber-900/90 dark:text-amber-100/90"
                       data-i18n-html="guide_caveat_body">Classification by User-Agent…</p>
                </div>
            </div>
        </section>

        <!-- Privacy -->
        <section>
            <h2 class="text-xl font-semibold mb-3" data-i18n="guide_privacy_title">Privacy and data retention</h2>
            <p class="text-slate-700 dark:text-slate-300" data-i18n-html="guide_privacy_body">Visitor IPs are stored…</p>
        </section>

        <!-- Security -->
        <section>
            <h2 class="text-xl font-semibold mb-3" data-i18n="guide_security_title">Securing the dashboard</h2>
            <p class="text-slate-700 dark:text-slate-300 mb-4" data-i18n-html="guide_security_intro">By default the dashboard is public…</p>

            <h3 class="font-semibold mt-4 mb-2" data-i18n="guide_security_inapp_title">1. Built-in HTTP Basic Auth (quick)</h3>
            <p class="text-slate-700 dark:text-slate-300 mb-3" data-i18n-html="guide_security_inapp_body">Fill <code>auth_user</code> and <code>auth_password_hash</code>…</p>

            <pre class="text-xs leading-relaxed bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg p-3 overflow-x-auto m-0"><code>php -r "echo password_hash('your-password', PASSWORD_DEFAULT), \"\n\";"</code></pre>

            <div class="mt-4 rounded-lg border border-amber-300 dark:border-amber-700/60 bg-amber-50 dark:bg-amber-950/30 p-4">
                <div class="flex items-start gap-2">
                    <svg class="w-5 h-5 flex-shrink-0 text-amber-600 dark:text-amber-400 mt-0.5"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 9v2m0 4h.01M5.072 19h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <p class="text-sm text-amber-900 dark:text-amber-100"
                       data-i18n-html="guide_security_https_warning"><strong>HTTPS is mandatory.</strong>…</p>
                </div>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400 mt-3"
               data-i18n="guide_security_logout_note">There is no logout button…</p>

            <h3 class="font-semibold mt-6 mb-2" data-i18n="guide_security_server_title">2. Web-server protection (recommended for production)</h3>
            <p class="text-slate-700 dark:text-slate-300 mb-3" data-i18n-html="guide_security_server_body">More robust because the gate runs before any PHP code…</p>

            <pre class="text-xs leading-relaxed bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg p-3 overflow-x-auto m-0"><code>AuthType Basic
AuthName "mini-guardianweb"
AuthUserFile /absolute/path/to/.htpasswd
Require valid-user</code></pre>

            <p class="text-xs text-slate-500 dark:text-slate-400 mt-2"
               data-i18n-html="guide_security_htpasswd_hint">Create the <code>.htpasswd</code> file…</p>
        </section>

        <!-- About / promo -->
        <section class="rounded-xl border border-violet-300 dark:border-violet-700/60 bg-gradient-to-br from-violet-50 to-fuchsia-50 dark:from-violet-950/40 dark:to-fuchsia-950/30 p-6">
            <h2 class="text-xl font-semibold mb-2 text-violet-900 dark:text-violet-100"
                data-i18n="guide_about_title">About this app</h2>
            <p class="text-violet-900/90 dark:text-violet-100/90 mb-4"
               data-i18n-html="guide_about_body">This mini version is offered by guardianweb.es…</p>
            <a href="https://guardianweb.es" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-violet-600 hover:bg-violet-700 text-white text-sm font-semibold transition-colors"
               data-i18n="guide_about_cta">Register free at guardianweb.es →</a>
        </section>

    </article>

    <footer class="mt-12 text-center text-xs text-slate-400 dark:text-slate-600">
        mini-guardianweb · MIT ·
        <a href="https://github.com/sergiogongil/Guardian-Web" class="underline hover:text-slate-600 dark:hover:text-slate-400" data-i18n="footer_source">source</a>
        · <span data-i18n="footer_inspired">inspired by</span>
        <a href="https://guardianweb.es" target="_blank" rel="noopener" class="underline hover:text-slate-600 dark:hover:text-slate-400">guardianweb.es</a>
    </footer>
</div>

<script>
const MGW_I18N = <?= json_encode($dictionaries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let MGW_LANG = window.__MGW_INITIAL_LANG || 'en';

function applyI18n() {
    const dict = MGW_I18N[MGW_LANG] || MGW_I18N.en || {};
    document.documentElement.setAttribute('lang', MGW_LANG);

    document.querySelectorAll('[data-i18n]').forEach(function (el) {
        const key = el.getAttribute('data-i18n');
        if (dict[key] !== undefined) el.textContent = dict[key];
    });

    // data-i18n-html: trusted dev-authored values from the JSON files.
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
});

document.getElementById('theme-toggle').addEventListener('click', function () {
    const html = document.documentElement;
    const wasDark = html.classList.contains('dark');
    html.classList.toggle('dark', !wasDark);
    localStorage.setItem('mgw-theme', wasDark ? 'light' : 'dark');
});

// --- Copy-to-clipboard for the embed snippet --------------------------
(function () {
    const btn  = document.getElementById('snippet-copy');
    const code = document.getElementById('mgw-snippet');
    if (!btn || !code) return;   // section not rendered

    btn.addEventListener('click', async function () {
        const text = code.textContent;
        let ok = false;
        try {
            await navigator.clipboard.writeText(text);
            ok = true;
        } catch (e) {
            // Fallback for old browsers / non-secure contexts: select the
            // text so the user can hit Ctrl+C themselves.
            const range = document.createRange();
            range.selectNodeContents(code);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }

        if (ok) {
            const dict = MGW_I18N[MGW_LANG] || MGW_I18N.en || {};
            btn.textContent = dict.guide_embed_copied || 'Copied!';
            setTimeout(function () {
                // Re-apply i18n so the button restores in the right language,
                // even if the user toggled language during the timeout.
                applyI18n();
            }, 1500);
        }
    });
})();

applyI18n();
</script>
</body>
</html>
