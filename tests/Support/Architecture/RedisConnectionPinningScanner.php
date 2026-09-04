<?php

namespace Tests\Support\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Source scanner for RedisConnectionPinningTest.
 *
 * Drill 03 (2026-08-05) measured an authenticated request taking 32.01s
 * against a hung Redis. Cause: TokenRevocationService called the bare
 * `Redis::` facade, which resolves to config('database.redis.default') —
 * read_timeout 15.0s, a bound sized for queue workers' BLPOP, not a
 * user-facing request. U1 (same date) added a request-path `app` connection
 * at read_timeout 3.0s and repointed every known caller. RedisTimeoutBoundsTest
 * guards the CONNECTION side of that fix (no connection drifts above 3.0s
 * unless it's on the worker-path allow-list) but nothing guarded the CALLER
 * side — a new `Redis::get(...)` written tomorrow in a request-path file
 * silently inherits `default` and reproduces the exact incident. This scanner
 * is the caller-side half.
 *
 * Modelled on RawCacheCallScanner (GS-1): token_get_all() has no concept of a
 * line or a string literal, so — unlike the grep this pattern replaces
 * elsewhere in the repo — a comment that merely mentions `Redis::eval()` (see
 * TokenRevocationService's class docblock, RecordCacheMetrics's __destruct()
 * docblock) cannot trip it, and a call artificially split across lines cannot
 * evade it either. T_COMMENT/T_DOC_COMMENT tokens are skipped when walking
 * from the facade name to its method name.
 *
 * `Redis::connection(...)` is explicitly the escape hatch, not a violation —
 * that is how request-path code opts into `app` (or any other named
 * connection) instead of inheriting whatever the bare facade resolves to.
 */
final class RedisConnectionPinningScanner
{
    /** Both real app/ source (.php) and the guard-test fixtures (.stub) are scanned. */
    private const SCANNED_EXTENSIONS = ['php', 'stub'];

    /**
     * Directories where a request-path caller lives. Deliberately excludes
     * app/Console/Commands (schedule/CLI context) and app/Http/Controllers
     * (none call Redis directly today — every Redis-touching controller goes
     * through a Services/ class, which IS scanned).
     *
     * app/Jobs IS scanned, not skipped, precisely so ALLOWLIST's blanket
     * 'app/Jobs/' entry below is a live exemption rather than dead code — a
     * job file that stops being job-only and gets called from a controller
     * would otherwise inherit an unreviewed pass.
     */
    public const SCANNED_DIRECTORIES = [
        'app/Http/Middleware',
        'app/Services',
        'app/Listeners',
        'app/Jobs',
    ];

    /**
     * Files/directories (path-prefix match, same convention as
     * RawCacheCallScanner::ALLOWLIST) deliberately left on the bare facade —
     * i.e. on `default` (or `queue`/`horizon`) — because they are worker/job
     * context, not request path, and so legitimately want the 15.0s bound
     * `default` reserves for BLPOP.
     */
    private const ALLOWLIST = [
        // Blanket: everything under app/Jobs/ runs on a queue worker, never
        // inline on the request path. Includes the three files U1's review
        // explicitly named as "do NOT repoint": AggregateCacheMetricsJob.php,
        // Streaming/CheckStreamingLiveStatusJob.php, and
        // Concerns/GuardsMediaProcessing.php (a trait, mixed into jobs only).
        'app/Jobs/',

        // Job-only despite living under app/Services/ (SCANNED_DIRECTORIES
        // scans all of Services/ — there is no "Services/Streaming is
        // job-only" carve-out at the directory level, so these need their own
        // entries). Verified 2026-08-05 by grepping every caller of each
        // class: neither is referenced from app/Http/ at all.
        //
        // StreamingTokenManager: reachable only via TwitchApiClient/
        // KickApiClient -> LiveStatusPoller -> CheckStreamingLiveStatusJob,
        // a queued job. (It was also reachable via TwitchConnector ->
        // Ingest\RunSourceJob until Phase 1 de-sourced Twitch; the live-status
        // lane above is unrelated to ingest and survives.) Investigated (not
        // repointed) during U1 review follow-up; recommendation was to leave
        // it on `default`.
        'app/Services/Streaming/StreamingTokenManager.php',

        // LiveStatusPoller: reachable only via CheckStreamingLiveStatusJob.
        // It WRITES the `streaming:live:<platform>:<handle>` keys. The
        // request-path READER that once paired with it was deleted with the
        // superseded payload lane (2026-09-04), so the writer/reader split
        // this entry used to justify no longer exists: the poller is now
        // job-only end to end, and `default` — the worker-path bound — is the
        // connection it wants.
        'app/Services/Streaming/LiveStatusPoller.php',
    ];

    /**
     * Every bare `Redis::<command>(` call site under SCANNED_DIRECTORIES,
     * `Redis::connection(...)` and comments excluded, allowlist NOT applied.
     *
     * @return array<string, list<int>> relative file path => 1-indexed line numbers
     */
    public static function bareFacadeCalls(?array $directories = null): array
    {
        $sites = [];

        foreach (self::phpFiles($directories ?? self::SCANNED_DIRECTORIES) as $relative => $source) {
            $lines = [];

            $tokens = token_get_all($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (! is_array($token) || ! self::namesRedisFacade($token)) {
                    continue;
                }

                $method = self::methodTokenAfterDoubleColon($tokens, $i);

                if ($method === null) {
                    continue;
                }

                [$methodToken, $callIndex] = $method;

                if ($methodToken[1] === 'connection') {
                    // The sanctioned escape hatch — Redis::connection('app') and
                    // friends are how request-path code opts OUT of the bare
                    // facade's default resolution. Not a violation.
                    continue;
                }

                if (! self::isFollowedByCallParens($tokens, $callIndex)) {
                    // Named but not invoked here (e.g. referenced as a callable
                    // string elsewhere) — nothing actually ran against `default`.
                    continue;
                }

                $lines[] = $token[2];
            }

            if ($lines !== []) {
                $sites[$relative] = array_values(array_unique($lines));
            }
        }

        ksort($sites);

        return $sites;
    }

    /**
     * Bare-facade call sites whose file is not covered by ALLOWLIST.
     *
     * @return list<string> "path:line"
     */
    public static function unallowlistedBareFacadeCalls(?array $directories = null): array
    {
        $violations = [];

        foreach (self::bareFacadeCalls($directories) as $relative => $lines) {
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

    private static function isAllowlisted(string $relative): bool
    {
        foreach (self::ALLOWLIST as $entry) {
            if (str_starts_with($relative, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** Does this token name the Redis facade — bare, qualified, or fully qualified? */
    private static function namesRedisFacade(array $token): bool
    {
        if (! self::isNameToken($token[0])) {
            return false;
        }

        $name = $token[1];

        return $name === 'Redis' || str_ends_with($name, '\\Redis');
    }

    private static function isNameToken(int $id): bool
    {
        return $id === T_STRING
            || (defined('T_NAME_QUALIFIED') && $id === T_NAME_QUALIFIED)
            || (defined('T_NAME_FULLY_QUALIFIED') && $id === T_NAME_FULLY_QUALIFIED);
    }

    /**
     * Given the index of a `Redis` name token, find the method-name token
     * that immediately (modulo whitespace/comments) follows a `::`, or null
     * if $i is not followed by `::` at all (e.g. a `use Facades\Redis;`
     * import, or a `Redis` mention with no member access after it).
     *
     * @return array{0: array{0:int,1:string,2:int}, 1: int}|null [methodToken, methodTokenIndex]
     */
    private static function methodTokenAfterDoubleColon(array $tokens, int $i): ?array
    {
        $count = count($tokens);
        $sawDoubleColon = false;

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if (! $sawDoubleColon && $token[0] === T_DOUBLE_COLON) {
                    $sawDoubleColon = true;

                    continue;
                }

                if (! $sawDoubleColon) {
                    return null;
                }

                if ($token[0] !== T_STRING) {
                    // e.g. Redis::class — not a method call we care about.
                    return null;
                }

                return [$token, $j];
            }

            // A bare-string token ('(' etc.) directly after the name with no
            // `::` in between means this wasn't a static-member access at all.
            if (! $sawDoubleColon) {
                return null;
            }

            return null;
        }

        return null;
    }

    /** Is the next significant token after $methodIndex an opening `(` — i.e. is this an actual call? */
    private static function isFollowedByCallParens(array $tokens, int $methodIndex): bool
    {
        $count = count($tokens);

        for ($j = $methodIndex + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return false;
            }

            return $token === '(';
        }

        return false;
    }

    /**
     * Relative-path => source for every scanned file under each of $directories
     * (each relative to base_path()).
     *
     * @param  list<string>  $directories
     * @return array<string, string>
     */
    private static function phpFiles(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $absolute = base_path($directory);

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), self::SCANNED_EXTENSIONS, true)) {
                    continue;
                }

                $relative = str_replace(DIRECTORY_SEPARATOR, '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));

                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
