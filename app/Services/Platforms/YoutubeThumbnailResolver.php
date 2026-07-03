<?php

namespace App\Services\Platforms;

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\Concerns\JitteredTtl;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Resolves the best available thumbnail URL for YouTube videos.
 *
 * YouTube exposes several derived thumbnails per video. hqdefault.jpg always
 * exists but is 480×360 4:3 — modern 16:9 uploads get black letterbox bars baked
 * in. maxresdefault.jpg is 1280×720 true 16:9 but only exists once YouTube has
 * generated it (older / low-res videos lack it). This probes maxres and falls
 * back to hqdefault, hotlinking i.ytimg.com directly — no API key, no rehosting.
 *
 * The URLs are hardcoded to i.ytimg.com (never user input), so there is no SSRF
 * surface — plain Http is used rather than SafeUrlFetcher.
 */
class YoutubeThumbnailResolver
{
    use JitteredTtl;

    /** Short — a HEAD to i.ytimg.com is fast or it isn't worth waiting for. */
    private const TIMEOUT_SECONDS = 3;

    /** maxres availability is stable once a video is published, so cache long. */
    private const CACHE_DAYS = 30;

    private function maxresUrl(string $videoId): string
    {
        return "https://i.ytimg.com/vi/{$videoId}/maxresdefault.jpg";
    }

    private function hqUrl(string $videoId): string
    {
        return "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg";
    }

    /**
     * Resolve the best thumbnail URL for each given video id.
     *
     * For every id: a cached verdict is reused; otherwise maxresdefault.jpg is
     * HEAD-probed. 200 ⇒ the maxres URL; anything else (incl. failures/errors)
     * ⇒ the hqdefault.jpg baseline. Cache-misses are probed concurrently via a
     * single Http::pool round-trip. Never throws — a failed probe is a fallback.
     *
     * @param  array<int,string>  $videoIds
     * @return array<string,string> id ⇒ best thumbnail URL (an entry per requested id)
     */
    public function bestForMany(array $videoIds): array
    {
        $ids = array_values(array_unique(array_filter($videoIds)));
        if ($ids === []) {
            return [];
        }

        // 1. Read all cached verdicts up front; collect the misses to probe.
        $result = [];
        $misses = [];
        foreach ($ids as $id) {
            $cached = Cache::get($this->cacheKey($id));
            if ($cached !== null) {
                $result[$id] = $cached === 'maxres' ? $this->maxresUrl($id) : $this->hqUrl($id);
            } else {
                $misses[] = $id;
            }
        }

        if ($misses === []) {
            return $result;
        }

        // 2. Probe every miss concurrently, but in bounded chunks so one refresh can't
        //    fan out an unlimited burst of HEADs to i.ytimg.com (SCALE-3). Global
        //    across the batch; per-provider job pacing is handled upstream by the
        //    platform-refresh RateLimiter.
        $responses = $this->pooledHead($misses);

        // 3. Record each fresh verdict, write it to cache, and merge into the map.
        // A pooled request that errored comes back as a Throwable, not a Response
        // — treat anything that isn't a clean 200 as "no maxres, use hqdefault".
        foreach ($misses as $id) {
            $response = $responses[$id] ?? null;
            $hasMaxres = $response instanceof Response && $response->status() === 200;

            // Jittered TTL (integer seconds — DateTimeInterface bypasses jitter) so
            // the whole batch's verdicts don't expire in the same second 30 days on.
            Cache::put($this->cacheKey($id), $hasMaxres ? 'maxres' : 'hq', self::applyJitter(self::CACHE_DAYS * 86400));
            $result[$id] = $hasMaxres ? $this->maxresUrl($id) : $this->hqUrl($id);
        }

        return $result;
    }

    /**
     * HEAD-probe every id concurrently, capped at the configured pool concurrency.
     * Chunks the ids and runs one Http::pool per chunk; results keyed by id (via
     * $pool->as($id)) are merged across chunks.
     *
     * @param  array<int,string>  $ids
     * @return array<string, Response|\Throwable>
     */
    private function pooledHead(array $ids): array
    {
        $max = max(1, (int) config('partna.refresh.host_limits.youtube_thumbnails.pool_concurrency', 10));
        $responses = [];

        foreach (array_chunk($ids, $max) as $chunk) {
            $batch = Http::pool(fn (Pool $pool) => array_map(
                fn (string $id) => $pool->as($id)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->head($this->maxresUrl($id)),
                $chunk,
            ));
            $responses += $batch; // key-preserving union (keys are the ids)
        }

        return $responses;
    }

    private function cacheKey(string $videoId): string
    {
        return CacheKeyGenerator::youtubeThumbnailVerdict($videoId);
    }
}
