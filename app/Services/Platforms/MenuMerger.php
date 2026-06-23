<?php

namespace App\Services\Platforms;

// Fuses the two platform menus (from MenuApifyScraper) into the single persisted
// menu as a UNION across every connected platform — no platform's items are
// dropped. The CONTENT platform (Uber Eats preferred) supplies the canonical
// spine (category order + names); items that exist ONLY on the other platform
// are appended afterwards so they still appear. For a dish present on BOTH
// platforms (matched by name — exact-normalized, then containment) we produce
// one merged item that:
//   • gap-fills display fields (name / description / image) — Uber Eats wins a
//     field only where it HAS a value; otherwise the other platform fills it,
//   • collects BOTH platforms into `platforms[]` — one entry per platform, each
//     carrying its pickupPrice + pickupUrl and deliveryPrice + deliveryUrl (a
//     mode the store doesn't offer is null on both),
//   • attaches the DoorDash 👍 rating + badges (Uber Eats exposes neither), and
//   • carries Uber Eats modifiers (DoorDash exposes none).
//
// Aggregate prices are derived from `platforms[]`:
//   basePrice     = cheapest price the dish can be had for, any mode/platform,
//   pickupPrice   = min pickupPrice across platforms,
//   deliveryPrice = min deliveryPrice across platforms,
// each *Source tagged with the platform backing that min. A mode no platform
// prices stays null.
//
// Per-platform per-mode prices arrive on each item (pickupPrice/deliveryPrice,
// from MenuApifyScraper::fetchStore scraping both mode pages). Per-platform urls
// + offered modes come from $storeLinks (MenuSource::storeLinks) — the
// consolidated ordering-link set per platform.
//
// LINK-DRIVEN AVAILABILITY: a platform the user has connected (a store link
// exists) but whose scrape returned nothing this run is a "ghost" — it's still
// attached to EVERY dish (linking to the store, prices null) so a flaky scrape
// never hides a platform the user actually has. Real per-dish prices fill in on
// the next successful scrape.
class MenuMerger
{
    // Mild-strict matching needs at least this many characters in the shorter
    // name before a containment match counts — stops short tokens ("Naan",
    // "Cola") pairing every dish that happens to include the word.
    private const MIN_CONTAINMENT_LEN = 5;

    // The platforms we union, in content-priority order (Uber Eats wins ties on
    // display fields). Drives both the spine choice and the platforms[] order.
    private const PLATFORMS = ['uber-eats', 'doordash'];

    /**
     * @param  array{store:array<string,mixed>, categories:list<array<string,mixed>>}|null  $ueMenu
     * @param  array{store:array<string,mixed>, categories:list<array<string,mixed>>}|null  $ddMenu
     * @param  array<string, array{pickupUrl:?string, deliveryUrl:?string, storeUrl:?string, modes:list<string>}>  $storeLinks
     * @return array{store:array<string,mixed>, categories:list<array{name:string, sourcePlatform:string, items:list<array<string,mixed>>}>}
     */
    public function merge(?array $ueMenu, ?array $ddMenu, string $contentSource, array $storeLinks = []): array
    {
        $canonical = ($contentSource === 'uber-eats' ? $ueMenu : $ddMenu) ?? ['store' => [], 'categories' => []];
        $otherSource = $contentSource === 'uber-eats' ? 'doordash' : 'uber-eats';
        $other = $contentSource === 'uber-eats' ? $ddMenu : $ueMenu;

        // Ghost platforms: connected (store link present) but this run's scrape
        // returned nothing. They're attached to every dish so the platform never
        // vanishes on a flaky scrape (see class doc, LINK-DRIVEN AVAILABILITY).
        $menus = ['uber-eats' => $ueMenu, 'doordash' => $ddMenu];
        $ghostPlatforms = [];
        foreach (self::PLATFORMS as $platform) {
            if ($menus[$platform] === null && isset($storeLinks[$platform])) {
                $ghostPlatforms[$platform] = $storeLinks[$platform];
            }
        }

        $index = $this->index($other['categories'] ?? []);

        // Track which other-platform items got matched into a canonical dish, so
        // the leftovers (other-platform-only dishes) can be appended afterwards.
        $matchedOther = [];

        $categories = [];
        foreach ($canonical['categories'] as $category) {
            $items = [];
            foreach ((array) ($category['items'] ?? []) as $item) {
                $matched = $this->match((string) ($item['name'] ?? ''), $index);
                if ($matched !== null) {
                    $matchedOther[$matched['key']] = true;
                }
                $items[] = $this->fuse($item, $matched['item'] ?? null, $contentSource, $storeLinks, $ghostPlatforms);
            }
            if ($items !== []) {
                $categories[] = [
                    'name' => (string) ($category['name'] ?? 'Menu'),
                    'sourcePlatform' => $contentSource,
                    'items' => $items,
                ];
            }
        }

        // Append the other platform's UNMATCHED items — every dish from every
        // platform must appear. They keep their own category (named from the
        // other platform), tagged with the other platform as the source.
        foreach ($this->leftovers($other['categories'] ?? [], $matchedOther) as $category) {
            $items = [];
            foreach ($category['items'] as $item) {
                $items[] = $this->fuse($item, null, $otherSource, $storeLinks, $ghostPlatforms);
            }
            if ($items !== []) {
                $categories[] = [
                    'name' => (string) ($category['name'] ?? 'Menu'),
                    'sourcePlatform' => $otherSource,
                    'items' => $items,
                ];
            }
        }

        return [
            'store' => $this->mergeStore($canonical['store'] ?? [], $other['store'] ?? [], $contentSource),
            'categories' => $categories,
        ];
    }

