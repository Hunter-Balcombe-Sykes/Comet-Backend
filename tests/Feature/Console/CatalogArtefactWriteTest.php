<?php

// OBS-1 / OBS-8: `catalog:compile` and `routing:corpus` used to discard the
// return value of file_put_contents()/rename() — a disk-full or permission
// failure looked exactly like a clean compile (exit 0, "wrote N cases"). Both
// now go through AtomicArtefactWriter, which checks every step and throws.
//
// Not report()ed by design (see AtomicArtefactWriter's docblock): neither
// command is scheduled, so the observer is the terminal already watching
// stderr + a non-zero exit (a developer laptop or CI), not Nightwatch.

use Illuminate\Support\Facades\File;

/** An 0555 (no-write) directory. Root ignores permission bits, so skip there. */
function unwritableScratchDir(string $name): ?string
{
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        return null;
    }

    $dir = storage_path('framework/testing/unit-f-'.$name);
    File::ensureDirectoryExists($dir);
    chmod($dir, 0555);

    return $dir;
}

function cleanupScratchDir(string $dir): void
{
    chmod($dir, 0755);
    File::deleteDirectory($dir);
}

it('catalog:compile exits 1 when the destination directory is unwritable', function () {
    $dir = unwritableScratchDir('catalog-locked');
    if ($dir === null) {
        $this->markTestSkipped('running as root — permission bits are not enforced');
    }

    try {
        $this->artisan('catalog:compile', ['--out' => $dir.'/compiled.php'])->assertExitCode(1);

        expect(File::exists($dir.'/compiled.php.tmp'))->toBeFalse('a failed write must not leave a .tmp file behind');
    } finally {
        cleanupScratchDir($dir);
    }
});

it('routing:corpus exits 1 when the destination directory is unwritable', function () {
    $dir = unwritableScratchDir('corpus-locked');
    if ($dir === null) {
        $this->markTestSkipped('running as root — permission bits are not enforced');
    }

    try {
        $this->artisan('routing:corpus', ['--out' => $dir.'/corpus.php'])->assertExitCode(1);

        expect(File::exists($dir.'/corpus.php.tmp'))->toBeFalse('a failed write must not leave a .tmp file behind');
    } finally {
        cleanupScratchDir($dir);
    }
});

it('a failed catalog:compile write does not disturb an existing artefact at that path', function () {
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        $this->markTestSkipped('running as root — permission bits are not enforced');
    }

    // Write the placeholder BEFORE locking the directory — you cannot create
    // a NEW file (the .tmp) in a 0555 dir, but the pre-existing target file's
    // own bytes are what must survive untouched.
    $dir = storage_path('framework/testing/unit-f-catalog-preexisting');
    File::ensureDirectoryExists($dir);
    $path = $dir.'/compiled.php';
    File::put($path, '<?php return ["digest" => "pre-existing"];');
    $before = File::get($path);
    chmod($dir, 0555);

    try {
        $this->artisan('catalog:compile', ['--out' => $path])->assertExitCode(1);

        expect(File::get($path))->toBe($before, 'rename() was never reached, so the pre-existing artefact must be byte-identical');
    } finally {
        cleanupScratchDir($dir);
    }
});

it('a successful catalog:compile still exits 0 and writes the artefact', function () {
    $dir = storage_path('framework/testing/unit-f-catalog-ok');
    File::ensureDirectoryExists($dir);
    $path = $dir.'/compiled.php';

    try {
        $this->artisan('catalog:compile', ['--out' => $path])->assertExitCode(0);

        expect(File::exists($path))->toBeTrue();
        $artefact = require $path;
        expect($artefact)->toHaveKey('digest');
    } finally {
        File::deleteDirectory($dir);
    }
});
