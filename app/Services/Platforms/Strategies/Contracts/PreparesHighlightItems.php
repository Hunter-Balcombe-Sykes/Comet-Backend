<?php

namespace App\Services\Platforms\Strategies\Contracts;

// SEAM INTERFACE — optional, matching the ApiKeyConnect/OAuthConnect/WebhookRefresh
// convention in this directory. Implemented by BandcampHighlights alone (the only
// highlights strategy whose apply() does vendor I/O — enrichPrices()); the other
// three (Youtube/Vimeo/YoutubeMusic) get zero diff from this interface existing.
//
// Unit 11 W3 (LIFE-21 lock-boundary fix): apply() used to run enrichPrices() TWICE
// while INSIDE GenericPlatformController::highlights()'s per-user connection lock —
// a lock is a deliberate bottleneck (it serialises concurrent work), so holding one
// across a vendor fetch lets a stranger's latency decide how long that bottleneck
// lasts. prepare() is the seam that moves the pricing fetch out.
interface PreparesHighlightItems
{
    /**
     * Enrich picker items the save will draw from. Vendor I/O is allowed here —
     * this runs OUTSIDE the per-user connection lock, unlike apply(), which must
     * do zero vendor I/O once this interface is implemented.
     *
     * @param  list<array<string,mixed>>  $items  picker items (HighlightsPicker::items()'s result)
     * @param  list<string>  $chosenIds  the ids the save request submitted
     * @return list<array<string,mixed>> $items, same shape and order, with the
     *                                   chosen (+ "most recent") entries enriched
     */
    public function prepare(array $items, array $chosenIds): array;
}
