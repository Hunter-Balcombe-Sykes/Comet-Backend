<?php

use App\Models\Moderation\NcmecSubmission;
use App\Services\Moderation\NcmecSubmissionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupNcmecSubmissionsTable();

    config([
        'partna.moderation.csam.ncmec_endpoint'      => 'https://hashmatching.api.missingkids.org/cybertip',
        'partna.moderation.csam.ncmec_api_key'       => 'test-key',
        'partna.moderation.csam.ncmec_esp_id'        => 'TEST-ESP',
        'partna.moderation.csam.ncmec_max_attempts'  => 5,
    ]);
});

it('successful submission writes ncmec_tip_id and marks submitted', function () {
    Http::fake([
        'hashmatching.api.missingkids.org/*' => Http::response([
            'tipId'  => 'NCMEC-TIP-001',
            'status' => 'received',
        ], 200),
    ]);

    $sub = NcmecSubmission::factory()->create();
    app(NcmecSubmissionService::class)->submit($sub);

    $sub->refresh();
    expect($sub->status)->toBe('submitted');
    expect($sub->ncmec_tip_id)->toBe('NCMEC-TIP-001');
    expect($sub->submitted_at)->not->toBeNull();
});

it('failed submission increments attempts and stores last_error', function () {
    Http::fake([
        'hashmatching.api.missingkids.org/*' => Http::response(['error' => 'down'], 503),
    ]);

    $sub = NcmecSubmission::factory()->create();
    try {
        app(NcmecSubmissionService::class)->submit($sub);
    } catch (\Throwable) {
        // expected
    }

    $sub->refresh();
    expect($sub->status)->toBe('failed');
    expect($sub->attempts)->toBe(1);
    expect($sub->last_error)->not->toBeNull();
});

it('escalates to manual_fallback_required after max attempts', function () {
    Http::fake([
        'hashmatching.api.missingkids.org/*' => Http::response(['error' => 'down'], 503),
    ]);

    $sub = NcmecSubmission::factory()->failed(attempts: 4)->create();
    try {
        app(NcmecSubmissionService::class)->submit($sub);
    } catch (\Throwable) {
        // expected
    }

    $sub->refresh();
    expect($sub->status)->toBe('manual_fallback_required');
});
