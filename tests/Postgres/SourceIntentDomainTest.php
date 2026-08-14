<?php

// #PARITY-1 §5.2. routing.source_intents carries four CHECKs the SQLite
// stand-in never enforced (state, block_reason, origin, confidence) — see
// tests/Pest.php's setupRoutingTables(). This binds each domain to real
// Postgres, extracting the CHECK clauses from the migration at runtime (same
// discipline as ContentKindDomainParityTest / IngestAnomalyKindDomainTest)
// rather than hand-copying them, so a future narrowing of any CHECK fails
// here first instead of live.
//
// `state` is driven by App\Routing\Verdict::intentState() (Place -> applied,
// Choose -> proposed, Hold -> blocked); 'dismissed' is written directly by
// SuggestionsController::dismiss(), 'superseded' is reserved but currently
// unwritten (grep confirms no literal 'superseded' assignment to this
// column — only in comments). `origin` flows through RoutingContext and has
// four known call sites today: RoutingController ('paste'), WebsiteImporter
// ('website_import'), LinkInBioImporter ('link_in_bio' | 'bio_harvest') and
// CommerceProbeJob ('commerce_probe') — StoreBrandSeeder defaults to 'paste'
// too. A call site with an out-of-domain literal is exactly the failure mode
// this test exists to catch (plan §5.2), and commerce_probe was one: it wrote
// a literal the CHECK did not carry, throwing 23514 on every probe that
// resolved a store, until 20260814100000 widened the domain. Same
// accept/reject-literal discipline as IngestAnomalyKindDomainTest.

use App\Routing\Verdict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

// See tests/Postgres/SectionShapeDomainTest.php's REPO_ROOT comment: these
// helpers must not depend on base_path()/expect() because a ->with() dataset
// closure runs during test collection, before the app container boots.
const MIGRATIONS_DIR = __DIR__.'/../../supabase/migrations';
const ROUTING_MIGRATION = MIGRATIONS_DIR.'/20260727120000_routing_schema.sql';

/** Extract the CREATE TABLE "routing"."source_intents" (...) block. */
function sourceIntentsTableBlock(): string
{
    $sql = (string) file_get_contents(ROUTING_MIGRATION);
    preg_match('/CREATE TABLE "routing"\."source_intents" \(.*?\n\);/s', $sql, $m);
    if (! isset($m[0])) {
        throw new RuntimeException('CREATE TABLE "routing"."source_intents" not found in the migration.');
    }

    return $m[0];
}

/**
 * The domain a column's CHECK declares TODAY, across superseding migrations.
 *
 * A later migration that DROPs and re-ADDs the constraint — as
 * 20260814100000_source_intents_allow_commerce_probe.sql does for `origin` —
 * leaves the original CREATE TABLE text untouched. Reading only that file
 * would therefore keep comparing a domain the database no longer has, staying
 * green while live and test drift apart. That is not hypothetical: it is the
 * exact failure mode convergence-log F9 records for
 * ContentKindDomainParityTest, which reads two migration files by name. So
 * take the newest ADD CONSTRAINT for the column if any migration supersedes
 * the inline declaration, and fall back to the inline one otherwise.
 */
function sourceIntentCheckDomain(string $column): array
{
    $col = preg_quote($column, '/');

    // Filename order is apply order, so the last match wins. Match to the
    // statement terminator and harvest literals, rather than trying to
    // balance the parens of `CHECK (origin = ANY (ARRAY[...]))`.
    $files = glob(MIGRATIONS_DIR.'/*.sql') ?: [];
    sort($files);

    $clause = null;
    foreach ($files as $file) {
        $sql = (string) file_get_contents($file);
        if (preg_match_all('/ADD\s+CONSTRAINT\s+source_intents_'.$col.'_check\b(.*?);/s', $sql, $m)) {
            $clause = (string) end($m[1]);
        }
    }

    if ($clause === null) {
        preg_match('/"'.$col.'"[^,]*?CHECK\s*\("'.$col.'"[^()]*?IN\s*\((.*?)\)\)/s', sourceIntentsTableBlock(), $m);
        if (! isset($m[1])) {
            throw new RuntimeException("CHECK domain for \"{$column}\" not found in routing.source_intents.");
        }
        $clause = $m[1];
    }

    preg_match_all("/'([a-z_]+)'/", $clause, $vals);

    return $vals[1];
}