    /**
     * Resolve one dish (the canonical or other-platform-only item) + its optional
     * cross-platform match into the persisted item shape — gap-filled display
     * fields, a platforms[] availability list, and the aggregate prices.
     *
     * $primarySource is the platform that supplied $item; $matched (when present)
     * is the SAME dish on the opposite platform.
     *
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>|null  $matched
     * @param  array<string, array{pickupUrl:?string, deliveryUrl:?string, storeUrl:?string, modes:list<string>}>  $storeLinks
     * @return array<string,mixed>
     */
    private function fuse(array $item, ?array $matched, string $primarySource, array $storeLinks, array $ghostPlatforms = []): array
    {
        // The Uber Eats-sourced + DoorDash-sourced versions of this dish — one is
        // the primary item, the other is the match (or null when unmatched).
        $ue = $primarySource === 'uber-eats' ? $item : $matched;
        $dd = $primarySource === 'doordash' ? $item : $matched;

        // platforms[] — one entry per platform this dish is on, in content-priority
        // order (Uber Eats first). A platform appears when either its scrape
        // carried this dish, OR it's a connected-but-unscraped ghost (attached to
        // every dish with null prices so it never disappears on a flaky scrape).
        $platforms = [];
        foreach (self::PLATFORMS as $platform) {
            $source = $platform === 'uber-eats' ? $ue : $dd;
            if (is_array($source)) {
                $platforms[] = $this->platformEntry($platform, $source, $storeLinks[$platform] ?? null);
            } elseif (isset($ghostPlatforms[$platform])) {
                $platforms[] = $this->platformEntry($platform, null, $ghostPlatforms[$platform]);
            }
        }

        $aggregates = $this->aggregates($platforms);

        // Display fields gap-fill: Uber Eats value where present, else DoorDash.
        $ueName = $this->str($ue['name'] ?? null);
        $ddName = $this->str($dd['name'] ?? null);

        return [
            'name' => $ueName ?? $ddName ?? (string) ($item['name'] ?? ''),
            'description' => ($this->str($ue['description'] ?? null)) ?? ($this->str($dd['description'] ?? null)),
            // Uber Eats image preferred; DoorDash fills the gap when UE has none.
            'imageUrl' => ($ue['image'] ?? null) ?? ($dd['image'] ?? null),
            'modifiers' => $ue['modifiers'] ?? null,                 // Uber Eats only
            'isSoldOut' => (bool) ($item['isSoldOut'] ?? false),
            'rating' => $dd['rating'] ?? null,                       // DoorDash only
            'ratingCount' => $dd['ratingCount'] ?? null,
            'badges' => $dd['badges'] ?? null,
            'basePrice' => $aggregates['basePrice'],
            'pickupPrice' => $aggregates['pickupPrice'],
            'pickupSource' => $aggregates['pickupSource'],
            'deliveryPrice' => $aggregates['deliveryPrice'],
            'deliverySource' => $aggregates['deliverySource'],
            'platforms' => $platforms,
            'ueExternalId' => $ue['externalId'] ?? null,
            'ddExternalId' => $dd['externalId'] ?? null,
        ];
    }

