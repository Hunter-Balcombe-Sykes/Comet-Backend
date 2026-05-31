<?php

namespace App\Services\SmartLinks\Extractors;

use App\Services\SmartLinks\ParsedUrl;
use App\Services\SmartLinks\ResolvedSmartLinkData;

/**
 * A per-platform/group extender plugged into SmartLinkResolver. The resolver
 * owns the shared pipeline (parse → SSRF guard → cache → validate → ingest);
 * each extractor only knows how to turn a URL into normalized data.
 */
interface SmartLinkExtractor
{
    /** Can this extractor resolve this URL for the declared type? */
    public function matches(ParsedUrl $url, string $type): bool;

    /** Fetch + parse → normalized data, or null if unresolvable. */
    public function resolve(ParsedUrl $url, string $type): ?ResolvedSmartLinkData;
}
