<?php

use App\Models\Core\Site\SiteMedia;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Documents\DocumentTestCase;

it('registers documents as a section_block_type', function () {
    expect(config('partna.section_block_types'))->toContain('documents');
});

it('registers documents pool with max 1', function () {
    expect(config('partna.upload_limits.documents'))->toMatchArray(['max' => 1]);
});

it('exposes POOL_DOCUMENTS and MEDIA_TYPE_DOCUMENT constants', function () {
    expect(SiteMedia::USAGE_DOCUMENTS)->toBe('documents');
    expect(SiteMedia::MEDIA_TYPE_DOCUMENT)->toBe('document');
});

it('SectionVisibilityService rejects documents section when no document is uploaded', function () {
    DocumentTestCase::boot();

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId, 'handle' => 'p', 'handle_lc' => 'p', 'display_name' => 'P',
        'first_name' => 'P', 'primary_email' => 'p@example.com', 'status' => 'active',
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'user_id' => $proId, 'subdomain' => 'p', 'is_published' => 0,
    ]);

    [$canBeVisible, $reason] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'documents');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('document');
});

it('SectionVisibilityService allows documents section when a document exists', function () {
    DocumentTestCase::boot();

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId, 'handle' => 'p', 'handle_lc' => 'p', 'display_name' => 'P',
        'first_name' => 'P', 'primary_email' => 'p@example.com', 'status' => 'active',
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'user_id' => $proId, 'subdomain' => 'p', 'is_published' => 0,
    ]);
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'usage' => 'documents',
        'media_type' => 'document',
        'path' => 'documents/foo/bar/original.pdf',
        'alt_text' => 'Schedule',
        'original_mime' => 'application/pdf',
        'processing_state' => 'ready',
        'is_active' => 1,
    ]);

    [$canBeVisible] = app(SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'documents');

    expect($canBeVisible)->toBeTrue();
});

it('SiteMedia accepts original_filename via mass assignment', function () {
    $media = new SiteMedia([
        'site_id' => (string) Str::uuid(),
        'usage' => 'documents',
        'media_type' => 'document',
        'path' => 'documents/foo/bar/original.pdf',
        'alt_text' => 'Spring Schedule',
        'caption' => 'Updated monthly',
        'original_mime' => 'application/pdf',
        'original_size_bytes' => 123456,
        'original_filename' => 'schedule-spring-2026.pdf',
        'processing_state' => 'ready',
    ]);

    expect($media->original_filename)->toBe('schedule-spring-2026.pdf');
    expect($media->usage)->toBe('documents');
    expect($media->media_type)->toBe('document');
});