    /**
     * One platforms[] entry — the dish's availability on a single platform:
     * pickupPrice + pickupUrl, deliveryPrice + deliveryUrl. A mode the store
     * doesn't offer (per the store link's modes) is null on both price and url;
     * an offered mode always gets a url (the mode-typed link, else the bare store
     * url) even when its price is unknown (ghost / blocked mode scrape), so the
     * card still routes. $source is the scraped per-mode-priced item, or null for
     * a ghost platform.
     *
     * @param  array<string,mixed>|null  $source
     * @param  array{pickupUrl:?string, deliveryUrl:?string, storeUrl:?string, modes:list<string>}|null  $link
     * @return array{platform:string, pickupPrice:?float, pickupUrl:?string, deliveryPrice:?float, deliveryUrl:?string}
     */
    private function platformEntry(string $platform, ?array $source, ?array $link): array
    {
        $modes = $link['modes'] ?? ['pickup', 'delivery'];
        $offersPickup = in_array('pickup', $modes, true);
        $offersDelivery = in_array('delivery', $modes, true);
        $storeUrl = $link['storeUrl'] ?? null;

        return [
            'platform' => $platform,
            'pickupPrice' => $offersPickup && $source !== null ? $this->price($source['pickupPrice'] ?? null) : null,
            'pickupUrl' => $offersPickup ? ($link['pickupUrl'] ?? $storeUrl) : null,
            'deliveryPrice' => $offersDelivery && $source !== null ? $this->price($source['deliveryPrice'] ?? null) : null,
            'deliveryUrl' => $offersDelivery ? ($link['deliveryUrl'] ?? $storeUrl) : null,
        ];
    }

    /**
     * Aggregate prices over an item's platforms[]:
     *   basePrice     = cheapest price the dish can be had for (any mode, any platform),
     *   pickupPrice   = min pickupPrice across platforms,
     *   deliveryPrice = min deliveryPrice across platforms,
     * each *Source = the platform backing that min. Null when no platform prices
     * that mode.
     *
     * @param  list<array{platform:string, pickupPrice:?float, pickupUrl:?string, deliveryPrice:?float, deliveryUrl:?string}>  $platforms
     * @return array{basePrice:?float, pickupPrice:?float, pickupSource:?string, deliveryPrice:?float, deliverySource:?string}
     */
    private function aggregates(array $platforms): array
    {
        $base = $this->minMode($platforms, 'base');
        $pickup = $this->minMode($platforms, 'pickup');
        $delivery = $this->minMode($platforms, 'delivery');

        return [
            'basePrice' => $base['price'],
            'pickupPrice' => $pickup['price'],
            'pickupSource' => $pickup['source'],
            'deliveryPrice' => $delivery['price'],
            'deliverySource' => $delivery['source'],
        ];
    }

    /**
     * The lowest price (+ the platform backing it) for a mode across platforms.
     * 'pickup'/'delivery' look at that mode's price; 'base' takes the cheaper of
     * either mode per platform. First platform wins a tie (content priority =
     * Uber Eats over DoorDash).
     *
     * @param  list<array{platform:string, pickupPrice:?float, pickupUrl:?string, deliveryPrice:?float, deliveryUrl:?string}>  $platforms
     * @return array{price:?float, source:?string}
     */
    private function minMode(array $platforms, string $mode): array
    {
        $bestPrice = null;
        $bestSource = null;
        foreach ($platforms as $p) {
            $prices = match ($mode) {
                'pickup' => [$p['pickupPrice'] ?? null],
                'delivery' => [$p['deliveryPrice'] ?? null],
                default => [$p['pickupPrice'] ?? null, $p['deliveryPrice'] ?? null],
            };
            foreach ($prices as $price) {
                if ($price === null) {
                    continue;
                }
                // Strictly-less keeps the FIRST platform on a tie (content priority).
                if ($bestPrice === null || $price < $bestPrice) {
                    $bestPrice = $price;
                    $bestSource = $p['platform'];
                }
            }
        }

        return ['price' => $bestPrice, 'source' => $bestSource];
    }

