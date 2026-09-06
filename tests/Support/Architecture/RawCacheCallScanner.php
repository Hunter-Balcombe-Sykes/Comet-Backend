<?php

namespace Tests\Support\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Source scanner for RawCacheCallGuardTest (GS-1).
 *
 * GS-1: Cache keys must flow through CacheKeyGenerator and a *CacheService to
 * preserve tenant-prefix discipline. A rogue Cache::put('user_count', $n) in a
 * controller is one git push away from a cross-tenant data leak — this guard
 * blocks that class of bug before it reaches review.
 *
 * This replaces the two-stage `git grep -n -P '\bCache::(put|…)\b'` that lived
 * in .github/workflows/ci.yml until COV-GUARD-2. That grep was line-oriented,
 * so a call with the method split from `Cache::` across lines — or matched
 * against a fixture/comment — was a text-matching exercise, not a semantic
 * one. token_get_all() has no concept of a line, so wrapping the call cannot
 * evade it, and T_COMMENT/T_DOC_COMMENT are discarded before matching, so a
 * comment that merely mentions `Cache::put(...)` cannot force a false
 * allowlist entry the way IndividualProfileController's did (see ALLOWLIST
 * below).
 *
 * Six ALLOWLIST entries are marked STALE, verified 2026-08-03 by re-running
 * the ORIGINAL ci.yml grep against today's tree, not just this scanner: five
 * are ordinary code drift since their audit date (a refactor onto a proper
 * *CacheService, a rename onto Cache::lock()/Cache::get() — methods GS-1
 * never gated — or a file deleted outright) and would already be stale under
 * the old grep too. Only IndividualProfileController is specific to THIS
 * migration (its match was a docblock mention, which token scanning ignores).
 * None are removed here — see each entry's comment and plan §4/§8.
 */
final class RawCacheCallScanner
{
    private const METHODS = ['put', 'remember', 'rememberForever', 'forget', 'add'];

    /** Both real app/ source (.php) and the guard-test fixtures (.stub) are scanned. */
    private const SCANNED_EXTENSIONS = ['php', 'stub'];

