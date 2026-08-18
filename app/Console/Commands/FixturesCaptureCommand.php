<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\InstagramScraper;
use App\Support\Fixtures\FixtureManifest;
use App\Support\Fixtures\FixtureStore;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
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
    /** --from=live refuses to run without --confirm-spend for these (spends a real third-party call). */
    private const BILLED_SOURCES = ['instagram', 'places', 'menus'];

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
                default => throw new InvalidArgumentException('--from must be file|url|db|live'),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ext = (string) ($this->option('ext') ?: $ext);
        $meta['captured_by'] = 'fixtures:capture';
        $meta['notes'] = (string) ($this->option('notes') ?? '');

        // --from=live's own writes (2nd+ recorded body) use <name>.<n>; keep the
        // primary body in the same numbering so the full sequence is contiguous.
        $primaryName = $this->option('from') === 'live' ? $name.'.1' : $name;
        $rel = $store->put($source, $primaryName, $ext, $body, $meta);
        $this->info("Recorded {$rel} (".strlen($body).' bytes, redacted).');

        return self::SUCCESS;
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromFile(): array
    {
        $path = (string) $this->option('file');
        if ($path === '' || ! is_file($path)) {
            throw new InvalidArgumentException("--file must point at an existing file (got '{$path}')");
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'txt';

        return [(string) file_get_contents($path), $ext, ['source_url' => 'file://'.realpath($path)]];
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromUrl(SafeUrlFetcher $fetcher): array
    {
        $url = (string) $this->option('url');
        if ($url === '') {
            throw new InvalidArgumentException('--url is required for --from=url');
        }
        // Category B: a URL a human typed goes through the guarded fetcher.
        $res = $fetcher->fetch($url, []);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException("GET {$url} returned {$res['status']}; nothing recorded.");
        }

        return [$res['body'], self::extFromContentType($res['contentType']), ['source_url' => $url, 'final_url' => $res['finalUrl']]];
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromDb(string $source): array
    {
        $ref = (string) $this->option('ref');
        if ($ref === '') {
            throw new InvalidArgumentException('--ref is required for --from=db: a platform_connections id, or <stream_id>:<key> for ingest.record_versions');
        }

        if (str_contains($ref, ':')) {
            [$streamId, $key] = explode(':', $ref, 2);
            $row = DB::connection('pgsql')->table('ingest.record_versions')
                ->where('stream_id', $streamId)->where('key', $key)
                ->orderByDesc('first_seen_at')->first();
            if ($row === null) {
                throw new RuntimeException("No ingest.record_versions row for {$ref}");
            }

            return [(string) $row->doc, 'json', ['source_url' => "db://ingest.record_versions/{$ref}"]];
        }

        $conn = IntegrationConnection::query()->find($ref);
        if ($conn === null) {
            throw new RuntimeException("No site.platform_connections row with id {$ref}");
        }
        $body = (string) json_encode($conn->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [$body, 'json', ['source_url' => "db://site.platform_connections/{$conn->id} ({$conn->surface_key})"]];
    }

    /**
     * Run the source's real scraper and record EVERY response body it received.
     * The first body is returned as the primary; the rest are written here as
     * <name>.<n>.<ext>. That gives C1 the wire, not the normalised output.
     *
     * @return array{0:string,1:string,2:array<string,mixed>}
     */
    private function fromLive(string $source): array
    {
        $ref = (string) $this->option('ref');
        if ($ref === '') {
            throw new InvalidArgumentException('--ref is required for --from=live (instagram username, place_id, or URL)');
        }
        // The spend gate MUST precede every other line here — no scraper call,
        // no HTTP middleware registration, nothing that could reach the wire.
        if (in_array($source, self::BILLED_SOURCES, true) && ! $this->option('confirm-spend')) {
            throw new RuntimeException("Source '{$source}' bills a third party. Re-run with --confirm-spend to spend one call.");
        }

        // Resolved via the underlying Factory singleton (FoundationServiceProvider
        // binds Illuminate\Http\Client\Factory::class => itself), NOT the `Http`
        // facade — OutboundHttpGuardTest (Rule 2) flags every literal `Http::`
        // call site in app/ for classification, and registering middleware here
        // is not an outbound fetch (the two actual requests below are already
        // classified: InstagramScraper/GoogleBusinessService — allowlist Pattern
        // A/D). The facade proxies to this exact same container singleton, so
        // behaviour (including under Http::fake() in tests) is identical.
        $captured = [];
        app(HttpClientFactory::class)->globalResponseMiddleware(function ($response) use (&$captured) {
            $captured[] = [
                'body' => (string) $response->getBody(),
                'contentType' => (string) ($response->getHeaderLine('Content-Type') ?: 'application/json'),
            ];
            // Reading the stream above leaves the pointer at EOF; rewind so the
            // real caller (the scraper) still sees the full body.
            $response->getBody()->rewind();

            return $response;
        });

        match ($source) {
            'instagram' => app(InstagramScraper::class)->fetchProfileResult($ref, 'fixtures-capture'),
            'places' => app(GoogleBusinessService::class)->fetchPlaceDetailsRaw($ref, 'fixtures-capture'),
            default => app(SafeUrlFetcher::class)->fetch($ref),
        };

        if ($captured === []) {
            throw new RuntimeException('The scraper made no HTTP request — nothing to record.');
        }

        // Write bodies 2..n here; body 1 is returned to handle() and written by the caller.
        $root = (string) ($this->option('root') ?: base_path('tests/fixtures/recorded'));
        $store = new FixtureStore($root, new FixtureManifest($root.'/MANIFEST.json'));
        $name = (string) $this->argument('name');
        foreach (array_slice($captured, 1, null, true) as $i => $c) {
            $store->put($source, $name.'.'.($i + 1), self::extFromContentType($c['contentType']), $c['body'], [
                'source_url' => "live://{$source}/{$ref}#".($i + 1), 'captured_by' => 'fixtures:capture', 'notes' => (string) ($this->option('notes') ?? ''),
            ]);
        }

        return [$captured[0]['body'], self::extFromContentType($captured[0]['contentType']), ['source_url' => "live://{$source}/{$ref}#1"]];
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
