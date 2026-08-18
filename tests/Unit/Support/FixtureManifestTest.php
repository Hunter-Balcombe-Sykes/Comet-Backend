<?php

// tests/Unit/Support/FixtureManifestTest.php

use App\Support\Fixtures\FixtureManifest;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/fixman-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/websites', 0777, true);
    $this->manifest = new FixtureManifest($this->dir.'/MANIFEST.json');
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/websites/*') ?: []);
    if (is_file($this->dir.'/MANIFEST.json')) {
        unlink($this->dir.'/MANIFEST.json');
    }
    @rmdir($this->dir.'/websites');
    @rmdir($this->dir);
});

it('starts empty when the manifest file does not exist', function () {
    expect($this->manifest->entries())->toBe([]);
});

it('upserts an entry and persists it as sorted JSON', function () {
    $this->manifest->upsert('websites/b.html', ['sha256' => 'x', 'source_url' => 'https://b']);
    $this->manifest->upsert('websites/a.html', ['sha256' => 'y', 'source_url' => 'https://a']);

    $raw = json_decode((string) file_get_contents($this->dir.'/MANIFEST.json'), true);
    expect(array_keys($raw['entries']))->toBe(['websites/a.html', 'websites/b.html'])
        ->and($raw['version'])->toBe(1);
});

it('verify() reports missing files, hash mismatches and orphans', function () {
    file_put_contents($this->dir.'/websites/present.html', 'hello');
    file_put_contents($this->dir.'/websites/orphan.html', 'nobody registered me');
    $this->manifest->upsert('websites/present.html', ['sha256' => hash('sha256', 'DIFFERENT')]);
    $this->manifest->upsert('websites/gone.html', ['sha256' => hash('sha256', 'x')]);

    $problems = $this->manifest->verify($this->dir);

    expect($problems)->toContain('hash mismatch: websites/present.html')
        ->and($problems)->toContain('missing file: websites/gone.html')
        ->and($problems)->toContain('orphan file: websites/orphan.html');
});

it('verify() is empty when everything matches', function () {
    file_put_contents($this->dir.'/websites/ok.html', 'hello');
    $this->manifest->upsert('websites/ok.html', ['sha256' => hash('sha256', 'hello')]);

    expect($this->manifest->verify($this->dir))->toBe([]);
});
