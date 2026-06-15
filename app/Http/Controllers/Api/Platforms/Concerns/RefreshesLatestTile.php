<?php

namespace App\Http\Controllers\Api\Platforms\Concerns;

// Shared "most recent" tile construction for the platform controllers. The nested
// `latest` key is the canonical shape; the flat keys ($flatFields) are the legacy
// back-compat fields partna-pages still reads. Centralised so a fix to one platform
// can't miss the others — the `latest`-key drift (CONS-1) was exactly that.
trait RefreshesLatestTile
{
    /**
     * Copy the flat back-compat tile fields verbatim from a latest item.
     *
     * @param  list<string>  $flatFields  keys to copy (differ per platform — e.g.
     *                                    music releaseDate vs podcast/youtube description)
     * @return array<string,mixed>
     */
    protected function flatTileFields(array $latest, array $flatFields): array
    {
        $out = [];
        foreach ($flatFields as $f) {
            // `?? null` so a field absent on a given item (e.g. a podcast episode
            // with no releaseDate) yields null rather than an undefined-key warning.
            $out[$f] = $latest[$f] ?? null;
        }

        return $out;
    }

    /**
     * Overwrite a selection's `latest` + flat tile fields from the newest item, so
     * the auto-latest tile doesn't go stale when only the curated highlights change.
     *
     * @param  list<string>  $flatFields
     * @return array<string,mixed>
     */
    protected function refreshLatestTile(array $selection, array $latest, array $flatFields): array
    {
        $selection['latest'] = $latest;

        return array_merge($selection, $this->flatTileFields($latest, $flatFields));
    }
}
