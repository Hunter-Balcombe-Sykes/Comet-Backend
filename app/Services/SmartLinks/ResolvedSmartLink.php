<?php

namespace App\Services\SmartLinks;

/** The full result of SmartLinkResolver::resolve — data + validation verdict. */
final class ResolvedSmartLink
{
    public function __construct(
        public string $type,
        public string $family,
        public string $platform,
        public ParsedUrl $url,
        public ?ResolvedSmartLinkData $data,
        public bool $valid,
        public ?string $reason = null,
    ) {}
}