beforeEach(function () {
    $pg = DB::connection('pgsql');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS routing');
    $pg->statement('DROP TABLE IF EXISTS routing.source_intent_scratch');

    $state = sourceIntentCheckDomain('state');
    $blockReason = sourceIntentCheckDomain('block_reason');
    $origin = sourceIntentCheckDomain('origin');

    $inList = fn (array $values) => implode(',', array_map(fn ($v) => "'{$v}'", $values));

    $pg->statement("CREATE TABLE routing.source_intent_scratch (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        state text NOT NULL DEFAULT 'proposed' CHECK (state IN ({$inList($state)})),
        block_reason text CHECK (block_reason IS NULL OR block_reason IN ({$inList($blockReason)})),
        origin text NOT NULL CHECK (origin IN ({$inList($origin)})),
        confidence smallint CHECK (confidence IS NULL OR (confidence BETWEEN 0 AND 100))
    )");
});

it('every non-null Verdict::intentState() output is in the state domain', function (Verdict $verdict) {
    $state = $verdict->intentState();
    if ($state === null) {
        // Note/Reject: writesIntent() is false, so SourceReconciler never
        // reaches the insert — assert the contract itself, not just skip.
        expect($verdict->writesIntent())->toBeFalse();

        return;
    }

    DB::connection('pgsql')->table('routing.source_intent_scratch')->insert(['state' => $state, 'origin' => 'paste']);

    expect(DB::connection('pgsql')->table('routing.source_intent_scratch')->where('state', $state)->exists())->toBeTrue();
})->with(Verdict::cases());

it('accepts the state literals written outside Verdict::intentState() (dismissed, superseded)', function (string $state) {
    // dismissed: app/Http/Controllers/Api/Routing/SuggestionsController.php:113
    // superseded: reserved, currently unwritten (grep finds it only in comments)
    DB::connection('pgsql')->table('routing.source_intent_scratch')->insert(['state' => $state, 'origin' => 'paste']);

    expect(DB::connection('pgsql')->table('routing.source_intent_scratch')->where('state', $state)->exists())->toBeTrue();
})->with(['dismissed', 'superseded']);