    /**
     * Store-level fields: canonical platform first, other platform as fallback so
     * a missing rating/logo on one side is filled by the other.
     *
     * @param  array<string,mixed>  $canonical
     * @param  array<string,mixed>  $other
     * @return array<string,mixed>
     */
    private function mergeStore(array $canonical, array $other, string $contentSource): array
    {
        return [
            'name' => $canonical['name'] ?? $other['name'] ?? null,
            'rating' => $canonical['rating'] ?? $other['rating'] ?? null,
            'reviewCount' => $canonical['reviewCount'] ?? $other['reviewCount'] ?? null,
            'currency' => $canonical['currency'] ?? $other['currency'] ?? 'AUD',
            'logo' => $canonical['logo'] ?? $other['logo'] ?? null,
            'contentSource' => $contentSource,
        ];
    }

    /**
     * The other platform's categories with their already-matched items removed,
     * keeping only categories that still have leftover (unmatched) dishes. Each
     * item is keyed (category index + item index) the same way index() keys them
     * so $matched lookups line up.
     *
     * @param  list<array<string,mixed>>  $categories
     * @param  array<string,bool>  $matched  keys of items already fused into a canonical dish
     * @return list<array{name:string, items:list<array<string,mixed>>}>
     */
    private function leftovers(array $categories, array $matched): array
    {
        $out = [];
        foreach ($categories as $ci => $category) {
            $items = [];
            foreach ((array) ($category['items'] ?? []) as $ii => $item) {
                if (isset($matched[$ci.':'.$ii])) {
                    continue;
                }
                $items[] = $item;
            }
            if ($items !== []) {
                $out[] = ['name' => (string) ($category['name'] ?? 'Menu'), 'items' => $items];
            }
        }

        return $out;
    }

    /**
     * Build a lookup over the other platform's items: an exact normalized-name
     * map (first wins) plus a flat list for the fuzzy fallback. Each entry keeps a
     * stable `key` (categoryIndex:itemIndex) so a match can mark that source item
     * consumed (and excluded from the leftover append).
     *
     * @param  list<array<string,mixed>>  $categories
     * @return array{exact:array<string,array{key:string, item:array<string,mixed>}>, all:list<array{norm:string, key:string, item:array<string,mixed>}>}
     */
    private function index(array $categories): array
    {
        $exact = [];
        $all = [];
        foreach ($categories as $ci => $category) {
            foreach ((array) ($category['items'] ?? []) as $ii => $item) {
                $norm = $this->norm((string) ($item['name'] ?? ''));
                if ($norm === '') {
                    continue;
                }
                $key = $ci.':'.$ii;
                $exact[$norm] ??= ['key' => $key, 'item' => $item];
                $all[] = ['norm' => $norm, 'key' => $key, 'item' => $item];
            }
        }

        return ['exact' => $exact, 'all' => $all];
    }

    /**
     * The other platform's item matching this name, or null. An exact normalized
     * match wins; otherwise a containment match — the shorter name appears whole
     * inside the longer ("Margherita" ⊂ "Margherita Pizza", "Fries" ⊂ "Large
     * Fries"). Containment pairs trailing/leading qualifiers without pairing
     * similar-but-distinct dishes ("Beef Burrito" vs "Bean Burrito" share no
     * containment), which a fuzzy ratio would wrongly merge.
     *
     * @param  array{exact:array<string,array{key:string, item:array<string,mixed>}>, all:list<array{norm:string, key:string, item:array<string,mixed>}>}  $index
     * @return array{key:string, item:array<string,mixed>}|null
     */
    private function match(string $name, array $index): ?array
    {
        $norm = $this->norm($name);
        if ($norm === '') {
            return null;
        }
        if (isset($index['exact'][$norm])) {
            return $index['exact'][$norm];
        }

        foreach ($index['all'] as $candidate) {
            $other = $candidate['norm'];
            [$short, $long] = strlen($norm) <= strlen($other) ? [$norm, $other] : [$other, $norm];
            if (strlen($short) >= self::MIN_CONTAINMENT_LEN && str_contains($long, $short)) {
                return ['key' => $candidate['key'], 'item' => $candidate['item']];
            }
        }

        return null;
    }

    /** A numeric price → float, else null. */
    private function price(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** A non-empty trimmed string, or null. */
    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s !== '' ? $s : null;
    }

    /** lowercase, non-alphanumerics → single spaces, trimmed — for name matching. */
    private function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('~[^a-z0-9]+~', ' ', $s) ?? '';

        return trim((string) preg_replace('~\s+~', ' ', $s));
    }
}
