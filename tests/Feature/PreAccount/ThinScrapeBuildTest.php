<?php

// A build that captures nothing must not be indistinguishable from one that
// captures everything. @crucibletattooco finished build_state='ready',
// failure_code=null on 2026-08-10 — byte-identical to the two builds either
// side of it that captured 12 posts each.
//
// It stays 'ready': the site still renders, and a genuinely sparse account must
// never be told its build failed. It just carries thin_scrape_at now, so a
// zero-post capture is queryable and re-runnable instead of silent.

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\SourceGeneratorRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    Storage::fake('media');
    Queue::fake();
    config([
        'services.apify.token' => 'test-token',
        'partna.instagram.actor' => 'apify~instagram-profile-scraper',
        'partna.media_disk' => 'media',
    ]);
});

function thinBuildFor(string $handle): PreAccountBuild
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram',
        'source_ref' => $handle,
        'source_ref_lc' => strtolower($handle),
        'built_via' => PreAccountBuild::VIA_SIGNUP,
    ]);
    $build->user()->associate($user);
    $build->save();

    return $build;
}

it('marks a build whose scrape stayed thin, without failing it', function () {
    $build = thinBuildFor('crucibletattooco');

    // Thin on the first call AND on the retry — the 2026-08-10 shape.
    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'crucibletattooco',
        'fullName' => 'Crucible Tattoo Co.',
        'followersCount' => 30042,
        'businessCategoryName' => 'None',
        'private' => false,
    ]], 201)]);

    (new GeneratePreAccountSiteJob($build->id, 'instagram'))
        ->handle(app(SourceGeneratorRegistry::class));

    $build->refresh();
    expect($build->build_state)->toBe(PreAccountBuild::STATE_READY)
        ->and($build->failure_code)->toBeNull()
        ->and($build->thin_scrape_at)->not->toBeNull();
});

it('leaves thin_scrape_at null on a healthy build', function () {
    $build = thinBuildFor('simondoylehair');

    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'simondoylehair',
        'fullName' => 'Simon Doyle',
        'followersCount' => 11065,
        'postsCount' => 365,
        'private' => false,
        'latestPosts' => array_fill(0, 12, [
            'shortCode' => 'x',
            'displayUrl' => 'https://cdn.example/p.jpg',
            'timestamp' => 1700000000,
        ]),
    ]], 201)]);

    (new GeneratePreAccountSiteJob($build->id, 'instagram'))
        ->handle(app(SourceGeneratorRegistry::class));

    $build->refresh();
    expect($build->build_state)->toBe(PreAccountBuild::STATE_READY)
        ->and($build->thin_scrape_at)->toBeNull();
});
