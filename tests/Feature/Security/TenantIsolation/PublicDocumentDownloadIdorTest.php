<?php

use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupMediaTables();
    Storage::fake('media');
});

it('refuses to download a document when its site does not match the request subdomain', function () {
    $victim = createTenant('victim-doc');
    $attacker = createTenant('attacker-doc');

    $docId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $docId,
        'site_id' => $victim->site->id,
        'usage' => SiteMedia::USAGE_DOCUMENTS,
        'path' => 'docs/secret.pdf',
        'original_filename' => 'secret.pdf',
        'is_active' => 1,
        'media_type' => 'document',
        'processing_state' => 'ready',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Attacker requests victim's document from their own subdomain
    $response = $this->withHeaders(['X-Site-Subdomain' => 'attacker-doc'])
        ->get("/api/public/documents/{$docId}/download");

    expect($response->status())->toBe(404);
});

it('allows download when subdomain matches the document site', function () {
    $owner = createTenant('owner-doc');

    $docId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $docId,
        'site_id' => $owner->site->id,
        'usage' => SiteMedia::USAGE_DOCUMENTS,
        'path' => 'docs/mine.pdf',
        'original_filename' => 'mine.pdf',
        'is_active' => 1,
        'media_type' => 'document',
        'processing_state' => 'ready',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $response = $this->withHeaders(['X-Site-Subdomain' => 'owner-doc'])
        ->get("/api/public/documents/{$docId}/download");

    // 302 redirect to presigned URL (Storage::fake provides it)
    expect($response->status())->toBe(302);
});
