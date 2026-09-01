<?php

namespace App\Services\Platforms;

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\TranscriptNormalizer;
use Illuminate\Support\Facades\Log;

// Item 11e (2026-09-01): video → text, as fuel for the AI-enrichment layer
// (About generation, service/menu extraction from spoken content). The plan's
// most speculative item, so the whole family ships behind ONE cap of its own
// — partna.limits.scrapecreators.sources.transcripts — rather than riding the
// per-platform scrape caps: a transcript credit must never compete with the
// identity/content scrapes that build pages. An absent cap reads 0 and never
// claims, which is also the shipped state until the central config pass.
//
// One seam, four platforms: the endpoint map below is the only per-platform
// branching, and there is no fallback lane behind it (nothing else can turn
// video into text) — every miss returns null and the consumer simply enriches
// without transcript fuel. NO consumer is wired yet by design; Wave 4's
// central pass decides who drinks from this.
//
// Budget contract (Item 8 adapter notes): claim BEFORE the call, release on
// transport-null, keep the slot spent on billed husks — NotFound bills a
// credit as success:true, so the gate is payload shape, never HTTP status.
// Vendor limits absorbed here: IG/TikTok/FB only answer videos under 2
// minutes (longer ones come back as a husk → null); TikTok's paid AI
// fallback (+10 credits) is deliberately NOT requested.
class TranscriptFetcher
{
    private const SOURCE = 'transcripts';

    private const ENDPOINTS = [
        'instagram' => '/v2/instagram/media/transcript',
        'tiktok' => '/v1/tiktok/video/transcript',
        'youtube' => '/v1/youtube/video/transcript',
        'facebook' => '/v1/facebook/post/transcript',
    ];

    // Exact host allowlist per platform — a claim is only ever spent on a URL
    // that can possibly answer, and a crafted host (tiktok.com.evil.example)
    // must not aim our API key at an attacker-chosen lookup.
    private const HOSTS = [
        'instagram' => ['instagram.com'],
        'tiktok' => ['tiktok.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'facebook' => ['facebook.com', 'fb.watch'],
    ];

    public function __construct(
        private readonly ScrapeCreatorsClient $client,
        private readonly ScrapeCreatorsBudget $budget,
        private readonly TranscriptNormalizer $normalizer,
    ) {}

    /**
     * The spoken content of one public video, as prose. Shape:
     * TranscriptNormalizer — {text, language, source}, timing discarded.
     *
     * @param  string  $platform  instagram|tiktok|youtube|facebook
     * @param  string  $videoUrl  the video's public permalink
     * @return array{text: string, language: string|null, source: string}|null
     */
    public function fetch(string $platform, string $videoUrl, ?string $userId = null): ?array
    {
        $endpoint = self::ENDPOINTS[$platform] ?? null;
        $videoUrl = $this->acceptableUrl($platform, $videoUrl);
        if ($endpoint === null || $videoUrl === null || ! $this->client->enabled() || ! $this->budget->tryClaim(self::SOURCE)) {
            return null;
        }

        $query = ['url' => $videoUrl];
        if ($platform !== 'tiktok') {
            // Transcripts of a given video never change — the vendor-side
            // cache turns a repeat lookup into a free answer. TikTok's route
            // is the one of the four without this parameter.
            $query['cache_max_age'] = '30d';
        }

        $body = $this->client->get($endpoint, $query, $userId);
        if ($body === null) {
            $this->budget->release(self::SOURCE);

            return null;
        }

        $transcript = $this->normalizer->normalize($platform, $body);
        if ($transcript === null) {
            // Husk, no-speech, over-2-minutes, or shape drift — billed either
            // way, the slot stays spent. Permalinks are public, logged raw.
            Log::info('scrapecreators.transcript.unusable_shape', ['platform' => $platform, 'url' => $videoUrl]);

            return null;
        }

        return $transcript;
    }

    /** http(s) URL on the platform's own hosts → passed through untouched; anything else refuses before any claim. */
    private function acceptableUrl(string $platform, string $videoUrl): ?string
    {
        $videoUrl = trim($videoUrl);
        $parts = parse_url($videoUrl);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::HOSTS[$platform] ?? [] as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return $videoUrl;
            }
        }

        return null;
    }
}
