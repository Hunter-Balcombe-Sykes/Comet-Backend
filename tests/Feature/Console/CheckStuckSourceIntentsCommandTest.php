<?php

// Feature tests for `routing:stuck-intents` (LIFE-19): a BACKLOG alarm on
// routing.source_intents rows stuck 'proposed'/'blocked' past the age gate —
// deliberately aggregate, not per-row, because every reachable stuck state is
// a question waiting on a USER in their own suggestions inbox
// (SuggestionsController::index), not an operator page. DB-backed via the
// SQLite mirror (setupRoutingTables(), tests/Pest.php).

use App\Exceptions\Routing\StuckSourceIntentBacklogException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupRoutingTables();
    config()->set('partna.routing.intents.stuck_age_days', 14);
    config()->set('partna.routing.intents.stuck_alert_threshold', 500);
});

/** routing.source_intents row, defaulted to a long-stuck 'proposed' intent. */
function seedSourceIntent(array $overrides = []): string
{
    $id = $overrides['id'] ?? (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::table('routing.source_intents')->insert(array_merge([
        'id' => $id,
        'user_id' => (string) Str::uuid(),
        'surface_key' => 'shopify.store',
        'routing_class' => 'commerce',
        'identifier' => 'store-'.$id,
        'canonical_url' => null,
        'state' => 'proposed',
        'block_reason' => 'below_threshold',
        'conflicting_connection_id' => null,
        'connection_id' => null,
        'confidence' => 60,
        // 'import' is NOT in routing.source_intents' origin domain
        // (20260727120000_routing_schema.sql). This seeded an invalid row that
        // only passed because the SQLite stand-in had no CHECK — the exact
        // defect #PARITY-1 describes, caught by the mirror Unit I added.
        'origin' => 'website_import',
        'import_run_id' => null,
        'detector_id' => null,
        'catalog_digest' => null,
        'first_seen_at' => now()->subDays(90)->toDateTimeString(),
        'resolved_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $id;
}

it('below threshold does not report', function () {
    Exceptions::fake();
    seedSourceIntent();

    $this->artisan('routing:stuck-intents')->assertExitCode(0);

    Exceptions::assertNothingReported();
});

it('a handful of long-stuck intents does NOT page — the anti-fatigue invariant', function () {
    Exceptions::fake();
    foreach (range(1, 5) as $i) {
        seedSourceIntent();
    }

    $this->artisan('routing:stuck-intents')->assertExitCode(0);

    Exceptions::assertNothingReported();
});

it('above threshold reports and still exits 0', function () {
    Exceptions::fake();
    config()->set('partna.routing.intents.stuck_alert_threshold', 2);
    foreach (range(1, 3) as $i) {
        seedSourceIntent();
    }

    $this->artisan('routing:stuck-intents')->assertExitCode(0);

    Exceptions::assertReported(fn (StuckSourceIntentBacklogException $e) => $e->stuckCount === 3 && $e->threshold === 2);
});

it('intents inside the age gate are not counted', function () {
    Exceptions::fake();
    config()->set('partna.routing.intents.stuck_alert_threshold', 0);
    seedSourceIntent(['first_seen_at' => now()->subDays(2)->toDateTimeString()]);

    $this->artisan('routing:stuck-intents')->assertExitCode(0);

    Exceptions::assertNothingReported();
});

it('applied/dismissed/superseded intents are not counted', function () {
    Exceptions::fake();
    config()->set('partna.routing.intents.stuck_alert_threshold', 0);
    seedSourceIntent(['state' => 'applied', 'block_reason' => null]);
    seedSourceIntent(['state' => 'dismissed', 'block_reason' => null]);
    seedSourceIntent(['state' => 'superseded', 'block_reason' => null]);

    $this->artisan('routing:stuck-intents')->assertExitCode(0);

    Exceptions::assertNothingReported();
});

it('a blocked intent past the age gate is counted toward the backlog', function () {
    Exceptions::fake();
    config()->set('partna.routing.intents.stuck_alert_threshold', 0);
    seedSourceIntent(['state' => 'blocked', 'block_reason' => 'conflict']);

    $this->artisan('routing:stuck-intents')->assertExitCode(0);

    Exceptions::assertReported(fn (StuckSourceIntentBacklogException $e) => $e->stuckCount === 1);
});
