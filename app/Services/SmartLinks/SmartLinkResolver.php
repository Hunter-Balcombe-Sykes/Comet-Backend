<?php

namespace App\Services\SmartLinks;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The shared smart-link pipeline. Parses + SSRF-checks the URL, resolves the
 * precise type, runs the extractor chain (first non-null wins), validates, and
 * returns a ResolvedSmartLink. Extractor output is cached 24h by type+canonical
 * (the refresh cron bypasses the cache to force-fresh).
 */
class SmartLinkResolver
{
    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly UrlNormalizer $normalizer,
        private readonly SmartLinkTypeRegistry $registry,
        private readonly SmartLinkValidator $validator,
        private readonly Cache $cache,
    ) {}

    public function resolve(string $rawUrl, string $selection, bool $bypassCache = false): ResolvedSmartLink
    {
        $url = $this->normalizer->parse($rawUrl);

        $type = $this->registry->resolveType($url, $selection);
        if ($type === null) {
            return new ResolvedSmartLink(
                type: $selection,
                family: '',
                platform: 'generic',
                url: $url,
                data: null,
                valid: false,
                reason: "This doesn’t look like a supported {$selection} link.",
            );
        }

        $family = $this->registry->familyOf($type);
        $cacheKey = 'smartlink:'.$type.':'.md5($url->canonical);

        $data = $bypassCache ? null : $this->cache->get($cacheKey);

        if (! $data instanceof ResolvedSmartLinkData) {
            $data = null;
            foreach ($this->registry->extractorChain($type) as $extractor) {
                if (! $extractor->matches($url, $type)) {
                    continue;
                }
                $resolved = $extractor->resolve($url, $type);
                if ($resolved !== null) {
                    $data = $resolved;
                    break;
                }
            }
            if ($data !== null) {
                $this->cache->put($cacheKey, $data, self::CACHE_TTL_SECONDS);
            }
        }

        if ($data === null) {
            return new ResolvedSmartLink(
                type: $type,
                family: $family,
                platform: 'generic',
                url: $url,
                data: null,
                valid: false,
                reason: 'We couldn’t read this link — it may block previews or not be a supported page.',
            );
        }

        $verdict = $this->validator->validate($type, $data);

        return new ResolvedSmartLink(
            type: $type,
            family: $family,
            platform: $data->platform,
            url: $url,
            data: $data,
            valid: $verdict['valid'],
            reason: $verdict['reason'],
        );
    }
}
