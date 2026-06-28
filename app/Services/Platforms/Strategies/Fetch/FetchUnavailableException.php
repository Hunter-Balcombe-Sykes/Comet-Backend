<?php

namespace App\Services\Platforms\Strategies\Fetch;

// The upstream fetch returned nothing usable (empty scrape, failed API/oEmbed call).
// Mirrors PlatformRefresher's status='unavailable' bucket — recorded quietly, the
// last-known-good payload preserved (no edge-cache purge).
class FetchUnavailableException extends \RuntimeException {}
