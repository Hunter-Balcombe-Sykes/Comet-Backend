<?php

// The §12 brand-asset plane, against real Postgres.
//
// This lane rather than the SQLite one for a structural reason, not a
// preference: tests/Pest.php ATTACHes schemas into SQLite and is already at
// that driver's hard cap of 10 attached databases, so `content` — where
// media_assets and brand_asset_refs live — cannot be mirrored there at all.
//
// What is pinned is the refusal surface. An auto-grabbed logo is untrusted
// input from a stranger's server, and every branch that decides NOT to keep
// bytes is load-bearing: §17's rule that an unsanitised scraped vector must
// never be served is a single `if` away from being untrue.

use App\Services\Brand\BrandAssetPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    Storage::fake(config('partna.media_disk'));

    $pg = DB::connection('pgsql');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');
    $pg->statement('DROP TABLE IF EXISTS content.brand_asset_refs CASCADE');
    $pg->statement('DROP TABLE IF EXISTS content.media_assets CASCADE');
    $pg->statement('DROP TABLE IF EXISTS core.users CASCADE');

    $pg->statement('CREATE TABLE core.users (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');
    $pg->statement('CREATE TABLE content.media_assets (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
        fingerprint text NOT NULL,
        source_url text,
        storage_path text,
        mime_type text,
        width integer,
        height integer,
        dims_confidence text,
        palette jsonb,
        variant_family text,
        blurhash text,
        mirror_attempts integer NOT NULL DEFAULT 0,
        mirror_last_attempt_at timestamptz,
        mirror_last_reason text,
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT media_assets_fingerprint_unique UNIQUE (user_id, fingerprint)
    )');
    $pg->statement("CREATE TABLE content.brand_asset_refs (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        connection_id uuid NOT NULL,
        role text NOT NULL CHECK (role IN ('logo_square', 'logo_full', 'favicon')),
        asset_id uuid REFERENCES content.media_assets (id) ON DELETE SET NULL,
        source_url text,
        attribution text,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )");
    $pg->statement('CREATE UNIQUE INDEX idx_brand_asset_refs_role
        ON content.brand_asset_refs (connection_id, role)');
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['content.brand_asset_refs', 'content.media_assets', 'core.users'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

function assetUser(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $id]);

    return $id;
}

function pngBytes(int $w = 800, int $h = 400): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, 12, 90, 200));
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

function servesLogo(string $body, string $contentType): void
{
    Http::fake(['*' => Http::response($body, 200, ['Content-Type' => $contentType])]);
}

it('turns a fetched logo into an owned asset attached to the connection', function () {
    $user = assetUser();
    $connection = (string) Str::uuid();
    servesLogo(pngBytes(), 'image/png');

    $assetId = app(BrandAssetPipeline::class)
        ->ingest($user, $connection, 'logo_full', 'https://example.com/logo.png', 'The Store');

    expect($assetId)->not->toBeNull();

    $asset = DB::connection('pgsql')->table('content.media_assets')->where('id', $assetId)->first();
    expect($asset->mime_type)->toBe('image/webp')
        // Decoded here, not read off a header someone else wrote.
        ->and($asset->dims_confidence)->toBe('measured');

    $ref = DB::connection('pgsql')->table('content.brand_asset_refs')->where('connection_id', $connection)->first();
    expect($ref->role)->toBe('logo_full')
        ->and($ref->asset_id)->toBe($assetId)
        // Attribution and source travel with the ref: a takedown request names
        // a URL, not an asset id.
        ->and($ref->source_url)->toBe('https://example.com/logo.png')
        ->and($ref->attribution)->toBe('The Store');
});

it('caps the stored variant rather than keeping a 4000px logo', function () {
    $user = assetUser();
    servesLogo(pngBytes(4000, 2000), 'image/png');

    $assetId = app(BrandAssetPipeline::class)
        ->ingest($user, (string) Str::uuid(), 'logo_full', 'https://example.com/big.png');

    $asset = DB::connection('pgsql')->table('content.media_assets')->where('id', $assetId)->first();
    expect($asset->width)->toBe(512)
        ->and($asset->height)->toBe(256);
});

