<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Design\LogoAutoGrabber;
use App\Services\Design\LogoCandidates;
use App\Services\Media\ImageVariantService;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    setupPreAccountBuildsTable();
    Storage::fake(app(ImageVariantService::class)->resolvedDiskName());
});

/** @return array{User, Site} */
function lcandSignupBusiness(string $h, string $status = 'unclaimed'): array
{
    $user = User::factory()->create([
        'handle' => $h, 'handle_lc' => strtolower($h),
        'account_type' => 'business', 'sector' => 'restaurant',
        'status' => $status, 'auth_user_id' => null, 'primary_email' => null,
    ]);
    $site = Site::factory()->create(['user_id' => $user->id, 'subdomain' => $h]);
    $build = PreAccountBuild::factory()->make(['source_type' => 'instagram']); // built_via defaults to signup
    $build->user()->associate($user);
    $build->save();

    return [$user, $site];
}

function lcandRows(Site $site): array
{
    return DB::connection('pgsql')->table('site.logo_candidates')
        ->where('site_id', $site->id)->orderBy('created_at')->get()->all();
}

it('still collects after the signup claim while the walk is unfinished (2026-09-05)', function () {
    // The signup lane claims BEFORE the website scan runs; gating collect
    // mode on "unclaimed" auto-picked st_ali's logo into both slots and
    // left "Your logo" with nothing to offer.
    [$user, $site] = lcandSignupBusiness('lcandclaimed', 'active');
    expect($user->isUnclaimed())->toBeFalse()->and($user->isInSetup())->toBeTrue();

    $fake = UploadedFile::fake()->image('logo.png', 200, 200);
    Http::fake(['example.com/*' => Http::response(file_get_contents($fake->getRealPath()), 200, ['Content-Type' => 'image/png'])]);

    app(LogoAutoGrabber::class)->grabIfEmpty($user, $site, [
        ['kind' => 'icon', 'url' => 'https://example.com/icon-a.png', 'sizes' => ''],
    ]);

    expect(lcandRows($site))->toHaveCount(1)
        ->and(SiteMedia::query()->count())->toBe(0);
});

