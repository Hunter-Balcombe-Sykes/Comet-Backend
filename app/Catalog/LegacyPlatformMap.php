<?php

namespace App\Catalog;

/**
 * The bridge between the legacy `platform` slug vocabulary and catalog surface
 * keys. It holds NO data of its own: the slug is a field on the surface in the
 * compiled catalog (`legacy_platform`, set only where the slug is not the brand
 * prefix), and everything else derives from the prefix.
 *
 * The vocabulary is still load-bearing — it is on the public wire and mirrored
 * into SQL — so it has four representations that must agree:
 *   1. this class, reading the compiled artefact (the AUTHORED source),
 *   2. the 20260727110001 backfill CASE (historical record of a one-time run),
 *   3. the LIVE generated `platform` column, 20260727110004 (GENERATED ALWAYS
 *      ... STORED — Postgres cannot read the artefact, so this is emitted from
 *      it by `catalog:emit-legacy-alias-sql`, not hand-maintained),
 *   4. the SQLite mirror in tests/Pest.php.
 * CatalogLegacyMapTest asserts 1 == 2 == 3 == 4.
 *
 * Changing a slug is therefore a MIGRATION with a full heap rewrite, not an
 * edit to a definition. RETIRED below stays so the lockstep test can tell
 * "retired on purpose" from "drifted by accident".
 */
class LegacyPlatformMap
{
    /**
     * Platforms REMOVED from the product after the 20260727110000 migration
     * ran. Their rows are still in that migration's backfill CASE — it is a
     * historical record of a one-time operation, not a live mapping, and
     * editing an applied migration to hide a later decision would make the
     * file lie about what actually executed.
     *
     * Kept here so the lockstep test can tell "the map and the SQL disagree"
     * (a bug) apart from "this platform was retired" (a decision), and so
     * anyone reading the map can see WHY the migration has an entry the map
     * does not.
     *
     * @var array<string, string> legacy slug => the surface it used to map to
     */
    private const RETIRED = [
        // Pinterest was retired here 2026-07-28 (connector + surface + scraper
        // deleted). RE-ADDED 2026-08-28 as a LINK-ONLY brand in the wave-2
        // batch — same slug, same surface key, none of the old integration
        // machinery — so the retirement row leaves with it: a live surface may
        // not sit in this map, and the resurrection is a deliberate decision,
        // not the guard failing.
        // Pseudo-platform link lane retired 2026-08-19 (owner): every routed
        // link lands on its real brand surface via LinkRouter/SourceReconciler.
        // Zero live rows carried any of these at deletion time (measured).
        'custom' => 'partna.custom_link',
        'events-custom' => 'partna.manual_event',
        'booking' => 'partna.booking_link',
        'reservations' => 'partna.reserve_link',
        'online-ordering' => 'partna.order_link',
    ];

    /** @var array<string, string>|null memoised legacy slug => surface key */
    private static ?array $inverse = null;

    /** @var array<string, string>|null memoised surface key => legacy slug */
    private static ?array $forward = null;

    /**
     * Legacy slug => surface key, for exactly the surfaces that carry one.
     *
     * Deliberately NOT every surface: a surface with no `legacy_platform` was
     * never addressable by a legacy slug, and widening this would change what
     * callers doing `surfaceFor($p) ?? $p` resolve to.
     */
    public static function surfaceFor(string $legacyPlatform): ?string
    {
        return self::inverse()[$legacyPlatform] ?? null;
    }

    /**
     * Whether this surface may be written to a connection.
     *
     * The compiled catalog is the sole authority. It is a git-tracked artefact
     * (bootstrap/catalog/compiled.php), so a missing one is a broken deploy,
     * not a supported state — this deliberately throws rather than silently
     * half-opening the write gate.
     */
    public static function isKnownSurface(string $surfaceKey): bool
    {
        return CompiledCatalog::surface($surfaceKey) !== null;
    }

    /**
     * Whether the platform a legacy slug names has a live connect flow.
     *
     * Deliberately separate from surfaceFor() rather than widening it (that
     * method's own docblock says its narrowness is load-bearing for other
     * callers). This resolves the slug two ways: first as a legacy alias
     * (covers apple-music, apple-podcast, eventbrite, humanitix, trybooking —
     * every surface with an explicit legacyPlatform() entry), then, if that
     * misses, as a bare brand prefix (covers single-surface catalog-only
     * brands like audiomack, tixr, spotify_podcasts, whose surface key is
     * literally "{slug}.{product}" with no legacy alias needed).
     *
     * Returns null for a slug that resolves neither way — an unrecognised
     * platform, not a known-and-refused one — so callers can choose their own
     * safe default instead of this method guessing on their behalf. Found
     * by measuring MediaPageReader::accountPlatform() /
     * EventPageReader::organiserPlatform() / WebsiteLinkHarvester::classify()
     * against the compiled catalog directly (2026-09-04): 16 platforms
     * (audiomack, beatport, dailymotion, feature_fm, laylo, linkfire, rumble,
     * admitone, bandsintown, dice, etix, moshtix, skiddle, songkick,
     * ticketweb, tixr) are catalog-declared notConnectable() yet the 422 copy
     * in PoolItemCreateController and PastedLinkClassifier's account/organiser
     * answer told the user to "Connect X as a platform" regardless — a
     * dead-end instruction, since none of the 16 has a connect route at all.
     */
    public static function connectableForSlug(string $slug): ?bool
    {
        $surfaceKey = self::surfaceFor($slug);
        if ($surfaceKey !== null) {
            return (bool) (CompiledCatalog::surface($surfaceKey)['is_connectable'] ?? false);
        }

        foreach (self::brandPrefixSurfaces() as $brandKey => $surface) {
            if ($brandKey === $slug) {
                return (bool) ($surface['is_connectable'] ?? false);
            }
        }

        return null;
    }

