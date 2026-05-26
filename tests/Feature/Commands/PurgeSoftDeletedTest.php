<?php

use App\Models\Core\User\ServiceCategory;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupEnquiriesTable();
    setupServiceCategoriesTable();
    setupMediaTables();
    setupCustomersTable();
    setupServicesTable();
    setupBlocksTable();
    setupFeedbackTable();
});

// ─── Enquiry retention ────────────────────────────────────────────────────────

it('hard-deletes soft-deleted enquiries past the retention window', function () {
    $pro = createTenant('purge-enq-old');

    $enquiry = createEnquiryFor($pro, [
        'deleted_at' => now()->subDays(35)->toDateTimeString(),
    ]);

    Artisan::call('partna:purge-soft-deletes');

    expect(DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiry->id)->exists())->toBeFalse();
});

it('keeps soft-deleted enquiries within the retention window', function () {
    $pro = createTenant('purge-enq-recent');

    $enquiry = createEnquiryFor($pro, [
        'deleted_at' => now()->subDays(20)->toDateTimeString(),
    ]);

    Artisan::call('partna:purge-soft-deletes');

    expect(DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiry->id)->exists())->toBeTrue();
});

it('keeps non-deleted enquiries untouched', function () {
    $pro = createTenant('purge-enq-live');

    $enquiry = createEnquiryFor($pro);

    Artisan::call('partna:purge-soft-deletes');

    expect(DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiry->id)->exists())->toBeTrue();
});

// ─── ServiceCategory retention ────────────────────────────────────────────────

it('hard-deletes soft-deleted service categories past the retention window', function () {
    $pro = createTenant('purge-cat-old');

    $category = createServiceCategoryFor($pro, [
        'deleted_at' => now()->subDays(35)->toDateTimeString(),
    ]);

    Artisan::call('partna:purge-soft-deletes');

    expect(DB::connection('pgsql')->table('site.service_categories')->where('id', $category->id)->exists())->toBeFalse();
});

it('keeps soft-deleted service categories within the retention window', function () {
    $pro = createTenant('purge-cat-recent');

    $category = createServiceCategoryFor($pro, [
        'deleted_at' => now()->subDays(20)->toDateTimeString(),
    ]);

    Artisan::call('partna:purge-soft-deletes');

    expect(DB::connection('pgsql')->table('site.service_categories')->where('id', $category->id)->exists())->toBeTrue();
});

// ─── Failed SiteMedia cleanup ─────────────────────────────────────────────────

it('hard-deletes failed SiteMedia rows older than 7 days', function () {
    $pro = createTenant('purge-media-old-fail');
    $siteId = $pro->site->id;

    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_GALLERY,
        'path' => 'images/test.jpg',
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_FAILED,
        'is_active' => 1,
        'created_at' => now()->subDays(10)->toDateTimeString(),
        'updated_at' => now()->subDays(10)->toDateTimeString(),
    ]);

    Artisan::call('partna:purge-soft-deletes');

    expect(DB::connection('pgsql')->table('site.site_media')->where('id', $id)->exists())->toBeFalse();
});

it('keeps failed SiteMedia rows newer than 7 days', function () {
    $pro = createTenant('purge-media-new-fail');
    $siteId = $pro->site->id;

    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_GALLERY,
        'path' => 'images/test.jpg',
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_FAILED,
        'is_active' => 1,
        'created_at' => now()->subDays(5)->toDateTimeString(),
        'updated_at' => now()->subDays(5)->toDateTimeString(),
    ]);

    Artisan::call('partna:purge-soft-deletes');

    expect(DB::connection('pgsql')->table('site.site_media')->where('id', $id)->exists())->toBeTrue();
});

it('does not delete ready SiteMedia rows via the failed-media cleanup pass', function () {
    $pro = createTenant('purge-media-ready');
    $siteId = $pro->site->id;

    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_GALLERY,
        'path' => 'images/test.jpg',
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'created_at' => now()->subDays(30)->toDateTimeString(),
        'updated_at' => now()->subDays(30)->toDateTimeString(),
    ]);

    Artisan::call('partna:purge-soft-deletes');

    expect(DB::connection('pgsql')->table('site.site_media')->where('id', $id)->exists())->toBeTrue();
});
