<?php

namespace App\Services\Platforms\Strategies\Contracts;

// How a "picker" platform (YouTube, Vimeo, YouTube Music, Bandcamp, ...) resolves
// its recent-items picker and the curated-highlights save. identity()/recentItems()
// are split rather than one call because the two frozen error paths of these
// platforms depend on WHICH step fails: no stored identity (never connected, or
// the connection lost its identity) is a 404 "connect first" — recentItems() never
// even runs. A resolved identity whose upstream re-fetch comes back empty/failed
// is a 422 "couldn't load" — the connection exists, the picker data just isn't
// available right now. Collapsing these into one call would lose that distinction.
interface HighlightsStrategy
{
    /** Re-fetch key derived from the stored payload; null → 404 "connect first". */
    public function identity(array $payload): ?string;

    /** Fresh picker items for that identity; null → 422 "couldn't load". */
    public function recentItems(string $identity): ?array;

    /** Merge the chosen ids into the stored selection; returns the full payload to persist. */
    public function apply(array $selection, array $items, array $chosenIds): array;

    /** Request field name carrying the chosen ids — frozen per platform ('videoIds' | 'itemIds'). */
    public function requestField(): string;

    /** @return array<string, array<int, string>> full validation rules for the highlights save (frozen per platform) */
    public function rules(): array;

    /** Response key the picker items / saved selection are returned under ('videos' | 'items'). */
    public function responseKey(): string;

    /** 404 message when there's no identity to re-fetch from. */
    public function notConnectedMessage(): string;

    /** 422 message when the upstream re-fetch comes back empty/failed. */
    public function loadErrorMessage(): string;
}
