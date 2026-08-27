<?php

use App\Jobs\Site\BuildSiteDocumentJob;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * T4 (2026-08-27 unclaimed-signup quality plan, issue 2): the site document
 * used to rebuild only on the 5-minute stale sweeper, so content landed and
 * the live site served its old (often EMPTY first-pass) state for minutes —
 * measured 4m09s between the OCR scan applying and the next build on the
 * st-ali rebuild, and ~2.5 min of empty-services window on simondoylehair.
 *
 * BuildState::bump() is the one choke point every content write passes
 * (observers + named raw-write seams), so it now also dispatches the build,
 * delayed a few seconds and coalesced by the job's own per-site uniqueness:
 * a write burst becomes ONE build starting seconds after the first write,
 * the builder's superseded-CAS loop covers writes landing mid-build, and the
 * sweeper stays as the net under anything that slips.
 */
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSectionsTables();
    Queue::fake();
});

it('bump dispatches a delayed, per-site-coalesced document build', function () {
    $siteId = (string) Str::uuid();

    BuildState::bump($siteId);
    // The burst: nine more writes in the same breath.
    foreach (range(1, 9) as $i) {
        BuildState::bump($siteId);
    }

    // Revision counted every write…
    $row = DB::table('site.site_build_state')->where('site_id', $siteId)->first();
    expect((int) $row->content_revision)->toBe(10);

    // …but the burst coalesced into ONE delayed build.
    Queue::assertPushed(BuildSiteDocumentJob::class, 1);
    Queue::assertPushed(BuildSiteDocumentJob::class, function (BuildSiteDocumentJob $job) use ($siteId) {
        return $job->siteId === $siteId
            && $job->channel === 'live'
            && $job->delay !== null;
    });
});

it('different sites each get their own build', function () {
    $a = (string) Str::uuid();
    $b = (string) Str::uuid();

    BuildState::bump($a);
    BuildState::bump($b);

    Queue::assertPushed(BuildSiteDocumentJob::class, 2);
});
