<?php

use App\Jobs\Platforms\RefreshConnectionJob;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Drift guard: config('partna.http_fetch.refresh_budget_seconds') must stay
// meaningfully below RefreshConnectionJob::$timeout (120s) — see the config
// comment. If the budget ever meets/exceeds the job timeout, the job's own
// SIGKILL wins before PlatformRefresher's ensureOpen() deadline ever fires,
// making the budget moot. But "just below" is not enough: a refresh still does
// real non-fetch work AFTER the budget closes — the Cache::lock->block(5)
// acquisition, the projector sync() upserts, the model write + observer purge,
// the health notifier — so the budget must leave HEADROOM for that tail, or the
// SIGKILL still wins mid-persist. This guards the headroom, not just non-inversion.

it('refresh_budget_seconds stays far enough below RefreshConnectionJob::$timeout to leave a persistence tail', function () {
    // Minimum seconds the budget must leave beneath the job timeout for the
    // post-fetch persistence tail. 90s under a 120s timeout leaves 30s; a future
    // edit that pushed the budget to (say) 115s would pass a bare "< timeout"
    // check yet leave only 5s — this floor catches that. Local (not a top-level
    // const) so it stays out of the shared Pest global symbol table.
    $minTailHeadroom = 20;

    $timeout = (new ReflectionClass(RefreshConnectionJob::class))->getDefaultProperties()['timeout'];
    $budget = (int) config('partna.http_fetch.refresh_budget_seconds');

    expect($budget)->toBeLessThanOrEqual(
        $timeout - $minTailHeadroom,
        "refresh_budget_seconds ({$budget}s) must leave at least {$minTailHeadroom}s beneath ".
        "RefreshConnectionJob::\$timeout ({$timeout}s) for the post-fetch persistence tail (lock+block, projector ".
        "sync, model write+observer purge, health notifier) — otherwise the job's SIGKILL wins mid-persist and the ".
        'quiet budget-exhaustion path never runs.'
    );
});
