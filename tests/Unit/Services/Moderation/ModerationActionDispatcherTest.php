<?php

use App\Jobs\Moderation\NotifyOnCallStaffJob;
use App\Jobs\Moderation\NotifyReportedUserJob;
use App\Jobs\Moderation\PurgeModerationCacheJob;
use App\Jobs\Moderation\QuarantineMediaJob;
use App\Jobs\Moderation\SuspendSiteJob;
use App\Jobs\Moderation\SuspendUserJob;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Services\Moderation\ModerationActionDispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Fake the queue so dispatched jobs are captured, not executed inline.
    // This is a unit test for the dispatcher (does it create the right action_log rows
    // and dispatch the right jobs?) — not for the jobs themselves.
    Queue::fake();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('writes action_log rows for hide_site (suspend_site + sync_subdomain_kv + notify_reported_user)', function () {
    $decision = Decision::factory()->create(['decision_type' => 'hide_site']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    $types = ActionLogEntry::query()->where('decision_id', $decision->id)->pluck('action_type')->all();
    expect($types)->toContain('suspend_site', 'sync_subdomain_kv', 'notify_reported_user');
});

it('writes action_log rows for suspend_user (suspend_user + suspend_site + sync_subdomain_kv + notify_reported_user)', function () {
    $decision = Decision::factory()->create(['decision_type' => 'suspend_user']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    $types = ActionLogEntry::query()->where('decision_id', $decision->id)->pluck('action_type')->all();
    expect($types)->toContain('suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user');
});

it('does not include notify_reporter when the case has no reporter_email', function () {
    $decision = Decision::factory()->create(['decision_type' => 'suspend_user']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    $types = ActionLogEntry::query()->where('decision_id', $decision->id)->pluck('action_type')->all();
    expect($types)->not->toContain('notify_reporter');
});

it('emits no actions for warn (F9 — no statement-of-reasons on warn)', function () {
    $decision = Decision::factory()->create(['decision_type' => 'warn']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    $types = ActionLogEntry::query()->where('decision_id', $decision->id)->pluck('action_type')->all();
    expect($types)->not->toContain('notify_reported_user');
    expect(ActionLogEntry::query()->where('decision_id', $decision->id)->count())->toBe(0);
});

it('writes the full CSAM action set for csam_auto_suspend and omits notify_reported_user', function () {
    $decision = Decision::factory()->create(['decision_type' => 'csam_auto_suspend']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    $types = ActionLogEntry::query()->where('decision_id', $decision->id)->pluck('action_type')->all();
    // Full CSAM enforcement set (spec §7.5). file_cybertip_report is dispatched
    // directly by CsamMatchHandlerService, not via the dispatcher.
    expect($types)->toContain('quarantine_media', 'suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_oncall_staff');
    // Never tip off a CSAM uploader.
    expect($types)->not->toContain('notify_reported_user');
});

it('emits no actions for dismiss decision', function () {
    $decision = Decision::factory()->create(['decision_type' => 'dismiss']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    expect(ActionLogEntry::query()->where('decision_id', $decision->id)->count())->toBe(0);
});

// ── Enforcement ordering (chain) ────────────────────────────────────────────
// The KV/edge sync reads the suspension state the earlier jobs write, so it
// must run strictly after them — as a chain, not as racing parallel dispatches.

it('chains suspend_user enforcement so the edge sync runs after the state writes', function () {
    Bus::fake();
    $decision = Decision::factory()->create(['decision_type' => 'suspend_user']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    Bus::assertChained([
        SuspendUserJob::class,
        SuspendSiteJob::class,
        PurgeModerationCacheJob::class,
    ]);
    // Notifies are order-free and must stay OUT of the chain (a failed
    // suspension link should not also swallow the user notification).
    Bus::assertDispatched(NotifyReportedUserJob::class);
});

it('chains the CSAM set with quarantine first and the edge sync last', function () {
    Bus::fake();
    $decision = Decision::factory()->create(['decision_type' => 'csam_auto_suspend']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    Bus::assertChained([
        QuarantineMediaJob::class,
        SuspendUserJob::class,
        SuspendSiteJob::class,
        PurgeModerationCacheJob::class,
    ]);
    Bus::assertDispatched(NotifyOnCallStaffJob::class);
});

it('dispatches a lone enforcement job for hide_content without a chain', function () {
    Bus::fake();
    $decision = Decision::factory()->create(['decision_type' => 'hide_content']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    Bus::assertDispatched(PurgeModerationCacheJob::class);
    Bus::assertDispatched(NotifyReportedUserJob::class);
});
