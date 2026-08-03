<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Schema\Concerns\SeedsAuthUsers;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class, SeedsAuthUsers::class)->in(__FILE__);

it('allows scanning and quarantined values for site_media.processing_state', function () {
    $user = $this->seedAuthUser();

    try {
        $siteId = Str::uuid()->toString();
        DB::table('site.sites')->insert([
            'id' => $siteId,
            'user_id' => $user->id,
            'subdomain' => 'scan-test-'.Str::random(6),
            'architecture_id' => 'staple',
            'settings' => '[]',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mediaId = Str::uuid()->toString();
        DB::insert("INSERT INTO site.site_media (id, site_id, bucket, path, processing_state, scanned_at) VALUES (?, ?, 'public-assets', 'test/path.jpg', 'scanning', NULL)", [$mediaId, $siteId]);

        DB::update("UPDATE site.site_media SET processing_state = 'quarantined' WHERE id = ?", [$mediaId]);

        $row = DB::selectOne('SELECT processing_state, scanned_at FROM site.site_media WHERE id = ?', [$mediaId]);
        expect($row->processing_state)->toBe('quarantined');
        expect($row->scanned_at)->toBeNull();
    } finally {
        // No RefreshDatabase in this lane — persistent, shared DB. Deleting
        // the user CASCADEs site.sites, which CASCADEs site.site_media.
        $this->cleanupSeededUser($user);
    }
})->group('postgres');

it('adds scanned_at nullable timestamp column', function () {
    $col = DB::selectOne(<<<'SQL'
        SELECT is_nullable FROM information_schema.columns
        WHERE table_schema = 'site' AND table_name = 'site_media' AND column_name = 'scanned_at'
    SQL);
    expect($col)->not->toBeNull();
    expect($col->is_nullable)->toBe('YES');
})->group('postgres');
