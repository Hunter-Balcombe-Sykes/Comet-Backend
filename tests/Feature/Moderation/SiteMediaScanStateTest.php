<?php

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('allows scanning and quarantined values for site_media.processing_state', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-specific schema test.');
    }

    $user = User::factory()->create();
    $site = DB::table('site.sites')->where('user_id', $user->id)->first()
        ?? tap(new stdClass, function ($s) use ($user) {
            $siteId = Str::uuid()->toString();
            DB::table('site.sites')->insert([
                'id' => $siteId,
                'user_id' => $user->id,
                'subdomain' => 'scan-test-'.Str::random(6),
                'skeleton_id' => 'skeleton-1',
                'settings' => '[]',
                'is_published' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $s->id = $siteId;
        });

    $mediaId = Str::uuid()->toString();
    DB::insert("INSERT INTO site.site_media (id, site_id, bucket, path, processing_state, scanned_at) VALUES (?, ?, 'public-assets', 'test/path.jpg', 'scanning', NULL)", [$mediaId, $site->id]);

    DB::update("UPDATE site.site_media SET processing_state = 'quarantined' WHERE id = ?", [$mediaId]);

    $row = DB::selectOne('SELECT processing_state, scanned_at FROM site.site_media WHERE id = ?', [$mediaId]);
    expect($row->processing_state)->toBe('quarantined');
    expect($row->scanned_at)->toBeNull();
})->group('postgres');

it('adds scanned_at nullable timestamp column', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('information_schema queries require PostgreSQL.');
    }

    $col = DB::selectOne(<<<'SQL'
        SELECT is_nullable FROM information_schema.columns
        WHERE table_schema = 'site' AND table_name = 'site_media' AND column_name = 'scanned_at'
    SQL);
    expect($col)->not->toBeNull();
    expect($col->is_nullable)->toBe('YES');
})->group('postgres');
