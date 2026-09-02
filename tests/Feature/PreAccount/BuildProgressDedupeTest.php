<?php

use App\Models\Core\User\PreAccountBuildEvent;
use App\Services\PreAccount\BuildProgress;
use Illuminate\Support\Str;

beforeEach(function () {
    setupPreAccountBuildEventsTable();
});

it('keeps one landed row per one-shot stage and lets shop rows repeat', function () {
    $buildId = (string) Str::uuid();

    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_WORKPLACE, PreAccountBuildEvent::STATUS_STARTED, 'Checking 2 places');
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_WORKPLACE, PreAccountBuildEvent::STATUS_LANDED, 'Workplace: STUDIO.MJ');
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_WORKPLACE, PreAccountBuildEvent::STATUS_LANDED, 'Workplace: STUDIO.MJ | studio. not salon.');
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_SHOP, PreAccountBuildEvent::STATUS_LANDED, 'Synced your store: A');
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_SHOP, PreAccountBuildEvent::STATUS_LANDED, 'Synced your store: B');

    $rows = PreAccountBuildEvent::query()->where('build_id', $buildId)->orderBy('created_at')->get();
    expect($rows->where('stage', PreAccountBuildEvent::STAGE_WORKPLACE)->where('status', PreAccountBuildEvent::STATUS_LANDED)->pluck('label')->all())
        ->toBe(['Workplace: STUDIO.MJ']);
    expect($rows->where('stage', PreAccountBuildEvent::STAGE_SHOP)->count())->toBe(2);
});
