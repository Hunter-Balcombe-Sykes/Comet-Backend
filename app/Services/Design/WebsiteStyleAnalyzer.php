<?php

namespace App\Services\Design;

use App\Services\Design\Scan\BrandScanClient;
use App\Services\Design\Scan\BrandScanException;
use App\Services\Design\Scan\EvidenceConclusions;
use App\Services\Design\Scan\ScreenshotSampler;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Log;

/**
 * Analyzes an external website into brand-signal conclusions for the
 * design-kit preset factors (PreviousWebsiteFactor / OutsideWebsitesFactor)
 * and the logo auto-grab.
 *
 * v2 — RENDERED evidence: the page is loaded in real Chrome (partna-brand-scan
 * Worker → Cloudflare Browser Run) with an injected collector reading
 * getComputedStyle off the live DOM; screenshot pixels corroborate. All policy
 * (tiers, confidence, accent quorum) lives in EvidenceConclusions. The v1
 * static-CSS regex analysis is gone — it could not resolve var() chains, know
 * what actually paints the page, or tell a modal dimmer from the background
 * (see docs/superpowers/specs/2026-07-02-brand-scanner-v2-design.md §1).
 *
 * Snap-don't-copy: every signal except accent is a SEMANTIC tier (StyleTiers
 * maps tiers → curated literals); accent is the one raw value, behind a
 * multi-source quorum. Signals below MIN_CONFIDENCE are omitted — a weak read
 * degrades to "no contribution", never to a wrong value.
 *
 * Failures return the ok:false shape with a failure kind — stored by callers
 * so reconciliation never re-dispatches a broken URL in a loop, and "why did
 * this site not style" stays answerable (design:scan-website prints it all).
 */
class WebsiteStyleAnalyzer
{
    public const VERSION = 2;

    public function __construct(
        private readonly BrandScanClient $client,
        private readonly ScreenshotSampler $sampler,
        private readonly EvidenceConclusions $conclusions,
        private readonly SafeUrlFetcher $fetcher,
    ) {}

    /**
     * @return array{v:int, url:string, finalUrl:?string, ok:bool, mode:string, failure:?string, analyzedAt:string, accent:?array, signals:array<string,array>, logo:array{candidates:list<array>}, notes:list<string>}
     */
    public function analyze(string $url): array
    {
        // SSRF policy check before the URL leaves our systems (the Worker
        // fetches from Cloudflare's network, but the policy is ours).
        try {
            $this->fetcher->assertPublicUrl($url);
        } catch (SafeUrlException $e) {
            return $this->doc($url, ok: false, mode: 'none', failure: 'fetch', conclusions: [], finalUrl: null, note: $e->getMessage());
        }

        try {
            $snap = $this->client->snapshot($url);
        } catch (BrandScanException $e) {
            // OBS-6: a failed scan is an operational signal, not routine info.
            // PRIV-1: log the host only — the full URL (possibly carrying
            // query-string tokens/PII) never needs to leave the caller's scope.
            Log::warning('brand_scan.failed', ['url' => parse_url($url, PHP_URL_HOST), 'kind' => $e->kind, 'message' => $e->getMessage()]);

            return $this->doc($url, ok: false, mode: 'none', failure: $e->kind, conclusions: [], finalUrl: null);
        }

        $evidence = $this->extractStash($snap['content']);
        $pixels = $this->sampler->summarize($snap['screenshot']);

        if ($evidence === null) {
            // Collector never ran (strict page CSP / script error) — degraded
            // pixels-only mode: bg can still conclude from the screenshot.
            $concluded = $this->conclusions->conclude(['page' => []], $pixels);
            $ok = $concluded['signals'] !== [];
            $result = $this->doc($url, $ok, 'pixels-only', 'csp', $concluded, null);
        } else {
            $concluded = $this->conclusions->conclude($evidence, $pixels);
            $finalUrl = is_string($evidence['url'] ?? null) ? $evidence['url'] : null;
            $result = $this->doc($url, true, 'rendered', null, $concluded, $finalUrl);
        }

        Log::info('brand_scan.scanned', [
            // PRIV-1: host only — avoid logging full URLs (query strings may
            // carry tokens/PII).
            'url' => parse_url($url, PHP_URL_HOST),
            'mode' => $result['mode'],
            'failure' => $result['failure'],
            'signals' => array_map(fn (array $s) => $s['tier'].'@'.$s['confidence'], $result['signals']),
            'accent' => $result['accent']['hex'] ?? null,
            'logo_candidates' => count($result['logo']['candidates']),
        ]);

        return $result;
    }

    /**
     * Pull the collector's JSON stash out of the rendered HTML. Null when the
     * collector never ran or its output is unparseable/truncated.
     *
     * @return array<string, mixed>|null
     */
    private function extractStash(string $html): ?array
    {
        if ($html === '' || ! preg_match('/data-partna-scan="([^"]*)"/', $html, $m)) {
            return null;
        }
        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);

        return (is_array($decoded) && ($decoded['ok'] ?? false) === true) ? $decoded : null;
    }

    /** @param array{signals?:array, accent?:?array, logo?:array, notes?:list<string>} $conclusions */
    private function doc(string $url, bool $ok, string $mode, ?string $failure, array $conclusions, ?string $finalUrl, ?string $note = null): array
    {
        $notes = $conclusions['notes'] ?? [];
        if ($note !== null) {
            $notes[] = $note;
        }

        return [
            'v' => self::VERSION,
            'url' => $url,
            'finalUrl' => $finalUrl,
            'ok' => $ok,
            'mode' => $mode,
            'failure' => $failure,
            'analyzedAt' => now()->toIso8601String(),
            'accent' => $conclusions['accent'] ?? null,
            'signals' => $conclusions['signals'] ?? [],
            'logo' => $conclusions['logo'] ?? ['candidates' => []],
            'notes' => array_slice($notes, 0, 8),
        ];
    }
}
