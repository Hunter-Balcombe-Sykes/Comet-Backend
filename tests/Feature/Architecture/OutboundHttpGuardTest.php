<?php

// Architecture test — every outbound HTTP call in app/ must sit in a known-safe
// category. Spec: docs/superpowers/specs/2026-07-30-outbound-http-guard-design.md
//
// WHY: App\Services\Http\SafeUrlFetcher is the house SSRF guard (public-IP-only,
// revalidates every redirect hop) and is used in 20+ files, but nothing ENFORCED
// its use — every SafeUrlFetcher reference in tests/ is behavioural, and no gate
// failed on a raw Http::get($userSuppliedUrl). Commit 393466a9 is that gap in
// practice: the shop-brand logo fetch skipped the sanitizer and was only caught
// by a human reading the code.
//
// Rather than trace which URLs are attacker-controlled (taint analysis, which
// nothing in this stack does), this asserts the contrapositive: every fetch is
// ALREADY in a safe category, so no unsanitized path remains. Same move as the
// GS-1 cache guard — constrain the door instead of inspecting everyone entering.
//
// SCOPE: one sink. This says nothing about SQL, filesystem paths, or shell
// execution. Do not read a green run here as "dataflow analysis is covered".
//
// SCOPE (directories): base_path('app') only — tests/, scripts/ and the Worker are
// NOT scanned. Reviewed 2026-07-31 and left that way deliberately, because none of
// the three holds an SSRF surface:
//   - cloudflare-worker/ has exactly two outbound sites, and neither resolves an
//     attacker-supplied host: `fetch(request)` (src/index.js:331) passes the inbound
//     request through to its own origin, and `env.PARTNA_PAGES.fetch(originRequest)`
//     (:341, :418, :424) is a service binding, which performs no URL resolution at
//     all. Re-check with `grep -rn "fetch(" cloudflare-worker/src/`.
//   - scripts/ hits are launch-check shell/PHP diagnostics run by hand. No request
//     input reaches them.
//   - tests/ is not a runtime surface.
// Widening the scanner would therefore add maintenance and allowlist churn without
// closing a reachable path. If a NEW outbound call in scripts/ or the Worker ever
// takes a variable host, that changes — widen the scanner then, and do not treat
// this note as settling it.
//
// Comments are stripped with token_get_all() before matching, and that is
// load-bearing: ProcessShopBrandLogoJob.php:67 contains the comment "Never call
// Http::get() here." A regex scanner allowlists a file for mentioning the very
// pattern it forbids — exactly how the GS-1 allowlist acquired its bogus
// IndividualProfileController entry.

use Tests\Support\Architecture\OutboundHttpScanner;

/**
 * Writes $source into a throwaway app/ dir under the system temp dir and hands
 * that dir straight to the scanner under test — the fixture can only reach
 * alternativeTransports() by being scanned, so a reader moved elsewhere has
 * nothing to silently orphan. Always cleaned up, even on assertion failure.
 *
 * @return list<string>
 */
function outboundHttpScanFixture(string $source): array
{
    $root = sys_get_temp_dir().'/outbound-http-guard-fixture-'.bin2hex(random_bytes(8));
    $appDir = $root.'/app';
    mkdir($appDir, 0755, true);
    file_put_contents($appDir.'/Fixture.php', $source);

    try {
        return OutboundHttpScanner::alternativeTransports($appDir);
    } finally {
        unlink($appDir.'/Fixture.php');
        rmdir($appDir);
        rmdir($root);
    }
}

/**
 * Pattern A — ConstantEndpoint      host is a class const or config() value
 * Pattern B — SafeUrlFetcher        user-supplied URL routed through the sanitizer
 * Pattern C — HostAllowlist         untrusted URL, explicit host allowlist, no redirects
 * Pattern D — FixedHostVariablePath hardcoded host, variable path (MUST validate the path)
 *
 * Pattern B files do not appear below: they call $fetcher->fetch()/tryFetch()
 * rather than the Http facade, so they never enter this census. B is listed in
 * the failure message because it is the right answer for most NEW code.
 */
