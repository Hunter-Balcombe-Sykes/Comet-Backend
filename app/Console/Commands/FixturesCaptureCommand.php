<?php

namespace App\Console\Commands;

use App\Services\Http\SafeUrlFetcher;
use App\Support\Fixtures\FixtureManifest;
use App\Support\Fixtures\FixtureStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * Capture ONE real upstream response into tests/fixtures/recorded/ and register
 * it in MANIFEST.json (spec 2026-08-18-pipeline-assurance §5 A1).
 *
 *   --from=file  import a body you already have (a tinker dump, a curl save)
 *   --from=url   one GET through SafeUrlFetcher — HTML pages: websites, linkinbio, fresha, shop
 *   --from=db    (Task 4) copy a stored payload / ingest doc from the connected DB
 *   --from=live  (Task 4) run the real scraper and record every HTTP body it received
 *
 * PII is redacted at write (FixtureRedactor). Paid sources refuse --from=live
 * without --confirm-spend.
 */
class FixturesCaptureCommand extends Command
{
    protected $signature = 'fixtures:capture
                            {source : one of '.'instagram|places|fresha|linkinbio|websites|shop|media|menus'.'}
                            {name : lowercase [a-z0-9._-], e.g. linktree.mixed}
                            {--from=file : file|url|db|live}
                            {--file= : path to import (--from=file)}
                            {--url= : URL to fetch (--from=url)}
                            {--ref= : connection id / user id / stream key (--from=db) or scraper ref (--from=live)}
                            {--ext= : override the file extension (default inferred)}
                            {--notes= : free text stored in the manifest}
                            {--root= : corpus root (default tests/fixtures/recorded) — tests point this at a temp dir}
                            {--confirm-spend : required for --from=live on a billed source}';

    protected $description = 'Record one real upstream response into tests/fixtures/recorded/ with PII redacted and a manifest row.';

    public function handle(SafeUrlFetcher $fetcher): int
    {
        $source = (string) $this->argument('source');
        $name = (string) $this->argument('name');
        $root = (string) ($this->option('root') ?: base_path('tests/fixtures/recorded'));

        if (! in_array($source, FixtureStore::SOURCES, true)) {
            $this->error("Unknown source '{$source}'. Known: ".implode(', ', FixtureStore::SOURCES));

            return self::FAILURE;
        }

        $store = new FixtureStore($root, new FixtureManifest($root.'/MANIFEST.json'));

        try {
            [$body, $ext, $meta] = match ((string) $this->option('from')) {
                'file' => $this->fromFile(),
                'url' => $this->fromUrl($fetcher),
                'db' => $this->fromDb($source),
                'live' => $this->fromLive($source),
                default => throw new \InvalidArgumentException('--from must be file|url|db|live'),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ext = (string) ($this->option('ext') ?: $ext);
        $meta['captured_by'] = 'fixtures:capture';
        $meta['notes'] = (string) ($this->option('notes') ?? '');

        $rel = $store->put($source, $name, $ext, $body, $meta);
        $this->info("Recorded {$rel} (".strlen($body).' bytes, redacted).');

        return self::SUCCESS;
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromFile(): array
    {
        $path = (string) $this->option('file');
        if ($path === '' || ! is_file($path)) {
            throw new \InvalidArgumentException("--file must point at an existing file (got '{$path}')");
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'txt';

        return [(string) file_get_contents($path), $ext, ['source_url' => 'file://'.realpath($path)]];
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromUrl(SafeUrlFetcher $fetcher): array
    {
        $url = (string) $this->option('url');
        if ($url === '') {
            throw new \InvalidArgumentException('--url is required for --from=url');
        }
        // Category B: a URL a human typed goes through the guarded fetcher.
        $res = $fetcher->fetch($url, []);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new \RuntimeException("GET {$url} returned {$res['status']}; nothing recorded.");
        }

        return [$res['body'], self::extFromContentType($res['contentType']), ['source_url' => $url, 'final_url' => $res['finalUrl']]];
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromDb(string $source): array
    {
        throw new \RuntimeException('--from=db is not implemented yet (Task 4).');
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromLive(string $source): array
    {
        throw new \RuntimeException('--from=live is not implemented yet (Task 4).');
    }

    public static function extFromContentType(string $contentType): string
    {
        $ct = strtolower(trim(explode(';', $contentType)[0]));

        return match (true) {
            str_contains($ct, 'json') => 'json',
            str_contains($ct, 'html') => 'html',
            str_contains($ct, 'pdf') => 'pdf',
            str_contains($ct, 'jpeg') => 'jpg',
            str_contains($ct, 'png') => 'png',
            str_contains($ct, 'webp') => 'webp',
            str_contains($ct, 'xml') => 'xml',
            default => 'txt',
        };
    }
}
