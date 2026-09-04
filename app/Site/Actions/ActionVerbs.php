<?php

namespace App\Site\Actions;

/**
 * The action verb a platform candidate wears — "Watch on YouTube", not
 * "YouTube" (spec §2's platform label was the bare registry label, which told
 * a visitor the brand but never the intent).
 *
 * Keyed on the catalog SHELF, deliberately not on PlatformCategory: `Content`
 * is a grab-bag holding YouTube, Apple Podcasts, Skool and Kajabi at once, so
 * a category-keyed map renders "Watch on Apple Podcasts". Shelf is also the
 * grouping the dashboard picker shows (CatalogSurfacesController ships it), so
 * the verb matches the heading the owner connected the platform under.
 *
 * Pure — no DB, no clock, no container. The caller resolves the shelf.
 */
final class ActionVerbs
{
    /**
     * Surface-key overrides — the most specific key, checked first. One entry
     * today: flickr shelves under `media` beside Medium and Substack, so the
     * shelf map alone renders "Read on Flickr" for a photo stream. Keep this
     * for surfaces the shelf genuinely mis-describes, not as a per-brand
     * copy-tuning table.
     *
     * @var array<string, string>
     */
    private const BY_SURFACE = [
        'flickr.photos' => 'View',
    ];

    /**
     * Routing classes that settle a shelf holding two intents at once — `food`
     * carries Uber Eats (ordering) beside OpenTable (reservations), and the
     * shelf alone cannot say Order from Reserve. Checked BEFORE the shelf map
     * because these four classes are unambiguous by construction.
     *
     * @var array<string, string>
     */
    private const BY_ROUTING_CLASS = [
        'booking' => 'Book',
        'reservations' => 'Reserve',
        'ordering' => 'Order',
        'shop' => 'Shop',
    ];

    /**
     * Shelf => verb. A shelf absent from this map has no verb and keeps the
     * bare brand label: `business` (Google Business, Yelp) is the deliberate
     * omission — "Contact on Yelp" is invented copy, not an intent the
     * platform actually offers.
     *
     * @var array<string, string>
     */
    private const BY_SHELF = [
        'video' => 'Watch',
        'music' => 'Listen',
        'podcast' => 'Listen',
        'social' => 'Follow',
        'community' => 'Join',
        'education' => 'Learn',
        'media' => 'Read',
        'commerce' => 'Shop',
        'events' => 'Tickets',
        'booking' => 'Book',
        'food' => 'Order',
    ];

    /** Null means "no verb" — the candidate keeps its bare brand label. */
    public static function for(?string $shelf, ?string $routingClass, ?string $surfaceKey = null): ?string
    {
        return self::BY_SURFACE[(string) $surfaceKey]
            ?? self::BY_ROUTING_CLASS[(string) $routingClass]
            ?? self::BY_SHELF[(string) $shelf]
            ?? null;
    }

    /** "Watch" + "YouTube" => "Watch on YouTube"; no verb (or no brand) => the brand verbatim. */
    public static function label(string $brand, ?string $verb): string
    {
        return $verb === null || $brand === '' ? $brand : $verb.' on '.$brand;
    }
}