    /**
     * One representative surface per brand key that has no legacy alias —
     * exactly the brands a bare slug can name directly. Where a brand has
     * more than one such surface (none currently do), the first compiled
     * wins; that ambiguity would mean connectableForSlug() cannot answer for
     * one bare slug in the first place, so any pick is as valid as any other.
     *
     * @return array<string, array<string, mixed>> brand key => surface array
     */
    private static function brandPrefixSurfaces(): array
    {
        $legacyAliasedSurfaceKeys = array_flip(array_values(self::inverse()));
        $map = [];
        foreach (CompiledCatalog::surfaces() as $key => $surface) {
            if (isset($legacyAliasedSurfaceKeys[$key])) {
                continue;
            }
            $map[$surface['brand_key']] ??= $surface;
        }

        return $map;
    }

    /**
     * The legacy slug for a surface: the catalog's `legacy_platform` where the
     * surface carries one, otherwise the brand prefix — the same rule as
     * split_part(surface_key, '.', 1) in Postgres.
     */
    public static function legacyFor(string $surfaceKey): string
    {
        return self::forward()[$surfaceKey]
            ?? explode('.', $surfaceKey, 2)[0];
    }

    /** The routing class for a surface, per the compiled catalog. */
    public static function routingClassFor(string $surfaceKey): ?string
    {
        return CompiledCatalog::surface($surfaceKey)['routing_class'] ?? null;
    }

    /** @return array<string, string> legacy slug => surface key */
    public static function toSurfaceMap(): array
    {
        $map = self::inverse();
        ksort($map);

        return $map;
    }

    /**
     * Live mappings plus retired ones — what the historical backfill CASE
     * contains. Only the lockstep test should need this.
     *
     * @return array<string, string>
     */
    public static function historicalSurfaceMap(): array
    {
        $all = self::toSurfaceMap() + self::RETIRED;
        ksort($all);

        return $all;
    }

    /** @return array<string, string> */
    public static function retiredMap(): array
    {
        return self::RETIRED;
    }

    /**
     * Surface key => legacy slug, ONLY where the slug is not the brand prefix.
     * These are the WHEN arms of the generated alias CASE; everything else
     * falls through to its split_part ELSE.
     *
     * @return array<string, string>
     */
    public static function specialToLegacyMap(): array
    {
        $special = [];

        foreach (self::forward() as $surfaceKey => $legacy) {
            if ($legacy !== explode('.', $surfaceKey, 2)[0]) {
                $special[$surfaceKey] = $legacy;
            }
        }

        ksort($special);

        return $special;
    }

    /**
     * Live special aliases plus the retired pseudo-surface ones — what the
     * APPLIED generated-column CASE (20260727110004, and the SQLite mirror in
     * tests/Pest.php) still contains: an applied migration records what ran,
     * and the retired surfaces alias harmlessly with zero rows to alias.
     * Only the lockstep test should need this.
     *
     * @return array<string, string>
     */
    public static function historicalSpecialToLegacyMap(): array
    {
        $all = self::specialToLegacyMap();

        foreach (self::RETIRED as $legacy => $surface) {
            if (str_starts_with($surface, 'partna.')) {
                $all[$surface] = $legacy;
            }
        }

        return $all;
    }

    /** Test seam — drops the memoised projections after a catalog swap. */
    public static function flush(): void
    {
        self::$inverse = null;
        self::$forward = null;
    }

    /** @return array<string, string> surface key => legacy slug (explicit only) */
    private static function forward(): array
    {
        if (self::$forward !== null) {
            return self::$forward;
        }

        $forward = [];

        foreach (CompiledCatalog::surfaces() as $key => $surface) {
            $legacy = $surface['legacy_platform'] ?? null;

            if (is_string($legacy) && $legacy !== '') {
                $forward[$key] = $legacy;
            }
        }

        return self::$forward = $forward;
    }

    /** @return array<string, string> legacy slug => surface key */
    private static function inverse(): array
    {
        if (self::$inverse !== null) {
            return self::$inverse;
        }

        $inverse = [];

        foreach (self::forward() as $surfaceKey => $legacy) {
            // A collision means two surfaces claim one legacy slug — the
            // artefact is wrong, and silently keeping either would mis-route
            // every write for that slug.
            if (isset($inverse[$legacy])) {
                throw new \LogicException(
                    "Legacy slug '{$legacy}' is claimed by both '{$inverse[$legacy]}' and '{$surfaceKey}'.",
                );
            }

            $inverse[$legacy] = $surfaceKey;
        }

        return self::$inverse = $inverse;
    }
}