it('stores every slot-passing candidate on a sign-up business build and uploads nothing', function () {
    [$user, $site] = lcandSignupBusiness('lcandcollect');

    $fake = UploadedFile::fake()->image('logo.png', 200, 200);
    $png = file_get_contents($fake->getRealPath());
    Http::fake(['example.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

    $decisions = app(LogoAutoGrabber::class)->grabIfEmpty($user, $site, [
        ['kind' => 'icon', 'url' => 'https://example.com/icon-a.png', 'sizes' => ''],
        ['kind' => 'icon', 'url' => 'https://example.com/icon-b.png', 'sizes' => ''],
        ['kind' => 'icon', 'url' => 'https://example.com/icon-c.png', 'sizes' => ''],
    ]);

    $rows = lcandRows($site);
    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->pluck('slot')->unique()->all())->toBe(['square'])
        ->and(collect($rows)->pluck('state')->unique()->all())->toBe(['proposed'])
        ->and(SiteMedia::query()->count())->toBe(0)
        ->and(collect($decisions)->pluck('outcome')->filter(fn ($o) => str_starts_with($o, 'candidate-stored'))->count())->toBe(3);

    // Bytes really mirrored — promote must work when the source URL rots.
    $disk = Storage::disk(app(ImageVariantService::class)->resolvedDiskName());
    foreach ($rows as $row) {
        expect($disk->exists((string) $row->storage_path))->toBeTrue();
    }
});

it('promote turns the chosen candidate into the slot singleton and settles the siblings', function () {
    [$user, $site] = lcandSignupBusiness('lcandpromote');

    $fake = UploadedFile::fake()->image('logo.png', 200, 200);
    $png = file_get_contents($fake->getRealPath());
    $store = app(LogoCandidates::class);
    expect($store->store($site, 'square', 'https://example.com/a.png', $png, 'image/png', 60, 200, 200))->toBeTrue()
        ->and($store->store($site, 'square', 'https://example.com/b.png', $png, 'image/png', 55, 200, 200))->toBeTrue();

    $rows = lcandRows($site);
    $chosen = (string) $rows[1]->id;

    $uploads = Mockery::mock(MediaUploadService::class);
    $uploads->shouldReceive('uploadSingleton')->once()
        ->withArgs(fn ($pro, $s, $file, $purpose) => $purpose === SiteMedia::PURPOSE_LOGO_SQUARE
            && (string) $s->id === (string) $site->id)
        ->andReturn(new SiteMedia);

    $service = new LogoCandidates(app(ImageVariantService::class), $uploads);
    expect($service->promote($user, $site, $chosen))->toBeTrue();

    $states = collect(lcandRows($site))->keyBy('id')->map(fn ($r) => (string) $r->state);
    expect($states[$chosen])->toBe('promoted')
        ->and($states[(string) $rows[0]->id])->toBe('dismissed');
});

it('promote refuses a foreign or already-settled id', function () {
    [$user, $site] = lcandSignupBusiness('lcandforeign');
    [, $otherSite] = lcandSignupBusiness('lcandforeignb');

    // A REAL candidate of another site, bytes and all. A bare Str::uuid() is
    // absent, not foreign: promote() would refuse it at the `id` lookup and
    // never reach the site_id scope, so dropping that scope would not have
    // failed this test. The mirrored bytes matter for the same reason — a
    // missing object also returns false, which would mask the leak.
    // (UploadedFile::fake() unlinks on destruct, so hold it.)
    $fake = UploadedFile::fake()->image('logo.png', 200, 200);
    $png = file_get_contents($fake->getRealPath());
    expect(app(LogoCandidates::class)->store($otherSite, 'square', 'https://example.com/foreign.png', $png, 'image/png', 60, 200, 200))->toBeTrue();

    $foreign = lcandRows($otherSite)[0];
    $disk = Storage::disk(app(ImageVariantService::class)->resolvedDiskName());
    expect($disk->exists((string) $foreign->storage_path))->toBeTrue();

    $uploads = Mockery::mock(MediaUploadService::class);
    $uploads->shouldNotReceive('uploadSingleton');
    $service = new LogoCandidates(app(ImageVariantService::class), $uploads);

    expect($service->promote($user, $site, (string) $foreign->id))->toBeFalse()
        ->and($service->promote($user, $site, (string) Str::uuid()))->toBeFalse();

    // The foreign row is untouched — a refusal that still settled it would
    // burn another site's candidate.
    expect((string) lcandRows($otherSite)[0]->state)->toBe('proposed');
});

it('promote refuses a candidate of its own site that is already settled', function () {
    // The other half of the name above, which had never been exercised:
    // promote() also requires state = 'proposed', so a promoted id must not
    // be re-promotable into a second singleton upload.
    [$user, $site] = lcandSignupBusiness('lcandsettled');

    $fake = UploadedFile::fake()->image('logo.png', 200, 200);
    $png = file_get_contents($fake->getRealPath());
    expect(app(LogoCandidates::class)->store($site, 'square', 'https://example.com/a.png', $png, 'image/png', 60, 200, 200))->toBeTrue();
    $id = (string) lcandRows($site)[0]->id;

    $uploads = Mockery::mock(MediaUploadService::class);
    $uploads->shouldReceive('uploadSingleton')->once()->andReturn(new SiteMedia);
    $service = new LogoCandidates(app(ImageVariantService::class), $uploads);

    expect($service->promote($user, $site, $id))->toBeTrue()
        ->and((string) lcandRows($site)[0]->state)->toBe('promoted');

    // Second call: the `once()` above is the assertion — a re-promote would
    // upload a second singleton and fail the expectation.
    expect($service->promote($user, $site, $id))->toBeFalse();
});
