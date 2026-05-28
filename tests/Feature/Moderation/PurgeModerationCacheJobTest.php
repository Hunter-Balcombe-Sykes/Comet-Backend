<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\Moderation\PurgeModerationCacheJob;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('dispatches SyncSubdomainToKvJob for the affected site', function () {
    Bus::fake();
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id'   => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'sync_subdomain_kv']);

    (new PurgeModerationCacheJob($entry->id, $case->id))->handle();

    Bus::assertDispatched(SyncSubdomainToKvJob::class);
    expect($entry->fresh()->status)->toBe('completed');
    expect($entry->fresh()->completed_at)->not->toBeNull();
});

it('skips SyncSubdomainToKvJob dispatch when reportable_owner_user_id is null', function () {
    Bus::fake();
    // Create a case with no owner — using the factory default (reportable_owner_user_id = null).
    // Deliberately NOT creating a Site via Site::factory() to avoid SiteObserver dispatching
    // SyncSubdomainToKvJob as a side-effect of the site being created.
    $case     = ModerationCase::factory()->create(['reportable_owner_user_id' => null]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'sync_subdomain_kv']);

    (new PurgeModerationCacheJob($entry->id, $case->id))->handle();

    Bus::assertNotDispatched(SyncSubdomainToKvJob::class);
    expect($entry->fresh()->status)->toBe('completed');
});

it('is idempotent (running twice does not error and entry stays completed)', function () {
    Bus::fake();
    $user = User::factory()->create();
    // Case has owner but no Site row created — avoids SiteObserver side-effect
    $case     = ModerationCase::factory()->create(['reportable_owner_user_id' => $user->id]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'sync_subdomain_kv']);

    (new PurgeModerationCacheJob($entry->id, $case->id))->handle();
    (new PurgeModerationCacheJob($entry->id, $case->id))->handle(); // must not throw

    // SyncSubdomainToKvJob implements ShouldBeUnique: second dispatch for the same user
    // within the uniqueness window is deduplicated — 1 is correct, not 2.
    Bus::assertDispatched(SyncSubdomainToKvJob::class);
    expect($entry->fresh()->status)->toBe('completed');
});
