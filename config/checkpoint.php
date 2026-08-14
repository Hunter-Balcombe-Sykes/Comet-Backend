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
        // Disabled 2026-07-31. This check tests whether `safe-chain`/`socket` is on the
        // CURRENT MACHINE's PATH — a per-developer global npm install. No repo change can
        // satisfy it, so it warns forever on every CI runner.
        //
        // Disabled rather than hash-suppressed on purpose: its findings exist only while
        // the tool is ABSENT, so suppressing them would go stale the moment anyone
        // installed it, and CheckpointSuppressionStalenessTest would then fail the build
        // for someone improving their own setup. Machine-state-dependent findings are a
        // bad fit for content-addressed suppression.
        //
        // The repo-level equivalent is already enforced by the `supply-chain` CI job:
        // `composer audit`, `npm audit` over both package trees, and gitleaks across
        // full history.
        Checks\SupplyChainToolingCheck::class => false,
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
        // Re-minted 2026-08-03 (COV-LANE): both files moved to tests/Schema/ and had
        // their per-test savepointSuiteIsPostgres()/markTestSkipped() driver-check
        // stripped out (SchemaTestCase::setUp() already gates the whole class), which
        // shifted enough surrounding text to change these hashes. Re-vetted against the
        // moved files: the flagged construct in every case is still $table interpolated
        // straight from 'savepoint_probe_'/'handle_race_probe_' . Str::lower(Str::random(8))
        // — never request input — so the original justification stands unchanged.
        '6fa07c5b79e3', // SiteProvisioningSavepointTest — local const table name
        '704838cf3f48', // SiteProvisioningSavepointTest — local const table name
        '9b9ae54b8595', // PreAccountBuildHandleRaceTest — CREATE TEMP TABLE, local const table name
        'e9ec6b1ede76', // PreAccountBuildHandleRaceTest — DROP TABLE, local const table name
        'bd0226c951ba', // ItemSlugAllocatorSavepointTest — CREATE TEMP TABLE, Str::random() local table name
        '28488bff79f2', // ItemSlugAllocatorSavepointTest — DROP TABLE, Str::random() local table name
        // Vetted 2026-07-28: the content/ingest projection landed while CI was
        // red at PHPStan, so Checkpoint never ran on it. Every interpolated
        // identifier below is provably a closed set — a class constant's keys
        // or the literal array being iterated on the line above — never
        // request input. Values still travel via bindings.
        '320c82ee77b5', // ProjectionWriter:536 — upsertSingletonFacet early-returns unless $facet is a SINGLETON_FACETS key (:521)
        'bab8cea99a97', // IngestProjectCommand:142 — $collection from a literal foreach array
        '24cc5ece6372', // IngestProjectCommand:148 — $facet from a literal foreach array

        // Re-vetted 2026-07-30, after refreshItemCaches() moved — Checkpoint keys a
        // suppression by line CONTENT, so any edit above a finding silently reopens it.
        // Both loop sources are compile-time constants; no request input reaches the
        // table name, and item_id values travel as bindings via whereIn().
        //
        // Line labels re-read 2026-07-31 — the 07-30 pass wrote :785/:791, which have
        // since drifted to :992/:998 while the hashes survived untouched. That is the
        // documented behaviour (content-addressed, line-insensitive) and it is why the
        // `:NNN` in every comment here is a LABEL, not a key. Do not trust it without
        // grepping; do not "fix" a hash because its label looks wrong.
        //   ProjectionWriter:992  foreach (array_keys(self::SINGLETON_FACETS) as $facet)
        //   ProjectionWriter:998  foreach (['item_media','offers','item_tags','f_action'] as $collection)
        //
        // The two entries this pair REPLACED (`7b0f383edf44`/`a15fee82d15b`, commented
        // ProjectionWriter:675/:680) were left behind by that re-vet and sat dead until
        // 2026-07-31. CheckpointSuppressionStalenessTest now fails on exactly that.
        '657930f2f7f9', // ProjectionWriter:993 — $facet from array_keys(self::SINGLETON_FACETS) (:992)
        '3b8365f2b9b7', // ProjectionWriter:999 — $collection from the literal foreach array (:998)
        '677ef50b5100', // ProjectionWriterBatchingTest:128 — same $facet const in a test fixture

        // Vetted 2026-07-31: the rebuild-chunking rewrite added a third interpolated
        // call site in projectStream() and reddened CI. Provenance read at the current
        // lines, both closed sets of string literals:
        //   ProjectionWriter:791-795  $tables = ['item_media' => …, 'offers' => …, 'item_tags' => …]
        //                             then `foreach ($tables as $table => $rows)` at :805
        //   IngestProjectRebuildChunkingTest:162  foreach (['f_action','offers','item_tags', …] as $table)
        // No request input reaches the table name; item_ids and source_id travel as
        // bindings via whereIn()/where(), and the rows go through insert().
        '66f9a31cbb50', // ProjectionWriter:806 — $table from the literal $tables map (:791)
        'e835690783ba', // ProjectionWriter:812 — same $table, insert() inside the same loop
        '0f027c086763', // IngestProjectRebuildChunkingTest:163 — $table from the literal foreach array (:162)

        // ── Hardcoded secrets: false positives, vetted 2026-07-19 ──────────
        // All are `Authorization: Bearer ` headers concatenating a VARIABLE
        // (JWT/service key/OAuth token resolved at runtime) — nothing literal.
        '3e7cc8a9d986', // VerifySupabaseJwt:559
        'df2b69ca1ced', // SupabaseAdminService:169
        'a424dd547cbd', // SupabaseAdminService:190
        '041b628b341b', // AccountDeletionService:953
        'f810d8fe0c0f', // KickApiClient:54
        // Re-vetted 2026-07-28: DAST seeding generates a RANDOM per-run
        // password at runtime ('dast-seed-'.bin2hex(random_bytes(12))) — the
        // literal is a prefix label, not a secret.
        'f5d227992271', // scripts/dast/active/seed-identities.php:70
        '2152b8323ce7', // TwitchApiClient:52
        // TwitchConnector:138's Bearer-header entry retired with the connector
        // itself (Phase 1 de-sourced Twitch); the live-status TwitchApiClient
        // above is a different lane and keeps its suppression.

        // ── Command injection: false positives, vetted 2026-07-19 ──────────
        // SET statement_timeout/lock_timeout interpolate config-derived ints
        // (DatabaseServiceProvider:43-47), and PDO::exec is SQL, not a shell.
        'b4adc742e4c3',
        'c6e9e9ec265f',

        // ── Debug functions: false positives, vetted 2026-07-31 ───────────
        // All three use var_export()'s return-mode (`, true`) to render a value into a
        // string, which is its documented purpose — none of them writes to output, and
        // none is leftover debugging. Two generate PHP artefacts; one builds an
        // exception message where `null` and `'null'` must stay distinguishable.
        //
        // The prior entry here (`5dd3ec775690`, ScanWebsiteCommand:31) was deleted: that
        // file went away with the website-style-analysis pipeline in e66bb911, and
        // config/checkpoint.php was its last reference anywhere in the repo.
        //
        // NOTE — write the function name WITHOUT its parentheses below. DebugFunctionsCheck
        // matches /\b(var_dump|print_r|var_export|dd|dump|…)\s*\(/ and only skips lines whose
        // trimmed text starts with `//` or `*`. A trailing comment on a hash line is NOT
        // skipped, so `var_export()` written here flags config/checkpoint.php ITSELF —
        // the suppression comment becomes a new finding. Same shape as the GS-1 allowlist
        // trap documented in OutboundHttpGuardTest's header.
        'ea463d746bf7', // BuildsAutoSyncFindings:178 — var_export in return mode, into a report()ed exception message
        '172b812bfed7', // CatalogCompileCommand:143 — var_export in return mode, writes the generated catalog artefact
        '8099ce2de8e4', // RoutingCorpusCommand:101 — var_export in return mode, writes the generated corpus-negatives artefact

        // ── Deliberately NOT suppressed ────────────────────────────────────
        // Supply Chain Tooling is switched off in the `checks` map above rather than
        // suppressed here — see the reasoning there; its findings are machine-state,
        // not repo state, so they cannot be safely content-addressed.
        //
        // The Environment (APP_DEBUG / APP_ENV / APP_URL) and File Permissions (.env
        // mode) findings read the LOCAL .env, which is why they warn on a dev machine
        // and in CI (which copies .env.example). They are correct signals, and
        // suppressing them would blind the check to a genuine production
        // misconfiguration — the one case where it must fire. Leave them warning.
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
