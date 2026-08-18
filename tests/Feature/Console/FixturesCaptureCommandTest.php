<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Support\Fixtures\FixtureManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/fixcap-'.bin2hex(random_bytes(4));
    mkdir($this->root, 0777, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

it('imports a local file, redacts it, and registers it in the manifest', function () {
    $src = $this->root.'/in.html';
    file_put_contents($src, '<p>mail me jane@example.com</p>');

    $this->artisan('fixtures:capture', [
        'source' => 'websites', 'name' => 'acme.home',
        '--from' => 'file', '--file' => $src, '--root' => $this->root, '--notes' => 'unit',
    ])->assertExitCode(0);

    $written = (string) file_get_contents($this->root.'/websites/acme.home.html');
    expect($written)->toContain('[redacted-email]');

    $entry = (new FixtureManifest($this->root.'/MANIFEST.json'))->entries()['websites/acme.home.html'];
    expect($entry['sha256'])->toBe(hash('sha256', $written))
        ->and($entry['captured_by'])->toBe('fixtures:capture')
        ->and($entry['notes'])->toBe('unit');
});

it('fetches a URL through SafeUrlFetcher and infers the extension from content type', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->with('https://linktr.ee/acme', Mockery::type('array'))
        ->andReturn(['status' => 200, 'body' => '<html>links</html>', 'finalUrl' => 'https://linktr.ee/acme', 'contentType' => 'text/html; charset=utf-8', 'etag' => null, 'lastModified' => null]);
    app()->instance(SafeUrlFetcher::class, $fetcher);

    $this->artisan('fixtures:capture', [
        'source' => 'linkinbio', 'name' => 'linktree.acme',
        '--from' => 'url', '--url' => 'https://linktr.ee/acme', '--root' => $this->root,
    ])->assertExitCode(0);

    expect(is_file($this->root.'/linkinbio/linktree.acme.html'))->toBeTrue();
    $entry = (new FixtureManifest($this->root.'/MANIFEST.json'))->entries()['linkinbio/linktree.acme.html'];
    expect($entry['source_url'])->toBe('https://linktr.ee/acme');
});

it('refuses an unknown source with a non-zero exit', function () {
    $this->artisan('fixtures:capture', ['source' => 'nope', 'name' => 'x', '--from' => 'file', '--file' => '/dev/null', '--root' => $this->root])
        ->assertExitCode(1);
});

it('refuses --from=url on a non-2xx response and writes nothing', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn(['status' => 404, 'body' => '', 'finalUrl' => 'x', 'contentType' => 'text/html', 'etag' => null, 'lastModified' => null]);
    app()->instance(SafeUrlFetcher::class, $fetcher);

    $this->artisan('fixtures:capture', ['source' => 'websites', 'name' => 'gone', '--from' => 'url', '--url' => 'https://example.com/gone', '--root' => $this->root])
        ->assertExitCode(1);

    expect(glob($this->root.'/websites/*') ?: [])->toBe([]);
});

it('fixtures:verify exits 1 and names each problem when the corpus and manifest disagree', function () {
    mkdir($this->root.'/websites');
    file_put_contents($this->root.'/websites/orphan.html', 'x');

    $this->artisan('fixtures:verify', ['--root' => $this->root])
        ->expectsOutputToContain('orphan file: websites/orphan.html')
        ->assertExitCode(1);
});

it('fixtures:verify exits 0 on a consistent corpus', function () {
    $src = $this->root.'/in.html';
    file_put_contents($src, '<p>hi</p>');
    $this->artisan('fixtures:capture', ['source' => 'websites', 'name' => 'ok', '--from' => 'file', '--file' => $src, '--root' => $this->root])->assertExitCode(0);
    unlink($src);

    $this->artisan('fixtures:verify', ['--root' => $this->root])->assertExitCode(0);
});

it('--from=db copies a platform_connections payload by connection id', function () {
    setupUsersTable();
    setupSitesTable();
    $user = User::factory()->create();
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'surface_key' => 'instagram.profile', 'resource_id' => 'instagram',
        'payload' => ['fullName' => 'Jane', 'biography' => 'call 0412 345 678'],
    ]);

    $this->artisan('fixtures:capture', ['source' => 'instagram', 'name' => 'stored.jane', '--from' => 'db', '--ref' => (string) $conn->id, '--root' => $this->root])
        ->assertExitCode(0);

    $json = json_decode((string) file_get_contents($this->root.'/instagram/stored.jane.json'), true);
    expect($json['fullName'])->toBe('Jane')
        ->and($json['biography'])->toBe('call [redacted-phone]');
});

it('--from=db copies the newest ingest.record_versions doc for stream:key', function () {
    setupIngestTables();
    DB::connection('pgsql')->table('ingest.record_versions')->insert([
        ['stream_id' => 'st1', 'key' => 'k1', 'doc_hash' => 'h1', 'doc' => json_encode(['v' => 1]), 'first_seen_run' => 'r1', 'first_seen_at' => '2026-08-01 00:00:00', 'is_current' => false],
        ['stream_id' => 'st1', 'key' => 'k1', 'doc_hash' => 'h2', 'doc' => json_encode(['v' => 2]), 'first_seen_run' => 'r2', 'first_seen_at' => '2026-08-02 00:00:00', 'is_current' => true],
    ]);

    $this->artisan('fixtures:capture', ['source' => 'places', 'name' => 'ingest.k1', '--from' => 'db', '--ref' => 'st1:k1', '--root' => $this->root])
        ->assertExitCode(0);

    expect(json_decode((string) file_get_contents($this->root.'/places/ingest.k1.json'), true))->toBe(['v' => 2]);
});

it('--from=live refuses a billed source without --confirm-spend and makes no request', function () {
    Http::fake();

    $this->artisan('fixtures:capture', ['source' => 'instagram', 'name' => 'live.acme', '--from' => 'live', '--ref' => 'acme', '--root' => $this->root])
        ->expectsOutputToContain('--confirm-spend')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('--from=live records every HTTP body the scraper received, numbered', function () {
    // InstagramScraper::fetchProfileResult() makes exactly ONE round-trip on the
    // success path: a single POST to Apify's run-sync-get-dataset-items endpoint,
    // which returns the dataset items directly (no run-start/dataset-read split,
    // no status poll) — verified against app/Services/Platforms/InstagramScraper.php
    // lines 156/172-189. The response body is a top-level array of items.
    // isThinProfile() treats a numeric postsCount with no matching latestPosts
    // array as thin and the scraper spends a SECOND Apify call retrying it — so
    // the item needs a non-empty latestPosts to read as a healthy profile and
    // keep this test's request count at exactly one.
    Http::fake([
        'api.apify.com/*' => Http::response([[
            'username' => 'acme', 'fullName' => 'Acme Co', 'biography' => 'hi',
            'postsCount' => 1, 'latestPosts' => [['id' => 'p1']],
        ]], 201),
    ]);
    // The token is empty under phpunit.xml (APIFY_TOKEN=""), so set one for the run.
    config(['services.apify.token' => 'test-token']);

    $this->artisan('fixtures:capture', ['source' => 'instagram', 'name' => 'live.acme', '--from' => 'live', '--ref' => 'acme', '--root' => $this->root, '--confirm-spend' => true])
        ->assertExitCode(0);

    $files = array_map('basename', glob($this->root.'/instagram/live.acme.*.json') ?: []);
    sort($files);
    expect($files)->toBe(['live.acme.1.json']);
    expect(json_decode((string) file_get_contents($this->root.'/instagram/live.acme.1.json'), true)[0]['fullName'])->toBe('Acme Co');
});
