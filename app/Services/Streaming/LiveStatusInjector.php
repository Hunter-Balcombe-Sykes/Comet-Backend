<?php

namespace App\Services\Streaming;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Post-processes a cached site payload to inject live status for streaming platforms.
 * Called after SiteCacheService::getPublicSitePayload() — never stored in the cache itself.
 */
class LiveStatusInjector
{
    private const LIVE_KEY_PREFIX = 'streaming:live:';

    /**
     * Injects is_live into the `links`, `sections`, and `blocks` arrays in a site payload.
     *
     * SiteCacheService::getPublicSitePayload() returns links and sections as separate
     * top-level arrays (both living in site.blocks, differentiated by block_group).
     * Covering all three keys future-proofs against streaming blocks appearing in sections.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function injectIntoPayload(array $payload): array
    {
        foreach (['links', 'sections', 'blocks'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $payload[$key] = $this->injectIntoBlocks($payload[$key]);
            }
        }

        return $payload;
    }

    /**
     * Injects is_live into each block that has live_check_enabled=true and a streaming platform.
     * Missing Redis key → is_live=false (safe default, no error).
     *
     * Phase 2: platform + live_check_enabled are promoted columns emitted as top-level
     * block keys by the public-site views. handle stays in the settings bag.
     * is_live continues to be written under settings.is_live (frontend wire contract).
     *
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    public function injectIntoBlocks(array $blocks): array
    {
        $streamingPlatforms = config('partna.streaming_platforms', []);

        return array_map(function ($block) use ($streamingPlatforms) {
            if (! is_array($block)) {
                return $block;
            }

            // platform + live_check_enabled are promoted columns, emitted as top-level
            // block keys by the public-site views. handle stays in the settings bag.
            $platform = $block['platform'] ?? null;
            $liveCheckEnabled = (bool) ($block['live_check_enabled'] ?? false);

            $settings = $block['settings'] ?? [];
            $handle = is_array($settings) ? ($settings['handle'] ?? null) : null;

            if (
                ! $liveCheckEnabled
                || ! $platform
                || ! $handle
                || ! in_array($platform, $streamingPlatforms, true)
            ) {
                return $block;
            }

            // is_live continues to live under settings.is_live (wire contract for the frontend).
            if (! is_array($block['settings'] ?? null)) {
                $block['settings'] = [];
            }
            $redisKey = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
            $block['settings']['is_live'] = $this->redis()->get($redisKey) === '1';

            return $block;
        }, $blocks);
    }

    /**
     * This runs during public sitepage render, inside injectIntoBlocks()'s
     * map() over every block — one GET per streaming block, sequentially, on
     * the request path the drill measured at 18.31s. `app`, not the bare
     * facade default, so N probes take the 3.0s bound each instead of
     * `default`'s 15.0s (reserved for queue workers' BLPOP). See drill 03
     * (2026-08-05).
     */
    private function redis(): Connection
    {
        return Redis::connection('app');
    }
}