it('rejects a state outside the domain', function () {
    $thrown = null;
    try {
        DB::connection('pgsql')->table('routing.source_intent_scratch')->insert(['state' => 'archived', 'origin' => 'paste']);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
});

it('the state domain is exactly the 5 values the migration declares', function () {
    $domain = sourceIntentCheckDomain('state');
    sort($domain);

    expect($domain)->toBe(['applied', 'blocked', 'dismissed', 'proposed', 'superseded']);
});

it('accepts every block_reason literal actually written by the app', function (string $reason) {
    // conflict / cap_reached: app/Routing/SourceReconciler.php:63-70
    // below_threshold: app/Routing/PlacementPolicy.php:109,112 (Verdict::Choose)
    DB::connection('pgsql')->table('routing.source_intent_scratch')->insert(['origin' => 'paste', 'block_reason' => $reason]);

    expect(DB::connection('pgsql')->table('routing.source_intent_scratch')->where('block_reason', $reason)->exists())->toBeTrue();
})->with(['conflict', 'cap_reached', 'below_threshold']);

it('accepts NULL and every reserved-but-unwritten block_reason value', function () {
    $table = DB::connection('pgsql')->table('routing.source_intent_scratch');

    $table->insert(['origin' => 'paste', 'block_reason' => null]);
    expect($table->whereNull('block_reason')->exists())->toBeTrue();

    foreach (sourceIntentCheckDomain('block_reason') as $reason) {
        DB::connection('pgsql')->table('routing.source_intent_scratch')->insert(['origin' => 'paste', 'block_reason' => $reason]);
        expect(DB::connection('pgsql')->table('routing.source_intent_scratch')->where('block_reason', $reason)->exists())->toBeTrue();
    }
});

it('rejects a block_reason outside the domain', function () {
    $thrown = null;
    try {
        DB::connection('pgsql')->table('routing.source_intent_scratch')->insert(['origin' => 'paste', 'block_reason' => 'made_up']);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
});

it('accepts every origin literal actually written today', function (string $origin) {
    // paste:          app/Http/Controllers/Api/Routing/RoutingController.php:39,49
    //                 (also StoreBrandSeeder's default)
    // website_import: app/Routing/Importers/WebsiteImporter.php:63
    // link_in_bio, bio_harvest: app/Routing/Importers/LinkInBioImporter.php's
    //                 KINDS const, passed straight through as $context->origin
    // commerce_probe: app/Jobs/Platforms/CommerceProbeJob.php's ORIGIN const.
    //                 This one is the reason the test exists: it WAS a live
    //                 call site writing a literal the domain did not carry, so
    //                 every probe that resolved a store threw 23514 until
    //                 20260814100000 widened the CHECK.
    // A further call site to RoutingContext::forUser()/preAccountBuild() with
    // a typo'd or new literal is exactly that failure mode again — see the
    // domain set-equality test below for the reserved-but-unwritten remainder
    // (google_business, staff, reproject).
    DB::connection('pgsql')->table('routing.source_intent_scratch')->insert(['origin' => $origin]);

    expect(DB::connection('pgsql')->table('routing.source_intent_scratch')->where('origin', $origin)->exists())->toBeTrue();
})->with(['paste', 'website_import', 'link_in_bio', 'bio_harvest', 'commerce_probe']);

it('accepts the reserved-but-unwritten origin values', function (string $origin) {
    DB::connection('pgsql')->table('routing.source_intent_scratch')->insert(['origin' => $origin]);

    expect(DB::connection('pgsql')->table('routing.source_intent_scratch')->where('origin', $origin)->exists())->toBeTrue();
})->with(['google_business', 'staff', 'reproject']);

it('rejects an origin outside the domain', function () {
    $thrown = null;
    try {
        DB::connection('pgsql')->table('routing.source_intent_scratch')->insert(['origin' => 'manual_typo']);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
});

it('the origin domain is exactly the 8 values the migrations declare', function () {
    $domain = sourceIntentCheckDomain('origin');
    sort($domain);

    expect($domain)->toBe(['bio_harvest', 'commerce_probe', 'google_business', 'link_in_bio', 'paste', 'reproject', 'staff', 'website_import']);
});

it('accepts NULL and both boundary values of the confidence range', function (?int $confidence) {
    DB::connection('pgsql')->table('routing.source_intent_scratch')->insert(['origin' => 'paste', 'confidence' => $confidence]);

    if ($confidence === null) {
        expect(DB::connection('pgsql')->table('routing.source_intent_scratch')->whereNull('confidence')->exists())->toBeTrue();
    } else {
        expect(DB::connection('pgsql')->table('routing.source_intent_scratch')->where('confidence', $confidence)->exists())->toBeTrue();
    }
})->with([null, 0, 100]);

it('rejects a confidence outside the 0-100 range', function (int $confidence) {
    $thrown = null;
    try {
        DB::connection('pgsql')->table('routing.source_intent_scratch')->insert(['origin' => 'paste', 'confidence' => $confidence]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull("Expected confidence={$confidence} to be rejected.");
    expect($thrown->getCode())->toBe('23514');
})->with([-1, 101]);
