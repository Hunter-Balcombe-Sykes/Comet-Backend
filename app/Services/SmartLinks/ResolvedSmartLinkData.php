<?php

namespace App\Services\SmartLinks;

/**
 * Normalized output every extractor produces. The resolver maps this onto the
 * SmartLink columns; `metadata` holds the type-specific extras (price, stock,
 * date, location, showName, channelName, subType).
 */
class ResolvedSmartLinkData
{
    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,string>  $imageSources  field => source URL (for commerce re-ingest gate)
     */
    public function __construct(
        public ?string $title = null,
        public ?string $imageUrl = null,
        public ?string $faviconUrl = null,
        public ?string $brandName = null,
        public ?string $brandLogoUrl = null,
        public string $platform = 'generic',
        public array $metadata = [],
        public array $imageSources = [],
    ) {}
}
