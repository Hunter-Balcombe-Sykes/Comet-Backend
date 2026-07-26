<?php

namespace App\Services\V5\Scraping\Contracts;

// V5 Highlights contract — curated item picker for platforms that support selecting
// specific content items (e.g. YouTube videos, Bandcamp releases).
interface HighlightsContract
{
    public function identity(): array;
    public function recentItems(): array;
    public function apply(array $selection): void;
}
