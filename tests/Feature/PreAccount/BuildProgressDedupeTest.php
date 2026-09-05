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

it('drops a second STARTED only while the stage is open — a closed stage may start again (2026-09-05)', function () {
    $buildId = (string) Str::uuid();

    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_MENU, PreAccountBuildEvent::STATUS_STARTED, 'Reading your menu');
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_MENU, PreAccountBuildEvent::STATUS_STARTED, 'Reading your menu');
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_MENU, PreAccountBuildEvent::STATUS_LANDED, 'Menu: 13 dishes');
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_MENU, PreAccountBuildEvent::STATUS_STARTED, 'Reading your menu');

    $statuses = PreAccountBuildEvent::query()->where('build_id', $buildId)->orderBy('created_at')->orderBy('id')->pluck('status')->all();
    expect($statuses)->toBe(['started', 'landed', 'started']);
});

it('pairs a tokened STARTED with its own terminal and dedupes on the token alone', function () {
    $buildId = (string) Str::uuid();
    $token = [BuildProgress::TOKEN => 'instagram'];

    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_STARTED, 'Syncing Facebook');
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_STARTED, 'Syncing Instagram', $token);
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_STARTED, 'Syncing Instagram', $token);
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_STARTED, 'Looking for your store', [BuildProgress::TOKEN => 'store:abc']);
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_LANDED, 'Facebook synced');
    BuildProgress::note($buildId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_LANDED, 'Instagram synced', $token);

    $rows = PreAccountBuildEvent::query()->where('build_id', $buildId)->orderBy('created_at')->orderBy('id')->get();
    expect($rows->pluck('label')->all())->toBe(['Syncing Facebook', 'Syncing Instagram', 'Looking for your store', 'Facebook synced', 'Instagram synced'])
        ->and($rows->where('label', 'Instagram synced')->first()->payload)->toBe($token);
});
