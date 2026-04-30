<?php
/**
 * mini-guardianweb — user-agent classifier.
 *
 * Returns ['kind' => ..., 'bot_name' => ...] where:
 *   kind ∈ {'human', 'bot_official', 'bot_ai', 'bot_other'}
 *   bot_name is the friendly name when known, NULL otherwise.
 *
 * The lookup is ordered: AI crawlers first (most specific), then official
 * search/social bots, then automation frameworks, then named SEO crawlers,
 * then uptime monitors, then HTTP libraries, then suspicious UA shapes,
 * and finally the generic "bot/crawl/spider" catch-all.
 *
 * Important: this is a HEURISTIC, not a verification. The User-Agent
 * header is sent by the client and can be spoofed. A request that uses
 * a perfect Chrome UA is indistinguishable here from a real Chrome user.
 */

function mgw_classify(string $ua): array
{
    if ($ua === '') {
        // No UA at all — almost always automation.
        return ['kind' => 'bot_other', 'bot_name' => null];
    }

    // === AI / LLM crawlers ===============================================
    $ai_bots = [
        'GPTBot'             => 'GPTBot',
        'ChatGPT-User'       => 'ChatGPT-User',
        'OAI-SearchBot'      => 'OAI-SearchBot',
        'ClaudeBot'          => 'ClaudeBot',
        'Claude-Web'         => 'Claude-Web',
        'anthropic-ai'       => 'anthropic-ai',
        'PerplexityBot'      => 'PerplexityBot',
        'Perplexity-User'    => 'Perplexity-User',
        'Google-Extended'    => 'Google-Extended',
        'CCBot'              => 'CCBot',
        'Bytespider'         => 'Bytespider',
        'Amazonbot'          => 'Amazonbot',
        'cohere-ai'          => 'cohere-ai',
        'Meta-ExternalAgent' => 'Meta-ExternalAgent',
        'Applebot-Extended'  => 'Applebot-Extended',
        'DuckAssistBot'      => 'DuckAssistBot',
        'YouBot'             => 'YouBot',
        'Diffbot'            => 'Diffbot',
        'ImagesiftBot'       => 'ImagesiftBot',
    ];
    foreach ($ai_bots as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            return ['kind' => 'bot_ai', 'bot_name' => $name];
        }
    }

    // === Official search-engine and social crawlers ======================
    $official_bots = [
        'Googlebot'           => 'Googlebot',
        'Bingbot'             => 'Bingbot',
        'Slurp'               => 'Yahoo Slurp',
        'DuckDuckBot'         => 'DuckDuckBot',
        'Baiduspider'         => 'Baiduspider',
        'YandexBot'           => 'YandexBot',
        'Sogou'               => 'Sogou',
        'Exabot'              => 'Exabot',
        'facebookexternalhit' => 'facebookexternalhit',
        'Twitterbot'          => 'Twitterbot',
        'LinkedInBot'         => 'LinkedInBot',
        'Pinterestbot'        => 'Pinterestbot',
        'WhatsApp'            => 'WhatsApp',
        'TelegramBot'         => 'TelegramBot',
        'Applebot'            => 'Applebot',
    ];
    foreach ($official_bots as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            return ['kind' => 'bot_official', 'bot_name' => $name];
        }
    }

    // === Headless browsers and automation frameworks =====================
    // These drive a real browser engine. Most do NOT contain "bot" in the
    // UA, so without this section they would slip through to "human".
    $automation = [
        'HeadlessChrome' => 'HeadlessChrome',
        'PhantomJS'      => 'PhantomJS',
        'Selenium'       => 'Selenium',
        'Puppeteer'      => 'Puppeteer',
        'Playwright'     => 'Playwright',
        'Cypress'        => 'Cypress',
        'Electron'       => 'Electron',
    ];
    foreach ($automation as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            return ['kind' => 'bot_other', 'bot_name' => $name];
        }
    }

    // === Named SEO / analytics crawlers ==================================
    // These all contain "bot" so the generic regex would catch them, but
    // naming them gives more value on the dashboard.
    $seo_bots = [
        'SemrushBot'    => 'SemrushBot',
        'AhrefsBot'     => 'AhrefsBot',
        'MJ12bot'       => 'MJ12bot',
        'DotBot'        => 'DotBot',
        'BLEXBot'       => 'BLEXBot',
        'PetalBot'      => 'PetalBot',
        'DataForSeoBot' => 'DataForSeoBot',
        'SEOkicks'      => 'SEOkicks',
        'Mail.RU_Bot'   => 'Mail.RU_Bot',
    ];
    foreach ($seo_bots as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            return ['kind' => 'bot_other', 'bot_name' => $name];
        }
    }

    // === Uptime monitors and synthetic-traffic tools =====================
    $monitors = [
        'UptimeRobot'        => 'UptimeRobot',
        'Pingdom'            => 'Pingdom',
        'StatusCake'         => 'StatusCake',
        'Site24x7'           => 'Site24x7',
        'GTmetrix'           => 'GTmetrix',
        'Lighthouse'         => 'Lighthouse',
        'PageSpeed'          => 'PageSpeed',
        'DatadogSynthetics'  => 'DatadogSynthetics',
        'NewRelicSynthetics' => 'NewRelicSynthetics',
    ];
    foreach ($monitors as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            return ['kind' => 'bot_other', 'bot_name' => $name];
        }
    }

    // === HTTP libraries and CLI tools ====================================
    // Used by scrapers, scripts, mobile/server-side fetches. Almost never
    // come from an interactive browser session.
    $http_libs = [
        'curl/'             => 'curl',
        'Wget/'             => 'wget',
        'python-requests'   => 'python-requests',
        'aiohttp'           => 'aiohttp',
        'urllib'            => 'urllib',
        'okhttp'            => 'okhttp',
        'Java/'             => 'java',
        'Go-http-client'    => 'Go-http-client',
        'libwww-perl'       => 'libwww-perl',
        'axios/'            => 'axios',
        'node-fetch'        => 'node-fetch',
        'got '              => 'got',
        'Apache-HttpClient' => 'Apache-HttpClient',
        'GuzzleHttp'        => 'Guzzle',
        'Faraday'           => 'Faraday',
        'RestSharp'         => 'RestSharp',
        'Scrapy'            => 'Scrapy',
        'HTTPie'            => 'HTTPie',
        'PostmanRuntime'    => 'PostmanRuntime',
        'insomnia'          => 'Insomnia',
        'reqwest'           => 'reqwest',
    ];
    foreach ($http_libs as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            return ['kind' => 'bot_other', 'bot_name' => $name];
        }
    }

    // === Suspiciously old or minimal user agents =========================
    // Real browsers in this decade do not send these. Almost always
    // automation or scripts using a default UA.
    if (preg_match('~^Mozilla/[1-4]\.0\b|MSIE\s+[1-9]\.|Trident/[1-6]\.~i', $ua)) {
        return ['kind' => 'bot_other', 'bot_name' => 'old/minimal UA'];
    }

    // === Generic catch-all for self-declared bots ========================
    if (preg_match('~bot|crawl|spider|slurp|httpclient~i', $ua)) {
        return ['kind' => 'bot_other', 'bot_name' => null];
    }

    // Anything left is probably (but not certainly) a real browser.
    return ['kind' => 'human', 'bot_name' => null];
}
