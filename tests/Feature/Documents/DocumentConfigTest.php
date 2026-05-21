<?php

it('registers documents as a section_block_type', function () {
    expect(config('partna.section_block_types'))->toContain('documents');
});

it('registers documents pool with max 1', function () {
    expect(config('partna.image_pools.documents'))->toMatchArray(['max' => 1]);
});

it('exposes POOL_DOCUMENTS and MEDIA_TYPE_DOCUMENT constants', function () {
    expect(\App\Models\Core\Site\SiteMedia::POOL_DOCUMENTS)->toBe('documents');
    expect(\App\Models\Core\Site\SiteMedia::MEDIA_TYPE_DOCUMENT)->toBe('document');
});

it('SectionVisibilityService rejects documents section when no document is uploaded', function () {
    \Tests\Feature\Documents\DocumentTestCase::boot();

    $proId = (string) \Illuminate\Support\Str::uuid();
    $siteId = (string) \Illuminate\Support\Str::uuid();

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId, 'handle' => 'p', 'display_name' => 'P',
        'primary_email' => 'p@example.com', 'status' => 'active',
        'professional_type' => 'professional',
    ]);
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'professional_id' => $proId, 'subdomain' => 'p', 'is_published' => 0,
    ]);

    [$canBeVisible, $reason] = app(\App\Services\Professional\SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'documents');

    expect($canBeVisible)->toBeFalse();
    expect($reason)->toContain('document');
});

it('SectionVisibilityService allows documents section when a document exists', function () {
    \Tests\Feature\Documents\DocumentTestCase::boot();

    $proId = (string) \Illuminate\Support\Str::uuid();
    $siteId = (string) \Illuminate\Support\Str::uuid();

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId, 'handle' => 'p', 'display_name' => 'P',
        'primary_email' => 'p@example.com', 'status' => 'active',
        'professional_type' => 'professional',
    ]);
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'professional_id' => $proId, 'subdomain' => 'p', 'is_published' => 0,
    ]);
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'site_id' => $siteId,
        'pool' => 'documents',
        'media_type' => 'document',
        'path' => 'documents/foo/bar/original.pdf',
        'alt_text' => 'Schedule',
        'original_mime' => 'application/pdf',
        'processing_state' => 'ready',
        'is_active' => 1,
    ]);

    [$canBeVisible] = app(\App\Services\Professional\SectionVisibilityService::class)
        ->checkVisibilityRequirements($proId, $siteId, 'documents');

    expect($canBeVisible)->toBeTrue();
});

it('SiteMedia accepts original_filename via mass assignment', function () {
    $media = new \App\Models\Core\Site\SiteMedia([
        'site_id' => (string) \Illuminate\Support\Str::uuid(),
        'pool' => 'documents',
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
    expect($media->pool)->toBe('documents');
    expect($media->media_type)->toBe('document');
});