    /**
     * Files exempt from GS-1. Migrated verbatim from .github/workflows/ci.yml
     * on 2026-08-03 — the comments below are the audit record of four separate
     * incidents (2026-06-12, 2026-07-22, 2026-07-25, 2026-07-28) in which this
     * gate was masked by an earlier red CI step and unscanned code landed. Do
     * not compress, reword, or delete an entry — see the class docblock and
     * the "no stale allowlist entries" test for how staleness is handled
     * instead.
     *
     * Historical note on the grep this replaces, preserved for context: the
     * old pattern was `-P` (PCRE), not `-E` — it needed `\b` at both ends
     * (leading, so MyCache::put didn't match; trailing, so Cache::adder
     * didn't). BSD regcomp (macOS) does not support `\b` in ERE and silently
     * matched nothing, so `-E` made the guard report a clean pass locally no
     * matter what was written, while the same command on the glibc CI runner
     * found 50 lines. Not applicable to token scanning, which has no regex
     * flavour to get wrong — recorded so the failure mode isn't forgotten.
     */
    private const ALLOWLIST = [
        // Allowlisted by path (ci.yml "Allowlisted by path:" section).
        'app/Services/Cache/', // the canonical cache layer itself
        'app/Http/Controllers/Api/Webhooks/', // Cache::add() for idempotency dedupe
        // T14 (2026-08-27): the bio-mention chain's GLOBAL per-handle scrape
        // cache — one namespaced literal key ('bio_mention_profile:{handle}'),
        // cross-tenant BY DESIGN (the same brand appears in hundreds of bios;
        // one paid scrape shared), no per-user CacheKeyGenerator shape fits.
        'app/Jobs/PreAccount/BioMentionChainsJob.php',
        // Wave 3 (2026-09-01): one atomic Cache::add SETNX throttling the
        // zero-dispatch media_mirror log line to once/user/hour — a log
        // throttle (same class as the webhook idempotency dedupe above),
        // never tenant data; wrapped in try/catch so a cache outage costs
        // the throttle, not the line or the projection.
        'app/Ingest/Projection/ProjectionWriter.php',

        // This entry carries NO justification comment in ci.yml — it is
        // present in the git-grep pathspec list with no accompanying bullet
        // in the "File-level exceptions" blocks below or above it. Recorded
        // as-is (verbatim absence, not invented) during the COV-GUARD-2
        // migration; worth raising in the next consolidation.
        'app/Http/Controllers/Api/HealthController.php',

        // File-level exceptions (audited 2026-06-12 — the guard shipped while CI
        // was failing at the pint step, so these pre-existing callers were never
        // caught; each is a deliberate pattern, not a tenant-key leak):
        // STALE — code drift, NOT the token migration: verified 2026-08-03 that
        // even re-running the ORIGINAL ci.yml grep against today's tree finds
        // nothing here. CustomerObserver was refactored to route through
        // UserCacheService::invalidateCustomerCount() (a proper *CacheService)
        // and no longer makes a raw Cache:: call of any kind. Not removed here:
        // deleting an allowlist entry in the same commit that changes the
        // scanner makes the diff unreviewable (see plan §4).
        'app/Observers/Core/CustomerObserver.php', // Cache::forget via CacheKeyGenerator; direct invalidation in observer, no service injection point
        'app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php', // Cache::add SETNX webhook dedupe + Cache::forget rollback on send failure
        // STALE — code drift, NOT the token migration: verified 2026-08-03 that
        // the original grep also finds nothing today. The file's only raw
        // Cache:: calls are now Cache::lock(...) (connection-lock, not one of
        // GS-1's five gated methods) — the Cache::add() this entry was
        // allowlisted for is gone.
        'app/Http/Controllers/Api/Platforms/InstagramController.php', // refresh budget + cooldown via atomic Cache::add (comments also match the grep)
        // Plan 3 R3 (2026-08-27): 6h negative cache on a shortcode whose
        // Instagram media refresh failed on BOTH legs — an internal cooldown
        // keyed by shortcode/kind/position (never tenant-keyed), same class
        // of exception as InstagramController's budget/cooldown above.
        'app/Services/Media/InstagramMediaUrl.php', // Cache::put failed-refresh cooldown (media_mirror)
        'app/Http/Controllers/Api/Platforms/ShopController.php', // short-lived picker-catalog cache, keys via CacheKeyGenerator
        // STALE after the token migration; retire in a follow-up. This entry
        // was allowlisted for a docblock mention only ("grep matches the
        // comment") — token_get_all() discards T_COMMENT/T_DOC_COMMENT, so
        // this file now produces zero raw hits and the entry is unneeded.
        // Not removed here: deleting an allowlist entry in the same commit
        // that changes the scanner makes the diff unreviewable (see plan §4).
        'app/Http/Controllers/Api/PublicSite/IndividualProfileController.php', // docblock mention only (grep matches the comment)
        'app/Http/Middleware/IdempotencyKey.php', // the idempotency exception the error copy itself names
        'app/Http/Middleware/Logging/LogLeadRateLimits.php', // Cache::add SETNX dedupe of auto-retry log bursts
        'app/Jobs/Notifications/SendFeedbackEmailJob.php', // job idempotency SETNX + rollback
        'app/Services/Analytics/AnalyticsCacheService.php', // IS a *CacheService, just lives under Services/Analytics/
        'app/Services/Analytics/AnalyticsDedupGuard.php', // atomic SETNX fixed-window dedup utility
        'app/Services/FeatureFlags/FeatureFlagService.php', // flag registry/per-pro invalidation (Cache::forget)
        'app/Http/Controllers/Api/Platforms/RefreshController.php', // Cache::add SETNX per-user refresh cooldown (key is user+platform scoped)
        'app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php', // Cache::put notify-once idempotency guard (enquiry-id scoped)
        'app/Services/Platforms/YoutubeThumbnailResolver.php', // thumbnail-tier memo with jittered TTL

        // File-level exceptions (audited 2026-07-22 — same story: the whole gate
        // chain after composer audit was masked, so these pre-existing callers were
        // never caught until the chain was fixed. Each key is scoped/content-addressed
        // — vetted, not a tenant leak):
        'app/Http/Controllers/Api/Staff/Analytics/StaffAggregateAnalyticsController.php', // staff-only aggregate; key encodes the full scope (all|segment-hash)+window; cross-tenant BY DESIGN (staff see all), so tenant isolation is N/A
        'app/Jobs/Platforms/GoogleBusinessEnrichJob.php', // paid-Apify inflight/result guard, keys via CacheKeyGenerator::googleBusinessApify*(userId, placeId) (same pattern as InstagramController)
        'app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php', // #LIFE-9: Cache::add SETNX claim before dispatching a billed OCR/AI sub-job, so a manual Horizon retry can't re-dispatch (and re-bill) one a prior attempt already sent — keys via CacheKeyGenerator::websiteScanSubJobDispatched(userId, kind, sha1(payload)), same pattern as GoogleBusinessEnrichJob above
        // STALE — code drift, NOT the token migration: verified 2026-08-03 that
        // neither file exists under app/ any more (find turns up nothing at
        // either path). Whatever removed/relocated the Design Presets factors
        // did not clean up these two exceptions.
        'app/Services/Design/Presets/Factors/OwnMediaAccentFactor.php', // content-addressed memo (key = md5 of image URL); value is a non-sensitive computed hex colour
        'app/Services/Design/Presets/Factors/StorePricePointFactor.php', // site-scoped streak key (design:store-price-streak:<site_id>)
        'app/Services/User/AccountDeletionService.php', // Cache::forget of the user's OWN idempotency keys (enumerated from idempotencyIndexKey(authUserId)) during account teardown

        // File-level exceptions (audited 2026-07-25 — same story a third time: all
        // three landed while CI was red at PHPStan/Checkpoint, so GS-1 never ran
        // on them. Each key is scoped/content-addressed — vetted, not a leak):
        'app/Jobs/Moderation/Concerns/DedupesRecipientSends.php', // RV-10 per-recipient Cache::add SETNX claim + Cache::forget rollback; key is action-log-scoped and content-addressed (sha256 of "<actionLogId>|<recipient>"), same pattern as SendFeedbackEmailJob
        'app/Services/Platforms/MenuApifyScraper.php', // negative-cache "blocked/hard_error" marker + its recovery forget, key via CacheKeyGenerator::menuScrapeBlocked(platform, url) (same shape as YoutubeThumbnailResolver/AppleSearch)
        // STALE — code drift, NOT the token migration: the legacy shop-brand
        // pipeline was deleted outright (WAVE-2C, 2026-08-06) — CommerceProbeJob
        // now cuts over to StoreBrandSeeder (App\Services\Brand), which carries
        // no picker-catalog warm of its own. The file this entry names is gone.
        'app/Services/Platforms/ShopBrandSeeder.php', // picker-catalog warm mirroring addBrand()'s, key via CacheKeyGenerator::shopifyBrandCatalog($id); ShopController is already allowlisted for this exact cache

        // File-level exceptions (audited 2026-07-28 — a FOURTH round of the same
        // story: all three landed while CI was red at PHPStan, so GS-1 never ran
        // on them. Each key is scoped/content-addressed — vetted, not a leak):
        'app/Listeners/RecordScheduledTaskHeartbeat.php', // scheduler liveness beacon; key is the class const prefix + a normalized TASK name (taskKey()), carries no tenant identity at all
        // STALE — code drift, NOT the token migration: verified 2026-08-03 that
        // the original grep also finds nothing today. The file's only raw
        // Cache:: calls are now Cache::get() (a read, not one of GS-1's five
        // gated methods) — the Cache::add() counter-init this entry was
        // allowlisted for is gone; no increment/decrement remains either.
        'app/Routing/Probes/ProbeBudget.php', // atomic Cache::add SETNX counter init (+increment/decrement), keys via CacheKeyGenerator::routingProbe{Global,User}Daily(); same shape as AnalyticsDedupGuard/RefreshController. The docblock at :24 also matches the grep
        'app/Routing/LinkProjector.php', // Cache::add SETNX throttling malformed-pattern error reports to once per detector+field per window, so one bad catalog entry cannot flood Nightwatch. Key is "routing:malformed-detector:{$detectorId}:{$field}" — BOTH segments are catalog-level identifiers, not user- or site-scoped, so there is no tenant prefix to preserve and no cross-tenant collision to cause. Same idiom and same reasoning as LogLeadRateLimits and AnalyticsDedupGuard, which its own docblock (:204) cites as precedent and which are already excluded below.
        'app/Routing/Probes/ProbeGate.php', // probe-answer memo incl. negative results, key via CacheKeyGenerator::routingProbeUrl($url) — content-addressed by URL (same shape as YoutubeThumbnailResolver/AppleSearch/MenuApifyScraper)

        // Added 2026-08-19 with the catalog runtime-table wiring. Unlike every
        // entry above, this one was caught by the guard on the way in rather
        // than after landing behind a red CI step.
        'app/Catalog/DetectorSuspensions.php', // Cache::remember/forget of the detector kill-switch set. ONE constant key ('catalog:detector-suspensions') holding a global list of catalog-level detector ids — no user, no site, no tenant identity of any kind, so there is no key to construct and nothing for CacheKeyGenerator to scope. Same reasoning as RecordScheduledTaskHeartbeat (a global beacon key) and LinkProjector above (catalog-level identifiers only). A *CacheService for a single constant key would be ceremony, not isolation. TTL is enforced (config partna.catalog.suspension_cache_ttl_seconds, floored at 1) and asserted behaviourally in DetectorSuspensionSourceTest.

        // Added 2026-09-02. This one DID land behind a red step — `21bcbe272`
        // ("A landed mirror purges the edge HTML too") went in while the test
        // job was already failing, so GS-1 never ran on it; the fifth round of
        // the same story the sections above record.
        'app/Services/Media/MediaMirror.php', // Cache::add SETNX debouncing the edge purge to one per site per 15s, because a build wave lands hundreds of mirrors and each would otherwise dispatch its own CloudflareCachePurgeJob. Key is 'media_mirror:purge:{subdomain}' — a subdomain is globally unique and publicly addressable (it IS the site's URL), so the key is site-scoped by construction, carries no tenant-private identity, and cannot collide across tenants. Same idiom and same reasoning as ProjectionWriter's media_mirror log throttle directly above and LogLeadRateLimits/AnalyticsDedupGuard. The whole block sits inside landed()'s try/catch, so a cache outage costs the debounce — an extra purge — not the mirror.

        // Added 2026-09-06. Landed in f2786a9b6 (2026-09-05) while this lane's
        // required check was already red on `development` for an unrelated
        // reason, so GS-1 never ran on it before merge — the same story the
        // sections above record.
        'app/Http/Controllers/Api/User/Setup/SetupController.php', // Cache::add SETNX claim stamping one paid Apify menu-photo sweep per user per day, so a Back/Continue bounce across the platforms→content crossing can't re-bill the sweep — key via CacheKeyGenerator::menuPhotoSweepOnce($userId), same pattern as ScanPreviousWebsiteContentJob's re-dispatch/re-bill guard above.
    ];

