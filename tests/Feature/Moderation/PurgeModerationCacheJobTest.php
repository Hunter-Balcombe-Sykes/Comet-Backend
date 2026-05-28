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
});