it('refuses an unsanitised scraped vector', function () {
    // §17. The one thing that must never happen is serving an SVG we grabbed
    // off a stranger's site without the sanitising container having seen it.
    $user = assetUser();
    servesLogo('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml');

    $assetId = app(BrandAssetPipeline::class)
        ->ingest($user, (string) Str::uuid(), 'logo_full', 'https://example.com/logo.svg');

    expect($assetId)->toBeNull()
        ->and(DB::connection('pgsql')->table('content.media_assets')->count())->toBe(0);
});

it('refuses bytes that claim to be an image and are not', function () {
    // An image content-type over undecodable bytes is the shape a disguised
    // payload takes.
    $user = assetUser();
    servesLogo('MZ'.str_repeat("\x00", 512), 'image/png');

    $assetId = app(BrandAssetPipeline::class)
        ->ingest($user, (string) Str::uuid(), 'logo_full', 'https://example.com/not-a-logo.png');

    expect($assetId)->toBeNull();
});

it('refuses a content type it will not decode', function () {
    $user = assetUser();
    servesLogo('<html><body>not a logo</body></html>', 'text/html');

    $assetId = app(BrandAssetPipeline::class)
        ->ingest($user, (string) Str::uuid(), 'logo_full', 'https://example.com/page');

    expect($assetId)->toBeNull();
});

it('treats an unreachable logo as a refusal, not a crash', function () {
    $user = assetUser();
    Http::fake(['*' => Http::response('', 404)]);

    $assetId = app(BrandAssetPipeline::class)
        ->ingest($user, (string) Str::uuid(), 'logo_full', 'https://example.com/gone.png');

    expect($assetId)->toBeNull();
});

it('stores one asset for the same logo reached by two URLs', function () {
    // Content address, not URL address. Re-running the ingest after a CDN path
    // change must not duplicate the bytes.
    $user = assetUser();
    servesLogo(pngBytes(), 'image/png');

    $first = app(BrandAssetPipeline::class)->ingest($user, (string) Str::uuid(), 'logo_full', 'https://example.com/a.png');
    $second = app(BrandAssetPipeline::class)->ingest($user, (string) Str::uuid(), 'logo_full', 'https://example.com/b.png');

    expect($second)->toBe($first)
        ->and(DB::connection('pgsql')->table('content.media_assets')->count())->toBe(1);
});

it('replaces the asset in a role rather than accumulating refs', function () {
    // One asset per role per connection. A re-scan after a rebrand should
    // change what the slot points at, not leave two logos both claiming it.
    $user = assetUser();
    $connection = (string) Str::uuid();

    servesLogo(pngBytes(200, 200), 'image/png');
    app(BrandAssetPipeline::class)->ingest($user, $connection, 'logo_full', 'https://example.com/old.png');

    servesLogo(pngBytes(300, 300), 'image/png');
    $newAsset = app(BrandAssetPipeline::class)->ingest($user, $connection, 'logo_full', 'https://example.com/new.png');

    $refs = DB::connection('pgsql')->table('content.brand_asset_refs')->where('connection_id', $connection)->get();
    expect($refs)->toHaveCount(1)
        ->and($refs[0]->asset_id)->toBe($newAsset)
        ->and($refs[0]->source_url)->toBe('https://example.com/new.png');
});

it('keeps one connection\'s logo off another connection with the same platform', function () {
    // The reason the key is per-connection and not (site_id, purpose): the
    // site-singleton key collides the moment a user has two stores.
    $user = assetUser();
    $storeA = (string) Str::uuid();
    $storeB = (string) Str::uuid();

    servesLogo(pngBytes(200, 200), 'image/png');
    app(BrandAssetPipeline::class)->ingest($user, $storeA, 'logo_full', 'https://example.com/a.png');
    servesLogo(pngBytes(300, 300), 'image/png');
    app(BrandAssetPipeline::class)->ingest($user, $storeB, 'logo_full', 'https://example.com/b.png');

    expect(DB::connection('pgsql')->table('content.brand_asset_refs')->count())->toBe(2);
});
