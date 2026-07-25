<?php

use Checkpoint\Checks;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled Checks
    |--------------------------------------------------------------------------
    |
    | Every default check is listed here and enabled by default. Set any
    | entry to `false` to exclude it from `php artisan checkpoint:scan`.
    |
    | Checks not listed in this map fall back to enabled — so when you
    | upgrade Checkpoint and new checks are added, you keep the protection
    | without re-publishing this file.
    |
    */

    'checks' => [
        Checks\ComposerAuditCheck::class => true,
        Checks\NpmAuditCheck::class => true,
        Checks\EnvironmentCheck::class => true,
        Checks\GitIgnoreCheck::class => true,
        Checks\FilePermissionsCheck::class => true,
        Checks\HardcodedSecretsCheck::class => true,
        Checks\SqlInjectionCheck::class => true,
        Checks\MassAssignmentCheck::class => true,
        Checks\XssCheck::class => true,
        Checks\CsrfCheck::class => true,
        Checks\OpenRedirectCheck::class => true,
        Checks\CommandInjectionCheck::class => true,
        Checks\InsecureDeserializationCheck::class => true,
        Checks\DebugFunctionsCheck::class => true,
        Checks\SensitiveExposureCheck::class => true,
        Checks\SsrfCheck::class => true,
        Checks\TlsVerificationCheck::class => true,
        Checks\CorsConfigCheck::class => true,
        Checks\PackageFreshnessCheck::class => true,
        Checks\SuspiciousVendorAutoloadCheck::class => true,
        Checks\SupplyChainToolingCheck::class => true,
        Checks\PathTraversalCheck::class => true,
        Checks\WeakCryptographyCheck::class => true,
        Checks\InsecureRngCheck::class => true,
        Checks\SessionSecurityCheck::class => true,
        Checks\EolVersionCheck::class => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Package Freshness (Supply Chain)
    |--------------------------------------------------------------------------
    |
    | Composer packages released within `minimum_age_days` will fail the
    | "Package Freshness" check. This mitigates supply-chain attacks that
    | typically get caught and removed from Packagist within hours or days.
    |
    | Add fully-qualified package names to `whitelist` to bypass the age
    | check for specific dependencies (e.g. a critical security patch you
    | need to deploy before the freshness window expires).
    |
    */

    'package_freshness' => [
        'minimum_age_days' => 3,
        'whitelist' => [
            // Checkpoint exempts itself from the freshness gate so a fresh
            // release of the scanner cannot block its own user's deploy.
            'andreapollastri/checkpoint',
            // 'vendor/package',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suspicious Vendor Autoload
    |--------------------------------------------------------------------------
    |
    | The "Suspicious Vendor Autoload" check warns when a package under
    | vendor/ registers PHP files via `autoload.files` — the exact mechanism
    | abused by the May 2026 Laravel-Lang supply-chain attack to execute
    | code on every request.
    |
    | A baked-in whitelist already covers packages that legitimately use
    | this mechanism (laravel/framework, symfony/polyfill-*, guzzlehttp/*,
    | ramsey/uuid, …). Add your own trusted entries below — exact matches
    | or `vendor/*` wildcards are both supported.
    |
    */

    'suspicious_autoload' => [
        // Well-known packages that legitimately register global helpers via
        // autoload.files (vetted 2026-07-19). A NEW package appearing in this
        // check is the actual supply-chain signal — inspect before whitelisting.
        'whitelist' => [
            'aws/aws-sdk-php',
            'laravel/nightwatch',
            'laravel/prompts',
            'mockery/mockery',
            'mtdowling/jmespath.php',
            'myclabs/deep-copy',
            'nunomaduro/collision',
            'nunomaduro/termwind',
            'pestphp/*',
            'phpstan/phpstan',
            'phpunit/phpunit',
            'psy/psysh',
            'ralouphie/getallheaders',
            'resend/resend-php',
            'sebastian/global-state',
            'sebastian/type',
            'symfony/clock',
            'symfony/string',
            'symfony/translation',
            'symfony/var-dumper',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suppressed Findings
    |--------------------------------------------------------------------------
    |
    | Add 12-character finding hashes here to silence specific FAIL/WARN
    | issues you have intentionally accepted (false positive, legacy code,
    | etc.). Hashes are shown in square brackets next to each finding when
    | you run the scan — copy the bracketed value into this array.
    |
    | The hash is content-stable: refactors that only shift line numbers
    | will not invalidate it.
    |
    | If every finding of a check is suppressed, the check is downgraded to
    | PASS with an explicit "N suppressed" message.
    |
    */

    'suppressed' => [
        // ── SQL injection: false positives, vetted 2026-07-19 ──────────────
        // All interpolations are code-built SQL expressions (driver-conditional
        // CASE/date-bucket/visitor exprs) or criterion-supplied column names —
        // never request input. Values always travel via bindings. The analytics
        // and segments query builders went through dedicated audits.
        '7e2e3339d4b3', // IgFollowersCriterion:87 — expr + bound int
        'fde9dd8fe4c6', // IgFollowersCriterion:91 — expr + bound int
        '8a2ff5b41ba2', // MatchesFreeTextLocation:25 — criterion-supplied column, bound needles
        '417d132cdb1c', // EarlyAccessCriterion:35 — code-built EXISTS expr
        '9ca95e170123', // AnalyticsCriterion:113 — code-built inner query + bindings
        'c401defb23ee', // AnalyticsCriterion:124 — code-built expr + bound values
        '9467622e6a7a', // PostgresEventWriter:388 — $ms int-clamped (line 366)
        '745c69b6d434', // AnalyticsQueryService — uniqueVisitorExpr() is code-built
        '2e3559b79e10', // AnalyticsQueryService — uniqueVisitorExpr()
        '0bae9835c674', // AnalyticsQueryService — driver-conditional bucket expr
        'c87490e7915d', // AnalyticsQueryService — code-built CASE expr
        '95b18828281d', // AnalyticsQueryService — code-built CASE expr
        '4de601db4130', // AnalyticsQueryService — uniqueVisitorExpr()
        '7457929c3c8b', // AnalyticsQueryService — code-built CASE expr
        'b699207c4b55', // AnalyticsQueryService — uniqueVisitorExpr()
        'be76b951dc38', // AnalyticsQueryService — code-built CASE expr
        '47fee294fcb4', // AnalyticsQueryService — uniqueVisitorExpr()
        '0892b46857d7', // AnalyticsQueryService — uniqueVisitorExpr()
        'e4e0d5609005', // AnalyticsQueryService — uniqueVisitorExpr()
        '2fc18adf2dfb', // AnalyticsQueryService — uniqueVisitorExpr()
        '9c4161933fb6', // AnalyticsQueryService — driver-conditional hour expr
        '25644227c885', // AnalyticsQueryService — driver-conditional dow expr
        'b480d73a6c7d', // AnalyticsQueryService — code-built CASE expr
        'cc081b49615e', // AnalyticsQueryService — code-built CASE expr
        // Re-vetted 2026-07-25: the demand-rate rewrite (07d5d515) changed the SQL
        // TEXT of both queries ("platform, … COUNT(*)" → "action_id, event, …
        // COUNT(DISTINCT COALESCE(…))"), so the old :290/:291 hashes went dead.
        // $day is still dayBucketExpr() — two hardcoded literals chosen on driver
        // name, never request input.
        'a70c32075dec', // RankedActionsComputer:132 — driver-conditional day expr
        '4e294d3c9300', // RankedActionsComputer:133 — driver-conditional day expr
        '73b44016b226', // ComputeContentPopularityScores — driver-conditional day expr
        '82d53234faf4', // ComputeContentPopularityScores — driver-conditional day expr
        'e1a9fc731742', // ComputeContentPopularityScores — driver-conditional day expr
        'dd810a95df0c', // ComputeContentPopularityScores — driver-conditional day expr
        '829c9554911e', // ComputeContentPopularityScores — driver-conditional day expr
        '3e38e942b939', // ComputeContentPopularityScores — driver-conditional day expr
        '7c06737326a6', // ComputeContentPopularityScores — driver-conditional day expr
        '6e4b3296bd03', // SiteProvisioningSavepointTest — local const table name
        '731db7a5ac32', // SiteProvisioningSavepointTest — local const table name
        '55939d4857c2', // PreAccountBuildHandleRaceTest — CREATE TEMP TABLE, local const table name
        '2d77dfcb34d9', // PreAccountBuildHandleRaceTest — DROP TABLE, local const table name
        'bd0226c951ba', // ItemSlugAllocatorSavepointTest — CREATE TEMP TABLE, Str::random() local table name
        '28488bff79f2', // ItemSlugAllocatorSavepointTest — DROP TABLE, Str::random() local table name

        // ── Hardcoded secrets: false positives, vetted 2026-07-19 ──────────
        // All are `Authorization: Bearer ` headers concatenating a VARIABLE
        // (JWT/service key/OAuth token resolved at runtime) — nothing literal.
        '3e7cc8a9d986', // VerifySupabaseJwt:559
        'df2b69ca1ced', // SupabaseAdminService:169
        'a424dd547cbd', // SupabaseAdminService:190
        '041b628b341b', // AccountDeletionService:953
        'f810d8fe0c0f', // KickApiClient:54
        '2152b8323ce7', // TwitchApiClient:52

        // ── Command injection: false positives, vetted 2026-07-19 ──────────
        // SET statement_timeout/lock_timeout interpolate config-derived ints
        // (DatabaseServiceProvider:43-47), and PDO::exec is SQL, not a shell.
        'b4adc742e4c3',
        'c6e9e9ec265f',

        // ── Debug functions: false positive ────────────────────────────────
        '5dd3ec775690', // ScanWebsiteCommand:31 — $this->info() is normal artisan output
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Scan Paths
    |--------------------------------------------------------------------------
    |
    | Paths relative to the project root that file-based checks should skip.
    | Useful for mounted data directories or folders with different ownership
    | that are not part of your application source.
    |
    | Built-in exclusions (vendor/, node_modules/, storage/, …) always apply;
    | entries here are merged on top of those defaults.
    |
    */

    'exclude_paths' => [
        // 'storage/app/mounted-data',
        // 'data/external',
    ],

];
