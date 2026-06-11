<?php

use App\Http\Resources\Moderation\CaseDetailResource;
use App\Http\Resources\Moderation\CaseResource;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\ModerationCase;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('CaseResource never exposes reporter_email', function () {
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'leak@example.com']);

    $array = (new CaseResource($case))->toArray(Request::create('/'));
    $json = json_encode($array);
    expect($json)->not->toContain('leak@example.com');
});

it('CaseDetailResource includes signals + evidence + decisions but never raw reporter_email', function () {
    $case = ModerationCase::factory()->create();
    $signal = CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'leak@example.com', 'reason_details' => 'visible']);
    Evidence::factory()->forCase($case)->create();

    $array = (new CaseDetailResource($case->load(['signals', 'evidence', 'decisions'])))
        ->toArray(Request::create('/'));
    $json = json_encode($array);

    expect($array)->toHaveKey('signals');
    expect($array)->toHaveKey('evidence');
    expect($json)->not->toContain('leak@example.com');
    expect($json)->toContain('visible');  // reason_details is safe to expose
});
