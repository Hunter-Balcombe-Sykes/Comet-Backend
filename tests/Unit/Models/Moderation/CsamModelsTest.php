<?php

use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\NcmecSubmission;
use Tests\TestCase;

// Opt in to the full Laravel bootstrap — factories and DB interactions need facades.
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupModerationCasesTable();
    setupCsamQuarantineTable();
    setupNcmecSubmissionsTable();
});

it('CsamQuarantine factory creates a valid row', function () {
    $row = CsamQuarantine::factory()->create();
    expect($row->preservation_expires_at)->not->toBeNull();
    expect($row->r2_binary_deleted)->toBeFalse();
});

it('NcmecSubmission belongs to CsamQuarantine', function () {
    $qrow = CsamQuarantine::factory()->create();
    $sub  = NcmecSubmission::factory()->forQuarantine($qrow)->create();
    expect($sub->csam_quarantine_id)->toBe($qrow->id);
    expect($sub->status)->toBe('pending');
});

it('models use the moderation schema', function () {
    expect((new CsamQuarantine)->getTable())->toBe('moderation.csam_quarantine');
    expect((new NcmecSubmission)->getTable())->toBe('moderation.ncmec_submissions');
});

it('CsamQuarantine binaryDeleted state sets r2_binary_deleted true', function () {
    $row = CsamQuarantine::factory()->binaryDeleted()->create();
    expect($row->r2_binary_deleted)->toBeTrue();
    expect($row->r2_binary_deleted_at)->not->toBeNull();
});

it('NcmecSubmission submitted state sets status and tip id', function () {
    $qrow = CsamQuarantine::factory()->create();
    $sub  = NcmecSubmission::factory()->forQuarantine($qrow)->submitted('TIP-999')->create();
    expect($sub->status)->toBe('submitted');
    expect($sub->ncmec_tip_id)->toBe('TIP-999');
});
