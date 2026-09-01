<?php

use App\Jobs\Platforms\WebsiteGalleryScanJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\WebsiteScan\GalleryAutoGrabber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSiteMediaTable();
});

function wgsjUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('does nothing when the user no longer exists', function () {
    Http::fake();
    $site = Site::factory()->create();

    (new WebsiteGalleryScanJob((string) Str::uuid(), (string) $site->id, ['https://example.com/photo.jpg']))
        ->handle(app(GalleryAutoGrabber::class));

    Http::assertNothingSent();
});

it('does nothing when the site no longer exists', function () {
    Http::fake();
    $user = wgsjUser('wgsjnosite');

    (new WebsiteGalleryScanJob((string) $user->id, (string) Str::uuid(), ['https://example.com/photo.jpg']))
        ->handle(app(GalleryAutoGrabber::class));

    Http::assertNothingSent();
});

it('skips fetching entirely when the media pool already has an active photo', function () {
    $user = wgsjUser('wgsjexisting');
    $site = Site::factory()->for($user, 'user')->create();
    (new SiteMedia([
        'pool' => SiteMedia::POOL_CONTENT,
        'bucket' => 'test-bucket',
        'path' => 'images/existing.jpg',
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
    ]))->site()->associate($site)->save();

    // No Http::fake() — a real fetch attempt here would be a hard failure,
    // proving the skip gate ran before any candidate was touched.
    (new WebsiteGalleryScanJob((string) $user->id, (string) $site->id, ['https://example.com/photo.jpg']))
        ->handle(app(GalleryAutoGrabber::class));

    // The pre-existing row is still the only one — nothing was added.
    expect(SiteMedia::query()->where('site_id', (string) $site->id)->count())->toBe(1);
});
