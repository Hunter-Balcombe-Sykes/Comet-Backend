<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Services\PreAccount\BuildProgressReader;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
});

// A build with nothing outstanding: ready, content landed, workplace answered,
// no started stages, no media to mirror. The factory builds an INSTAGRAM
// source, and isDone() holds those open until the bio-link seeder reports --
// it always does, even to say "nothing to connect" -- so the landed platforms
// row is part of what settled means here, not decoration.
function outcomeSettledBuild(string $subdomain = 'janedoe'): PreAccountBuild
{
    [$user, $site, $build] = makeReadyBuild($subdomain);
    $build->forceFill(['content_filled_at' => now(), 'enriched_at' => now()])->save();
    outcomePlatformsLanded($build);

    return $build->fresh();
}

function outcomePlatformsLanded(PreAccountBuild $build): PreAccountBuildEvent
{
    $event = new PreAccountBuildEvent;
    $event->forceFill([
        'build_id' => $build->id,
        'stage' => PreAccountBuildEvent::STAGE_PLATFORMS,
        'status' => PreAccountBuildEvent::STATUS_LANDED,
        'label' => 'Links routed',
        'payload' => '{}',
        'created_at' => now(),
    ])->save();

    return $event;
}

/** @return list<PreAccountBuildEvent> */
function outcomeEventsFor(PreAccountBuild $build): array
{
    return PreAccountBuildEvent::query()->where('build_id', $build->id)->orderBy('created_at')->get()->all();
}

it('reports settled when everything is answered', function () {
    $reader = app(BuildProgressReader::class);
    $build = outcomeSettledBuild();

    expect($reader->outcome($build, outcomeEventsFor($build), ['mirrored' => 0, 'total' => 0, 'failed' => 0]))
        ->toBe(BuildProgressReader::OUTCOME_SETTLED);
});

it('reports pending while content has not landed', function () {
    $reader = app(BuildProgressReader::class);
    [$user, $site, $build] = makeReadyBuild(); // content_filled_at still null

    expect($reader->outcome($build->fresh(), [], ['mirrored' => 0, 'total' => 0, 'failed' => 0]))
        ->toBe(BuildProgressReader::OUTCOME_PENDING);
});

// The discriminating case: a build old enough that isDone() short-circuits on
// the ceiling must NOT read as settled — that is the whole point of the split.
it('reports ceiling for an unfinished build past CEILING_MINUTES', function () {
    $reader = app(BuildProgressReader::class);
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['created_at' => now()->subMinutes(BuildProgressReader::CEILING_MINUTES + 1)])->save();

    expect($reader->outcome($build->fresh(), [], ['mirrored' => 0, 'total' => 0, 'failed' => 0]))
        ->toBe(BuildProgressReader::OUTCOME_CEILING);
});

it('reports failed for a failed build, even inside the ceiling', function () {
    $reader = app(BuildProgressReader::class);
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED])->save();

    expect($reader->outcome($build->fresh(), [], ['mirrored' => 0, 'total' => 0, 'failed' => 0]))
        ->toBe(BuildProgressReader::OUTCOME_FAILED);
});

// A build that genuinely finished inside the ceiling but is READ after it must
// stay settled — the check order in outcome() is what protects this, and
// reversing it silently kills the email for every slow-but-successful build.
it('still reports settled when a finished build is read past the ceiling', function () {
    $reader = app(BuildProgressReader::class);
    $build = outcomeSettledBuild('lateread');
    $build->forceFill(['created_at' => now()->subMinutes(BuildProgressReader::CEILING_MINUTES + 1)])->save();

    expect($reader->outcome($build->fresh(), outcomeEventsFor($build), ['mirrored' => 0, 'total' => 0, 'failed' => 0]))
        ->toBe(BuildProgressReader::OUTCOME_SETTLED);
});

// isDone() must keep meaning exactly what it meant before: any terminal outcome.
it('keeps isDone true for every terminal outcome and false only for pending', function () {
    $reader = app(BuildProgressReader::class);
    $media = ['mirrored' => 0, 'total' => 0, 'failed' => 0];

    [$u1, $s1, $pending] = makeReadyBuild('pendingone');
    expect($reader->isDone($pending->fresh(), [], $media))->toBeFalse();

    $settled = outcomeSettledBuild('doneone');
    expect($reader->isDone($settled, outcomeEventsFor($settled), $media))->toBeTrue();

    [$u2, $s2, $failed] = makeReadyBuild('failedone');
    $failed->forceFill(['build_state' => PreAccountBuild::STATE_FAILED])->save();
    expect($reader->isDone($failed->fresh(), [], $media))->toBeTrue();

    [$u3, $s3, $aged] = makeReadyBuild('agedone');
    $aged->forceFill(['created_at' => now()->subMinutes(BuildProgressReader::CEILING_MINUTES + 1)])->save();
    expect($reader->isDone($aged->fresh(), [], $media))->toBeTrue();
});
