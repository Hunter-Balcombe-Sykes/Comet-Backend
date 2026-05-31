<?php

namespace App\Services\Platforms;

// Shared base for the test-mode platform scrapers. Holds the one browser
// user-agent they all present when fetching public pages / feeds / JSON, so the
// string lives in exactly one place instead of being copy-pasted per controller.
abstract class PlatformScraper
{
    protected const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
}