const OUTBOUND_HTTP_ALLOWLIST = [
    // ── Pattern A — ConstantEndpoint ────────────────────────────────────────
    'app/Http/Middleware/Auth/VerifySupabaseJwt.php' => ['A', '$jwksUrl built from config(supabase.*)'],
    'app/Ingest/Runtime/Effects/MenuActorDriver.php' => ['A', 'Apify actor endpoint; the actor id is checked against config(partna.menu.platforms) before use, the store URL is POST body, not target'],
    'app/Ingest/Runtime/Effects/MusicActorDriver.php' => ['A', 'Apify actor endpoint from config; the artist URL is POST body, not target'],
    'app/Services/Auth/SupabaseAdminService.php' => ['A', 'Supabase admin base from config'],
    'app/Services/BotProtection/Providers/HCaptchaProvider.php' => ['A', 'hcaptcha.com verify endpoint'],
    'app/Services/BotProtection/Providers/TurnstileProvider.php' => ['A', 'Cloudflare Turnstile verify endpoint'],
    'app/Services/Cloudflare/CloudflareCustomHostnameService.php' => ['A', 'Cloudflare API, base() from config'],
    'app/Services/Cloudflare/CloudflareKvService.php' => ['A', 'Cloudflare KV API from config'],
    'app/Services/Cloudflare/CloudflarePurgeService.php' => ['A', 'Cloudflare purge API from config'],
    'app/Services/Media/LogoProcessorClient.php' => ['A', 'internal logo-processor base from config'],
    'app/Services/Platforms/FreshaScraper.php' => ['A', 'self::GRAPHQL_URL'],
    'app/Services/Platforms/GoogleBusinessApifyScraper.php' => ['A', 'Apify actor endpoint'],
    'app/Services/Platforms/GoogleMenuImagesScraper.php' => ['A', 'Apify actor endpoint'],
    'app/Services/Platforms/InstagramScraper.php' => ['A', 'Apify actor endpoint'],
    'app/Services/Platforms/MenuAiExtractor.php' => ['A', 'Mistral + DeepSeek endpoints from config'],
    'app/Services/Platforms/YoutubeScraper.php' => ['A', 'YouTube Data API base; the handle is a query PARAM, never the target host'],
    'app/Services/Profile/BioIntelligence.php' => ['A', 'DeepSeek chat endpoint from config (same lane as MenuAiExtractor)'],
    'app/Services/Platforms/MenuApifyScraper.php' => ['A', 'actorUrl($platform); the scraped URL is POST body, not target'],
    'app/Services/Media/InstagramMediaUrl.php' => ['A', 'api.apify.com single-post scrape; the post URL is POST body, not target (the embed-page leg is pattern B via SafeUrlFetcher)'],
    'app/Services/Streaming/KickApiClient.php' => ['A', 'Kick API base'],
    'app/Services/Streaming/StreamingTokenManager.php' => ['A', 'OAuth token endpoints from config'],
    'app/Services/Streaming/TwitchApiClient.php' => ['A', 'Twitch Helix API base'],
    'app/Services/User/AccountDeletionService.php' => ['A', 'Supabase admin base from config'],

    // ── Pattern C — HostAllowlist ───────────────────────────────────────────
    // Scraped (untrusted) CDN urls, but constrained STRICTER than SafeUrlFetcher:
    // a two-host allowlist beats "any public IP". See ALLOWED_HOSTS + isAllowedHost().
    'app/Services/Platforms/InstagramConnectionSeeder.php' => ['C', 'ALLOWED_HOSTS + isAllowedHost() + withoutRedirecting() + drop >=300'],

    'app/Console/Commands/FleetVerifyCommand.php' => ['A', 'staff-only console command; https://<handle>.partna.au where handle is OUR handle_lc column (validated DNS label, HandleAllocator-shaped) — the apex is fixed, no user input reaches the URL'],

    // ── Pattern D — FixedHostVariablePath (Rule 3 applies) ──────────────────
    'app/Services/Platforms/YoutubeThumbnailResolver.php' => ['D', 'i.ytimg.com; $videoId validated by VIDEO_ID_PATTERN'],
    'app/Services/Platforms/GoogleBusinessService.php' => ['D', 'places.googleapis.com; $ref validated by PHOTO_REF_PATTERN'],
    'app/Services/Platforms/LinkInBioApiUnroller.php' => ['D', 'api-prod.linkin.bio + taplink.cc + api.stanwith.me + sprout.link, all class consts; $slug validated by NICKNAME_PATTERN'],
    'app/Services/Platforms/SquareMenuDriver.php' => ['D', 'API_BASE (cdn5.editmysite.com) class const; the two numeric ids validated by API_ID_PATTERN (the store PAGE fetch is pattern B via SafeUrlFetcher)'],
];

