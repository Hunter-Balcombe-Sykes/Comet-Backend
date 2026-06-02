<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\EvidenceSnapshotService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupModerationCasesTable();
    setupModerationEvidenceTable();
});

it('captures a Site snapshot with stable content_hash', function () {
    $user = User::factory()->create(['display_name' => 'Joe Plumber', 'handle' => 'joeplumber', 'handle_lc' => 'joeplumber']);
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create(['reportable_type' => 'Site', 'reportable_id' => $site->id]);

    $service = app(EvidenceSnapshotService::class);
    $ev1 = $service->capture($case->id, 'Site', $site->id, null);
    $ev2 = $service->capture($case->id, 'Site', $site->id, null);

    expect($ev1->evidence_type)->toBe('content_snapshot');
    expect($ev1->payload)->toHaveKey('captured_at');
    expect($ev1->payload)->toHaveKey('site_id');
    expect($ev1->payload['site_id'])->toBe($site->id);

    // Same site contents → same hash even on a second snapshot
    expect($ev1->content_hash)->toBe($ev2->content_hash);
});

it('throws when the target is an unknown type', function () {
    $case = ModerationCase::factory()->create();
    expect(fn () => app(EvidenceSnapshotService::class)
        ->capture($case->id, 'Unicorn', '00000000-0000-0000-0000-000000000000', null)
    )->toThrow(\InvalidArgumentException::class, 'Unsupported snapshot target type: Unicorn');
});

it('payload is JSON-serializable (no recursion, no DateTime)', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create(['reportable_type' => 'Site', 'reportable_id' => $site->id]);

    $ev = app(EvidenceSnapshotService::class)->capture($case->id, 'Site', $site->id, null);
    $encoded = json_encode($ev->payload, flags: JSON_THROW_ON_ERROR);
    expect($encoded)->toBeString();
});
