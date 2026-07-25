<?php

use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\ProEmailBrandResolver;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush();
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();
    setupMediaTables();
    setupBlocksTable();
});

function makeProSite(array $userAttrs = []): Site
{
    $user = User::factory()->create(array_merge([
        'display_name' => 'Jane Doe',
        'handle' => 'jane',
        'handle_lc' => 'jane',
    ], $userAttrs));

    $site = Site::factory()->create(['user_id' => $user->id]);

    // The production DB has trg_create_empty_design_kit which inserts an empty
    // row on site creation. SQLite tests don't run that trigger, so we seed it
    // manually to ensure the kit read finds a row.
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id]);

    return $site;
}

it('resolves a pro brand from display_name, handle and design kit', function () {
    $domain = (string) (config('partna.public_domain') ?: 'partna.au');
    $site = makeProSite();
    DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $site->id)
        ->update(['color_accent' => '#aa0000']);

    $brand = app(ProEmailBrandResolver::class)->forSite($site->id);

    expect($brand)->toBeInstanceOf(EmailBrand::class)
        ->and($brand->isPartna)->toBeFalse()
        ->and($brand->proName)->toBe('Jane Doe')
        ->and($brand->siteUrl)->toBe("https://jane.{$domain}")
        ->and($brand->palette->accent)->toBe('#aa0000')
        ->and($brand->palette->buttonBg)->toBe('#aa0000')   // derived
        ->and($brand->logoUrl)->toBeNull();
});

it('falls back to defaults when display_name/handle are missing', function () {
    $domain = (string) (config('partna.public_domain') ?: 'partna.au');
    // handle/handle_lc/display_name are NOT NULL in prod — '' is the representable
    // "missing" value here, and the resolver treats it identically to null
    // (trim(...) ?: 'the team', and $user->handle is falsy when empty).
    $site = makeProSite(['display_name' => '', 'handle' => '', 'handle_lc' => '']);

    $brand = app(ProEmailBrandResolver::class)->forSite($site->id);

    expect($brand->proName)->toBe('the team')
        ->and($brand->siteUrl)->toBe("https://{$domain}")
        ->and($brand->palette->accent)->toBe('#3a6efc');
});

it('uses a ready design-pool logo url when present', function () {
    // Configure the media disk URL so MediaVariant::getUrlAttribute() resolves correctly.
    config(['filesystems.disks.media.url' => 'https://media.partna.au']);

    $site = makeProSite();
    $media = new SiteMedia([
        'pool' => SiteMedia::POOL_DESIGN,
        'purpose' => SiteMedia::PURPOSE_LOGO_FULL,
        'is_active' => true,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'bucket' => 'media',
        'path' => 'design/logo.png',
    ]);
    $media->site()->associate($site);
    $media->save();
    $media->mediaVariants()->create([
        'variant_key' => 'md',
        'artifact_type' => 'webp',
        'disk' => 'media',
        'path' => 'design/logo-md.webp',
    ]);

    $brand = app(ProEmailBrandResolver::class)->forSite($site->id);

    expect($brand->logoUrl)->toBe('https://media.partna.au/design/logo-md.webp');
});

it('never leaks the pro\'s private account email as reply-to when no contact-block inbox is set', function () {
    $site = makeProSite(['primary_email' => 'jane-private@example.com']);

    // Active contact block with a blank notification_email — the one path
    // that used to fall through to the pro's private Supabase login email.
    $block = new Block([
        'block_type' => 'contact',
        'block_group' => Block::GROUP_SECTIONS,
        'is_active' => true,
        'settings' => ['notification_email' => ''],
    ]);
    $block->user()->associate($site->user);
    $block->site()->associate($site);
    $block->save();

    $brand = app(ProEmailBrandResolver::class)->forSite($site->id);

    expect($brand->replyToEmail)
        ->not->toBe('jane-private@example.com')
        ->toBeNull();
});

it('returns the partna brand for an unknown site', function () {
    $brand = app(ProEmailBrandResolver::class)->forSite('00000000-0000-0000-0000-000000000000');
    expect($brand->isPartna)->toBeTrue();
});

it('forget() clears the cached brand', function () {
    $site = makeProSite();
    $resolver = app(ProEmailBrandResolver::class);
    $resolver->forSite($site->id); // primes cache

    $resolver->forget($site->id);

    expect(Cache::get(CacheKeyGenerator::emailBrand($site->id)))->toBeNull();
});
