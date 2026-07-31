<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    // Enumerated 2026-07-31 (Checkpoint `CorsConfigCheck`, hash 18dbdb6d60a4). Derived
    // from `php artisan route:list --json`, not guessed — the router serves exactly six
    // verbs: GET 219, HEAD 219, POST 177, DELETE 97, PATCH 38, PUT 17. OPTIONS is added
    // for the preflight itself.
    //
    // This list is therefore a SUPERSET of everything the app can answer: replacing '*'
    // drops only verbs that have no route at all (TRACE, CONNECT, …), so no legitimate
    // caller loses a method. Adding a route with a new verb means adding it here —
    // otherwise the preflight succeeds and the browser blocks the real request.
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    // Exact-origin allowlist for browser callers. Driven by PARTNA_FRONTEND_ORIGINS
    // (see config/partna.php). First-party frontends — partna.au, www.partna.au,
    // app.partna.au, and dev/local equivalents — go here.
    //
    // History: this was previously `['*']` with a comment justifying wildcard via
    // `supports_credentials => false`. That tolerated unbounded callers (scanners,
    // random sites). Replaced by an explicit allowlist + targeted regex patterns
    // below for the legitimate wildcard cases (#P2-40 / #P3-11).
    // SEC-4: parse PARTNA_FRONTEND_ORIGINS DIRECTLY here, not via
    // config('partna.frontend_origins'). Config files load alphabetically — cors.php
    // is required before partna.php, so reading partna.* at require-time resolves to the
    // empty default and silently zeroes the allowlist for the whole request. env() is
    // available at config-load/cache time, so direct parsing is order-independent.
    // (Mirrors the parse in config/partna.php's 'frontend_origins'.)
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PARTNA_FRONTEND_ORIGINS', ''))
    ))),
    // Regex patterns for hostnames where enumerating every entry is impossible:
    //   - partna.au + *.partna.au: the apex marketing site AND visitor mini-sites
    //     (handle subdomains). The optional `([a-z0-9-]+\.)?` label covers both; the
    //     character class excludes `.` so `evil.partna.au.attacker.com` cannot match
    //     (SEC-2: the prior pattern required a subdomain label and silently denied the apex).
    // All patterns require https. Subject to Laravel CORS package's `preg_match` —
    // anchors and delimiters are mandatory.
    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)?partna\.au$#i',
    ],
    // Wildcard request headers remain safe: supports_credentials => false means the
    // browser's wildcard+credentials restriction does not apply. If supports_credentials
    // is ever set to true, this MUST be locked to an explicit list.
    'allowed_headers' => ['*'],
    // Let the browser read rate-limit hints — the dashboard renders countdown
    // copy ("Try again in 4:32") from Retry-After on 429 responses.
    'exposed_headers' => ['Retry-After'],
    // Cache CORS preflight responses for 24h. Browsers floor this to their own
    // caps (Chromium 2h, Safari 10min, Firefox 24h). Without this, every fetch
    // pays a fresh OPTIONS round-trip — observed as ~140 redundant preflights
    // per dashboard page load.
    'max_age' => 86400,
    'supports_credentials' => false,
];
