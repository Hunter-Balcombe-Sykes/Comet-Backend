<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Documents\DocumentTestCase;

beforeEach(function () {
    DocumentTestCase::boot();
    Storage::fake('media');
});

function seedDocumentRow(string $siteId, bool $isActive = true, ?string $deletedAt = null, string $pool = 'documents'): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $id,
        'site_id' => $siteId,
        'pool' => $pool,
        'media_type' => 'document',
        'path' => "documents/foo/{$id}/original.pdf",
        'alt_text' => 'Doc',
        'original_mime' => 'application/pdf',
        'original_filename' => 'schedule.pdf',
        'original_size_bytes' => 1234,
        'processing_state' => 'ready',
        'is_active' => $isActive ? 1 : 0,
        'sort_order' => 0,
        'deleted_at' => $deletedAt,
    ]);

    return $id;
}

function seedPublishedSite(bool $isPublished = true): string
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId, 'handle' => 'p', 'handle_lc' => 'p', 'display_name' => 'P',
        'first_name' => 'P', 'primary_email' => 'p@example.com', 'status' => 'active',
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'user_id' => $proId, 'subdomain' => 'p',
        'is_published' => $isPublished ? 1 : 0,
    ]);

    return $siteId;
}

it('returns 404 when document does not exist', function () {
    $fakeUuid = '00000000-0000-0000-0000-000000000000';
    $response = $this->get("/api/public/documents/{$fakeUuid}/download");
    expect($response->status())->toBe(404);
});

it('returns 404 when site is unpublished (draft)', function () {
    $siteId = seedPublishedSite(isPublished: false);
    $docId = seedDocumentRow($siteId);

    $response = $this->get("/api/public/documents/{$docId}/download");
    expect($response->status())->toBe(404);
});

it('returns 404 when document is soft-deleted', function () {
    $siteId = seedPublishedSite();
    $docId = seedDocumentRow($siteId, deletedAt: now()->toIso8601String());

    $response = $this->get("/api/public/documents/{$docId}/download");
    expect($response->status())->toBe(404);
});

it('returns 404 when document is not in the documents pool', function () {
    $siteId = seedPublishedSite();
    $docId = seedDocumentRow($siteId, pool: 'gallery');

    $response = $this->get("/api/public/documents/{$docId}/download");
    expect($response->status())->toBe(404);
});

it('returns 404 when document is inactive', function () {
    $siteId = seedPublishedSite();
    $docId = seedDocumentRow($siteId, isActive: false);

    $response = $this->get("/api/public/documents/{$docId}/download");
    expect($response->status())->toBe(404);
});

it('returns 302 redirect for a valid document on a published site', function () {
    $siteId = seedPublishedSite();
    $docId = seedDocumentRow($siteId);

    $response = $this->get("/api/public/documents/{$docId}/download");
    expect($response->status())->toBe(302);
    // The redirect Location will be the faked storage's signed URL.
    expect($response->headers->get('Location'))->toBeString();
});

it('route GET api/public/documents/{document}/download is registered', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('GET', $r->methods()) && $r->uri() === 'api/public/documents/{document}/download');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('PublicDocumentDownloadController');
});

it('route GET api/public/documents/{document}/download has public-site throttle', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('GET', $r->methods()) && $r->uri() === 'api/public/documents/{document}/download');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:public-site');
});