/** Pattern D files must constrain their interpolated segment before building a URL. */
const OUTBOUND_HTTP_PATTERN_D_VALIDATORS = [
    'app/Services/Platforms/YoutubeThumbnailResolver.php' => 'VIDEO_ID_PATTERN',
    'app/Services/Platforms/GoogleBusinessService.php' => 'PHOTO_REF_PATTERN',
    'app/Services/Platforms/LinkInBioApiUnroller.php' => 'NICKNAME_PATTERN',
    'app/Services/Platforms/SquareMenuDriver.php' => 'API_ID_PATTERN',
];

it('has exactly one outbound HTTP door — no curl, no direct Guzzle, no URL file reads (Rule 1)', function () {
    $violations = OutboundHttpScanner::alternativeTransports(base_path('app'));

    expect($violations)->toBe([], PHP_EOL.PHP_EOL
        .'Alternative outbound HTTP transport(s) found in app/:'.PHP_EOL
        .implode(PHP_EOL, array_map(fn ($v) => "  {$v}", $violations)).PHP_EOL.PHP_EOL
        .'The Laravel Http facade is the only permitted outbound transport, so that the'.PHP_EOL
        .'classification rule below cannot be bypassed by reaching for a different client.'.PHP_EOL
        .'For a user-supplied URL use App\Services\Http\SafeUrlFetcher.'.PHP_EOL);
});

it('classifies every outbound HTTP call site in app/ (Rule 2)', function () {
    $sites = OutboundHttpScanner::httpFacadeCallSites(base_path('app'));

    $unclassified = [];
    foreach ($sites as $file => $lines) {
        if (! array_key_exists($file, OUTBOUND_HTTP_ALLOWLIST)) {
            $unclassified[] = "{$file}:".implode(',', $lines);
        }
    }

    expect($unclassified)->toBe([], PHP_EOL.PHP_EOL
        .'Unclassified outbound HTTP call site(s):'.PHP_EOL
        .implode(PHP_EOL, array_map(fn ($v) => "  {$v}", $unclassified)).PHP_EOL.PHP_EOL
        .'Every outbound fetch must be in one of four safe categories:'.PHP_EOL
        .'  B  SafeUrlFetcher        — the URL comes from a user/scrape. USE THIS unless you'.PHP_EOL
        .'                             are certain one of the others applies. Inject'.PHP_EOL
        .'                             App\Services\Http\SafeUrlFetcher and call fetch()/tryFetch().'.PHP_EOL
        .'  A  ConstantEndpoint      — host is a class const or config() value; a user cannot'.PHP_EOL
        .'                             influence WHICH host is contacted.'.PHP_EOL
        .'  C  HostAllowlist         — untrusted URL, but checked against an explicit host'.PHP_EOL
        .'                             allowlist AND ->withoutRedirecting().'.PHP_EOL
        .'  D  FixedHostVariablePath — host hardcoded, only the path varies. The variable'.PHP_EOL
        .'                             segment MUST be validated (Rule 3).'.PHP_EOL.PHP_EOL
        .'Adding an allowlist entry is NOT the default fix. If the URL originates from a'.PHP_EOL
        .'user, a scrape, or any third-party payload, the answer is B.'.PHP_EOL.PHP_EOL
        .'Allowlist: OUTBOUND_HTTP_ALLOWLIST in '.__FILE__.PHP_EOL);
});

it('has no stale allowlist entries (Rule 2)', function () {
    $sites = OutboundHttpScanner::httpFacadeCallSites(base_path('app'));

    $stale = array_values(array_diff(array_keys(OUTBOUND_HTTP_ALLOWLIST), array_keys($sites)));

    // A stale entry is a silent hole: the file keeps a standing exemption it no
    // longer needs, and a later edit reintroducing a fetch inherits it unreviewed.
    // The GS-1 allowlist already carries one of these (IndividualProfileController,
    // allowlisted for a docblock match that was never a call).
    expect($stale)->toBe([], PHP_EOL.PHP_EOL
        .'Allowlist entr(ies) for files that no longer make an outbound HTTP call:'.PHP_EOL
        .implode(PHP_EOL, array_map(fn ($v) => "  {$v}", $stale)).PHP_EOL.PHP_EOL
        .'Remove them from OUTBOUND_HTTP_ALLOWLIST — a standing exemption that nothing'.PHP_EOL
        .'needs is a hole waiting for the next edit to fall into.'.PHP_EOL);
});

