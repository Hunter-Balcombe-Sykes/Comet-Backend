<?php

use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use App\Services\Platforms\Registry\DerivedDescriptorFactory;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\WebsiteLinkHarvester;

/**
 * Every connectable, URL-detected catalog surface must be reachable as a
 * platform. This is the invariant the whole Brand shape exists to hold: before
 * it, ~50 brands were declared in the registry, shaped Bespoke, and had no
 * routes at all — the failure mode was silence, not an error.
 */

// Defined and used in THIS file only — a cross-file Pest helper breaks the
// parallel runner in this repo.
function brandCoverageSurfaces(): array
{
    $detected = [];
    foreach (CompiledCatalog::detectors() as $detector) {
        $key = $detector['surface_key'] ?? null;
        if (is_string($key) && $key !== '' && ($detector['evidence'] ?? null) === 'url') {
            $detected[$key] = true;
        }
    }

    $out = [];
    foreach (CompiledCatalog::surfaces() as $key => $surface) {
        if (! in_array($surface['lifecycle'] ?? '', ['active', 'sunset'], true)) {
            continue;
        }
        if (($surface['is_connectable'] ?? false) !== true || ! isset($detected[$key])) {
            continue;
        }
        // Storefronts are connected by the commerce probe, never a brand route.
        if (($surface['routing_class'] ?? null) === 'shop') {
            continue;
        }
        $out[$key] = $surface;
    }

    return $out;
}

it('resolves every connectable url-detected surface to a registry descriptor', function () {
    $registry = app(PlatformRegistry::class);

    $unrouted = [];
    foreach (brandCoverageSurfaces() as $key => $surface) {
        $slug = LegacyPlatformMap::legacyFor($key);
        if (! $registry->has($slug)) {
            $unrouted[] = "{$key} -> {$slug}";
        }
    }

    expect($unrouted)->toBe([], "Connectable surfaces with no platform descriptor:\n  ".implode("\n  ", $unrouted));
});

it('gives every brand-shaped descriptor a surface key that reduces back to its slug', function () {
    $registry = app(PlatformRegistry::class);

    $checked = 0;
    foreach ($registry->all() as $slug => $descriptor) {
        if ($descriptor->routeShape() !== PlatformRouteShape::Brand) {
            continue;
        }

        $surfaceKey = $descriptor->getSurfaceKey();
        expect($surfaceKey)->toBeString("{$slug} is Brand-shaped but carries no surface key");
        // If this drifts, rows are written under a surface whose generated
        // `platform` column does not match the route that wrote them, and the
        // connection becomes invisible to its own platform's reads.
        expect(LegacyPlatformMap::legacyFor($surfaceKey))->toBe($slug);
        $checked++;
    }

    expect($checked)->toBeGreaterThan(30);
});

it('accepts a real url for its own host on every brand platform', function () {
    $registry = app(PlatformRegistry::class);
    $harvester = app(WebsiteLinkHarvester::class);

    $bySurface = [];
    foreach (CompiledCatalog::detectors() as $detector) {
        $key = $detector['surface_key'] ?? null;
        if (is_string($key) && ($detector['evidence'] ?? null) === 'url' && ! empty($detector['registrable_key'])) {
            $bySurface[$key][] = $detector['registrable_key'];
        }
    }

    $rejected = [];
    $checked = 0;

    foreach ($registry->all() as $slug => $descriptor) {
        if ($descriptor->routeShape() !== PlatformRouteShape::Brand) {
            continue;
        }
        $checked++;

        $accepted = false;

        // The surface's own canonical URL with placeholders filled is the
        // strongest "its own url" probe — added 2026-09-01 for bluesky, the
        // first Brand platform whose detector anchors on a deeper path
        // (/profile/…) that the generic '/x' shapes below can't reach.
        $canonicalProbes = [];
        $surfaceRow = CompiledCatalog::surface($descriptor->getSurfaceKey()) ?? [];
        $template = $surfaceRow['canonical_url_template'] ?? null;
        if (is_string($template) && $template !== '') {
            // A numeric_id surface's own detector requires digits (e.g.
            // Deezer.php's /artist/(?<id>\d{1,15})) — the literal 'x' this
            // probe used everywhere else fails that shape and reads as the
            // surface rejecting its own canonical URL, when the real defect
            // (if any) is this probe's placeholder, not the surface. Found
            // 2026-09-04 fixing the equivalent real over-matching bug in
            // WebsiteLinkHarvester (deezer.com/<non-artist-path> was wrongly
            // claimed as deezer.artist before that fix); tightening the
            // harvester's own deezer detector surfaced this probe's blind
            // spot in the same run.
            $placeholder = ($surfaceRow['identifier_kind'] ?? null) === 'numeric_id' ? '123' : 'x';
            $canonicalProbes[] = preg_replace('/\{[a-z_]+\}/i', $placeholder, $template);
        }

        foreach ($bySurface[$descriptor->getSurfaceKey()] ?? [] as $host) {
            // Three shapes because some detectors anchor on a subdomain
            // (mykajabi.com, circle.so) and reject the bare or www host.
            foreach ([...$canonicalProbes, "https://www.{$host}/x", "https://shop.{$host}/", "https://{$host}/x"] as $url) {
                $classified = $harvester->classify($url);
                if ($classified === null) {
                    continue;
                }
                $actual = LegacyPlatformMap::legacyFor($classified['platform']);
                if ($actual === $slug
                    || in_array($actual, DerivedDescriptorFactory::classifierAliases()[$slug] ?? [], true)) {
                    $accepted = true;
                    break 2;
                }
            }
        }

        if (! $accepted) {
            $rejected[] = $slug;
        }
    }

    // A brand whose own URL its guard rejects is a card the user taps into a
    // dead end — strictly worse than never offering it.
    expect($rejected)->toBe([], "Brand platforms that would 422 their own url:\n  ".implode("\n  ", $rejected));
    expect($checked)->toBeGreaterThan(30);
});

it('never upgrades a descriptor that already had a working connect route', function () {
    $registry = app(PlatformRegistry::class);

    // instagram, apple-music, apple-podcast, eventbrite, humanitix, fresha and
    // square all have real connect flows. If the upgrade discriminator ever
    // widened to swallow one, its scraper would be replaced by a link stub.
    foreach (['instagram', 'apple-music', 'apple-podcast', 'eventbrite', 'humanitix', 'fresha', 'square'] as $slug) {
        expect($registry->get($slug)?->routeShape())
            ->not->toBe(PlatformRouteShape::Brand, "{$slug} must keep its own connect flow");
    }
});
