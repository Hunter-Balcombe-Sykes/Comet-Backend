<?php

use App\Jobs\DeleteMediaArtifactsJob;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('retries with exponential backoff so a transient R2 outage does not orphan files', function () {
    $job = new DeleteMediaArtifactsJob('media-id', 'videos/pro/media-id', 'gallery');

    // P1-08: a fixed 30s × 3-try window (~60s of waiting) is shorter than a
    // typical R2 blip. Exponential [60,300,900] across 4 tries tolerates ~21min
    // of downtime before failed() gives up — by which point the gdpr ledger
    // sweep is the safety net for any path still orphaned.
    expect($job->tries)->toBe(4)
        ->and($job->backoff)->toBe([60, 300, 900]);
});
