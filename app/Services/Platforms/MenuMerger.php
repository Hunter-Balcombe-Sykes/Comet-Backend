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
//   • collects BOTH platforms into `platforms[]` (each with its price, the
//     pickup/delivery modes that platform's store link supports, and that
//     platform's order url),
//   • attaches the DoorDash 👍 rating + badges (Uber Eats exposes neither), and
//   • carries Uber Eats modifiers (DoorDash exposes none).
//
// Aggregate prices are derived from `platforms[]`:
//   basePrice     = min price across the item's platforms (headline),
//   pickupPrice   = min price among platforms whose store offers PICKUP,
//   deliveryPrice = min price among platforms whose store offers DELIVERY,
// each *Source tagged with the platform backing that min (back-compat). A mode
// with no offering platform stays null.
//
// Per-platform modes + urls come from $storeLinks (MenuSource::storeLinks) — the
// consolidated ordering-link set per platform, where one store's pickup +
// delivery rows have already collapsed into a single {pickupUrl, deliveryUrl,
// storeUrl, modes} set.
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
                $items[] = $this->fuse($item, $matched['item'] ?? null, $contentSource, $storeLinks);
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
                $items[] = $this->fuse($item, null, $otherSource, $storeLinks);
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
    private function fuse(array $item, ?array $matched, string $primarySource, array $storeLinks): array
    {
        $otherSource = $primarySource === 'uber-eats' ? 'doordash' : 'uber-eats';

        // The Uber Eats-sourced + DoorDash-sourced versions of this dish — one is
        // the primary item, the other is the match (or null when unmatched).
        $ue = $primarySource === 'uber-eats' ? $item : $matched;
        $dd = $primarySource === 'doordash' ? $item : $matched;

        // platforms[] — one entry per platform this dish is actually on, in
        // content-priority order, each with its price + the modes/url of that
        // platform's consolidated store link.
        $platforms = [];
        foreach (self::PLATFORMS as $platform) {
            $source = $platform === 'uber-eats' ? $ue : $dd;
            if (! is_array($source)) {
                continue;
            }
            $link = $storeLinks[$platform] ?? null;
            $platforms[] = [
                'platform' => $platform,
                'price' => $this->price($source['price'] ?? null),
                // The modes this platform's store supports; both when no store
                // link resolved (shouldn't happen — the dish came from a scrape
                // of that store — but stay safe and offer both).
                'modes' => $link['modes'] ?? ['pickup', 'delivery'],
                // Prefer the pickup-typed link, else delivery, else the store url.
                'url' => $link['pickupUrl'] ?? $link['deliveryUrl'] ?? $link['storeUrl'] ?? null,
            ];
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
     * Aggregate prices over an item's platforms[]:
     *   basePrice     = min price across all platforms (headline),
     *   pickupPrice   = min price among platforms whose modes include 'pickup',
     *   deliveryPrice = min price among platforms whose modes include 'delivery',
     * each *Source = the platform backing that min. Null when no platform offers
     * a price (or that mode).
     *
     * @param  list<array{platform:string, price:?float, modes:list<string>, url:?string}>  $platforms
     * @return array{basePrice:?float, pickupPrice:?float, pickupSource:?string, deliveryPrice:?float, deliverySource:?string}
     */
    private function aggregates(array $platforms): array
    {
        $base = $this->minPrice($platforms, null);
        $pickup = $this->minPrice($platforms, 'pickup');
        $delivery = $this->minPrice($platforms, 'delivery');

        return [
            'basePrice' => $base['price'],
            'pickupPrice' => $pickup['price'],
            'pickupSource' => $pickup['source'],
            'deliveryPrice' => $delivery['price'],
            'deliverySource' => $delivery['source'],
        ];
    }

    /**
     * The lowest priced platform entry, optionally restricted to those offering a
     * given mode. Returns the price + the platform backing it (first platform
     * wins a tie, keeping content-priority order = Uber Eats over DoorDash).
     *
     * @param  list<array{platform:string, price:?float, modes:list<string>, url:?string}>  $platforms
     * @return array{price:?float, source:?string}
     */
    private function minPrice(array $platforms, ?string $mode): array
    {
        $bestPrice = null;
        $bestSource = null;
        foreach ($platforms as $p) {
            if ($p['price'] === null) {
                continue;
            }
            if ($mode !== null && ! in_array($mode, $p['modes'], true)) {
                continue;
            }
            // Strictly-less keeps the FIRST platform on a tie (content priority).
            if ($bestPrice === null || $p['price'] < $bestPrice) {
                $bestPrice = $p['price'];
                $bestSource = $p['platform'];
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
