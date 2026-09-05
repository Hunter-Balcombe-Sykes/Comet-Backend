<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Media\MirrorMediaAssetJob;
use App\Site\SitePublishState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// One seam for the ingest layer's cross-domain read of site.sites.is_published.
// Three states, not two: null ("no site row") is what keeps siteInSetup()
// answering false for a user who has no site at all.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Bus::fake();
});

it('reports true, false and null distinctly', function () {
    $published = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    $unpublished = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    $siteless = createTenant('sps-'.Str::lower(Str::random(6)))->id;

    DB::table('site.sites')->where('user_id', $published)->update(['is_published' => true]);
    DB::table('site.sites')->where('user_id', $unpublished)->update(['is_published' => false]);
    DB::table('site.sites')->where('user_id', $siteless)->delete();

    $state = app(SitePublishState::class);

    expect($state->isPublished($published))->toBeTrue()
        ->and($state->isPublished($unpublished))->toBeFalse()
        ->and($state->isPublished($siteless))->toBeNull();
});

it('issues one query for repeated reads of the same user', function () {
    // siteInSetup() was un-memoised and ran per dispatch call.
    $published = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    $unpublished = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    DB::table('site.sites')->where('user_id', $published)->update(['is_published' => true]);
    DB::table('site.sites')->where('user_id', $unpublished)->update(['is_published' => false]);

    $state = app(SitePublishState::class);

    // Prime both and assert they DIFFER: no database-free constant can satisfy
    // both, so the empty query log below means memoisation rather than a no-op.
    expect($state->isPublished($published))->toBeTrue()
        ->and($state->isPublished($unpublished))->toBeFalse();

    DB::enableQueryLog();
    expect($state->isPublished($published))->toBeTrue()
        ->and($state->isPublished($unpublished))->toBeFalse();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('memoises a null so a siteless user is not re-queried either', function () {
    $siteless = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    DB::table('site.sites')->where('user_id', $siteless)->delete();
    $withSite = createTenant('sps-'.Str::lower(Str::random(6)))->id;

    $state = app(SitePublishState::class);

    // Prove this instance reads for real before trusting its silence below —
    // a stub that always returned null would also log no queries.
    expect($state->isPublished($withSite))->toBeTrue()
        ->and($state->isPublished($siteless))->toBeNull();

    DB::enableQueryLog();
    expect($state->isPublished($siteless))->toBeNull();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('keeps a siteless user on the published mirror ordering', function () {
    // The null carve-out, end to end. No site row must NOT read as "in setup":
    // videos go first, per Item 9f.
    $userId = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    DB::table('site.sites')->where('user_id', $userId)->delete();

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:orderless-1', [
        'kind' => 'media',
        'headline' => null,
        'media' => [
            ['role' => 'cover', 'url' => 'https://scontent.cdninstagram.com/v/c.jpg', 'ref' => 'instagram:ORD1:0'],
            ['role' => 'video', 'url' => 'https://scontent.cdninstagram.com/v/v.mp4', 'ref' => 'instagram:ORD1:1'],
        ],
    ]);

    $order = collect(Bus::dispatched(MirrorMediaAssetJob::class))
        ->map(fn ($job) => $job->video)
        ->all();

    expect($order[0])->toBeTrue();
});

it('puts images first while the site is unpublished', function () {
    // The setup walk's media step is tiles of covers, and that is the screen
    // a new user is waiting on.
    $userId = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    DB::table('site.sites')->where('user_id', $userId)->update(['is_published' => false]);

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:setup-1', [
        'kind' => 'media',
        'headline' => null,
        'media' => [
            ['role' => 'video', 'url' => 'https://scontent.cdninstagram.com/v/v.mp4', 'ref' => 'instagram:SET1:0'],
            ['role' => 'cover', 'url' => 'https://scontent.cdninstagram.com/v/c.jpg', 'ref' => 'instagram:SET1:1'],
        ],
    ]);

    $order = collect(Bus::dispatched(MirrorMediaAssetJob::class))
        ->map(fn ($job) => $job->video)
        ->all();

    expect($order[0])->toBeFalse();
});
