<?php

use App\Services\Http\SafeUrlFetcher;
use App\Support\Fixtures\FixtureManifest;

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
