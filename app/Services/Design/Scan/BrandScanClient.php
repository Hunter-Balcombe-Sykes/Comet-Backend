<?php

namespace App\Services\Design\Scan;

use App\Services\Http\SafeUrlFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Client for the partna-brand-scan Worker (Cloudflare Browser Run snapshot
 * behind a shared bearer secret — same auth pattern as the logo processor).
 *
 * Sends the target URL + the in-page collector script; receives the RENDERED
 * page HTML (carrying the collector's data-partna-scan evidence stash) and a
 * viewport PNG screenshot. All interpretation happens in EvidenceConclusions —
 * this client ships bytes.
 */
class BrandScanClient
{
    private const VIEWPORT_W = 1440;

    private const VIEWPORT_H = 1200;

    public function __construct(private readonly SafeUrlFetcher $urlFetcher) {}

    /**
     * Snapshot a URL through the Worker. Retries once with the laxer 'load'
     * wait strategy when 'networkidle2' times out (analytics-beacon sites
     * never go idle).
     *
     * @return array{content: string, screenshot: string} screenshot = raw PNG bytes ('' when absent)
     *
     * @throws BrandScanException
     * @throws \App\Services\Http\SafeUrlException
     */
    public function snapshot(string $url): array
    {
        $cfg = (array) config('partna.brand_scan', []);
        if (! ($cfg['enabled'] ?? false) || ($cfg['url'] ?? '') === '' || ($cfg['token'] ?? '') === '') {
            throw new BrandScanException('disabled', 'Brand scan disabled or unconfigured.');
        }

        // Cost + junk-URL pre-check, enforced regardless of caller: today's only
        // caller (WebsiteStyleAnalyzer::analyze) already validates the URL first,
        // but a future direct caller (CLI command, staff endpoint) must not be
        // able to ship an unvalidated URL to the Worker, and there's no point
        // spending a paid Browser Run on a private/invalid address. This is NOT
        // the authoritative SSRF boundary — it's TOCTOU/DNS-rebind-advisory only
        // and never sees the Worker's own redirect chain. The authoritative guard
        // belongs in the partna-brand-scan Worker itself (separate cross-repo
        // follow-up).
        $this->urlFetcher->assertPublicUrl($url);

        $collector = @file_get_contents(resource_path('design-scan/collector.js'));
        if (! is_string($collector) || trim($collector) === '') {
            throw new BrandScanException('error', 'collector.js missing from resources/design-scan.');
        }

        try {
            return $this->attempt($cfg, $url, $collector, 'networkidle2');
        } catch (BrandScanException $e) {
            if (! in_array($e->kind, ['timeout', 'fetch'], true)) {
                throw $e;
            }

            return $this->attempt($cfg, $url, $collector, 'load');
        }
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array{content: string, screenshot: string}
     */
    private function attempt(array $cfg, string $url, string $collector, string $waitUntil): array
    {
        try {
            $response = Http::withToken((string) $cfg['token'])
                ->timeout((int) ($cfg['timeout'] ?? 40))
                ->post(rtrim((string) $cfg['url'], '/').'/scan', [
                    'url' => $url,
                    'collectorJs' => $collector,
                    'width' => self::VIEWPORT_W,
                    'height' => self::VIEWPORT_H,
                    // In-browser navigation budget; the HTTP timeout above adds headroom on top.
                    'timeoutMs' => (int) ($cfg['page_timeout_ms'] ?? 25_000),
                    'waitUntil' => $waitUntil,
                ]);
        } catch (ConnectionException $e) {
            throw new BrandScanException('timeout', "Brand-scan worker unreachable: {$e->getMessage()}", $e);
        }

        if ($response->status() === 429) {
            throw new BrandScanException('quota', 'Browser Run quota exhausted (429).');
        }
        if (! $response->successful()) {
            throw new BrandScanException('fetch', "Brand-scan worker HTTP {$response->status()}: ".mb_substr($response->body(), 0, 200));
        }

        $data = $response->json();
        $result = is_array($data) ? ($data['result'] ?? null) : null;
        if (! is_array($data) || ($data['success'] ?? null) !== true || ! is_array($result)) {
            throw new BrandScanException('fetch', 'Brand-scan worker returned a malformed snapshot payload.');
        }

        $content = is_string($result['content'] ?? null) ? $result['content'] : '';
        $screenshot = '';
        if (is_string($result['screenshot'] ?? null) && $result['screenshot'] !== '') {
            $decoded = base64_decode($result['screenshot'], true);
            $screenshot = $decoded === false ? '' : $decoded;
        }

        if ($content === '' && $screenshot === '') {
            throw new BrandScanException('fetch', 'Snapshot carried neither content nor screenshot.');
        }

        return ['content' => $content, 'screenshot' => $screenshot];
    }
}