it('validates the variable path segment on every fixed-host builder (Rule 3)', function () {
    $missing = [];

    foreach (OUTBOUND_HTTP_PATTERN_D_VALIDATORS as $file => $constant) {
        $source = (string) file_get_contents(base_path($file));

        $declaresPattern = str_contains($source, "const {$constant}");
        $appliesPattern = preg_match('/preg_match\s*\(\s*self::'.preg_quote($constant, '/').'/', $source) === 1;

        if (! $declaresPattern || ! $appliesPattern) {
            $missing[] = "{$file} (expected `const {$constant}` and a preg_match(self::{$constant}, …) call)";
        }
    }

    // The host is closed before the interpolation point, so this is NOT SSRF —
    // no value can redirect the request elsewhere. It is the same class as
    // unparameterised SQL: the danger is that a value can carry SYNTAX. A '?' or
    // '#' in a path segment reshapes the request just as a quote breaks a query.
    expect($missing)->toBe([], PHP_EOL.PHP_EOL
        .'Pattern D file(s) interpolating an unvalidated segment into a URL:'.PHP_EOL
        .implode(PHP_EOL, array_map(fn ($v) => "  {$v}", $missing)).PHP_EOL.PHP_EOL
        .'Declare a strict character-class const and apply it before building the URL.'.PHP_EOL);
});

it('ignores Http:: mentions that appear only in comments', function () {
    // Regression pin for the GS-1 failure mode. These three files mention Http::
    // in prose ONLY; a text-matching scanner would demand allowlist entries for
    // them, and ProcessShopBrandLogoJob's mention is a warning NOT to call it.
    $sites = OutboundHttpScanner::httpFacadeCallSites(base_path('app'));

    foreach ([
        'app/Jobs/Platforms/ProcessShopBrandLogoJob.php',
        'app/Jobs/Cloudflare/CloudflareCachePurgeJob.php',
        'app/Services/Platforms/ConnectResolver.php',
    ] as $commentOnly) {
        expect($sites)->not->toHaveKey($commentOnly, "{$commentOnly} mentions Http:: only in a comment and must not be treated as a call site");
    }
});

// Positive/negative control for Rule 1 (#TEST-4): the `[]` assertion above
// proves app/ is currently clean, not that alternativeTransports() can detect
// a violation at all. Each case below feeds the scanner a fixture directory
// directly, so the fixture cannot be silently orphaned by a moved reader.

it('flags curl_* calls as an alternative transport (Rule 1 positive control)', function () {
    $violations = outboundHttpScanFixture(<<<'PHP'
        <?php

        namespace Fixtures;

        class CurlFixture
        {
            public function fetch(): void
            {
                curl_init('http://example.com');
            }
        }
        PHP);

    expect($violations)->toHaveCount(1);
    expect($violations[0])->toContain('curl_init()');
});

it('flags a directly-instantiated Guzzle client as an alternative transport (Rule 1 positive control)', function () {
    $violations = outboundHttpScanFixture(<<<'PHP'
        <?php

        namespace Fixtures;

        class GuzzleFixture
        {
            public function fetch(): void
            {
                $client = new \GuzzleHttp\Client();
                $client->get('http://example.com');
            }
        }
        PHP);

    expect($violations)->toHaveCount(1);
    expect($violations[0])->toContain('GuzzleHttp');
});

it('flags file_get_contents() on a literal http(s) URL as an alternative transport (Rule 1 positive control)', function () {
    $violations = outboundHttpScanFixture(<<<'PHP'
        <?php

        namespace Fixtures;

        class StreamWrapperFixture
        {
            public function fetch(): void
            {
                file_get_contents('http://example.com/logo.png');
            }
        }
        PHP);

    expect($violations)->toHaveCount(1);
    expect($violations[0])->toContain('file_get_contents() on an http(s) URL');
});

it('does not flag file_get_contents() on a local path (Rule 1 negative control)', function () {
    $violations = outboundHttpScanFixture(<<<'PHP'
        <?php

        namespace Fixtures;

        class LocalStreamWrapperFixture
        {
            public function read(): string|false
            {
                return file_get_contents(__DIR__.'/fixture.txt');
            }
        }
        PHP);

    expect($violations)->toBe([]);
});

it('does not flag the Http facade as an alternative transport (Rule 1 negative control)', function () {
    $violations = outboundHttpScanFixture(<<<'PHP'
        <?php

        namespace Fixtures;

        use Illuminate\Support\Facades\Http;

        class HttpFacadeFixture
        {
            public function fetch(): void
            {
                Http::get('http://example.com');
            }
        }
        PHP);

    expect($violations)->toBe([]);
});