    /**
     * Every raw Cache::(put|remember|rememberForever|forget|add) call site in
     * $appPath, comments excluded, allowlist NOT applied.
     *
     * @return array<string, list<int>> relative file path => 1-indexed line numbers
     */
    public static function rawCacheCalls(string $appPath): array
    {
        $sites = [];

        foreach (self::phpFiles($appPath) as $relative => $source) {
            $lines = [];

            $tokens = token_get_all($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (! is_array($token) || ! self::namesCacheFacade($token)) {
                    continue;
                }

                $afterColons = self::indexAfterDoubleColon($tokens, $i);

                if ($afterColons === null) {
                    continue;
                }

                $method = $tokens[$afterColons];

                if (is_array($method) && $method[0] === T_STRING && in_array($method[1], self::METHODS, true)) {
                    $lines[] = $token[2];
                }
            }

            if ($lines !== []) {
                $sites[$relative] = array_values(array_unique($lines));
            }
        }

        ksort($sites);

        return $sites;
    }

    /**
     * Raw call sites whose file is not covered by ALLOWLIST.
     *
     * @return list<string> "path:line"
     */
    public static function unallowlistedCacheCalls(string $appPath): array
    {
        $violations = [];

        foreach (self::rawCacheCalls($appPath) as $relative => $lines) {
            if (self::isAllowlisted($relative)) {
                continue;
            }

            foreach ($lines as $line) {
                $violations[] = "{$relative}:{$line}";
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * ALLOWLIST entries whose file produces zero raw hits today — a standing
     * exemption nobody needs, OR (post token-migration) an entry that only
     * ever matched a comment/docblock mention.
     *
     * @return list<string>
     */
    public static function staleAllowlistEntries(string $appPath): array
    {
        $sites = self::rawCacheCalls($appPath);
        $stale = [];

        foreach (self::ALLOWLIST as $entry) {
            $covers = false;

            foreach (array_keys($sites) as $relative) {
                if (str_starts_with($relative, $entry)) {
                    $covers = true;

                    break;
                }
            }

            if (! $covers) {
                $stale[] = $entry;
            }
        }

        return $stale;
    }

    private static function isAllowlisted(string $relative): bool
    {
        foreach (self::ALLOWLIST as $entry) {
            if (str_starts_with($relative, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** Does this token name the Cache facade — bare, qualified, or fully qualified? */
    private static function namesCacheFacade(array $token): bool
    {
        if (! self::isNameToken($token[0])) {
            return false;
        }

        $name = $token[1];

        return $name === 'Cache' || str_ends_with($name, '\\Cache');
    }

    private static function isNameToken(int $id): bool
    {
        return $id === T_STRING
            || (defined('T_NAME_QUALIFIED') && $id === T_NAME_QUALIFIED)
            || (defined('T_NAME_FULLY_QUALIFIED') && $id === T_NAME_FULLY_QUALIFIED);
    }

    /**
     * Index of the first significant token AFTER a `::` that immediately
     * (modulo whitespace/comments) follows $i, or null if $i is not followed
     * by `::` at all (e.g. a `use Facades\Cache;` import, a type-hint mention).
     */
    private static function indexAfterDoubleColon(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        $sawDoubleColon = false;

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                // `::` is itself an array token (T_DOUBLE_COLON), not a bare
                // string — unlike '(' or ',' elsewhere in this file.
                if (! $sawDoubleColon && $token[0] === T_DOUBLE_COLON) {
                    $sawDoubleColon = true;

                    continue;
                }

                if (! $sawDoubleColon) {
                    return null;
                }

                return $j;
            }

            if (! $sawDoubleColon) {
                return null;
            }

            return $j;
        }

        return null;
    }

    /**
     * Relative-path => source for every scanned file under $appPath.
     *
     * Mirrors OutboundHttpScanner::phpFiles() exactly: relative to
     * dirname($appPath), so the standard call — rawCacheCalls(base_path('app'))
     * — yields "app/…" paths that match ALLOWLIST's own "app/…" pathspecs
     * verbatim (ALLOWLIST was migrated straight from ci.yml's git-grep
     * pathspecs, which are repo-root relative).
     *
     * @return array<string, string>
     */
    private static function phpFiles(string $appPath): array
    {
        $base = dirname($appPath).DIRECTORY_SEPARATOR;
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), self::SCANNED_EXTENSIONS, true)) {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', str_replace($base, '', $file->getPathname()));

            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }
}
