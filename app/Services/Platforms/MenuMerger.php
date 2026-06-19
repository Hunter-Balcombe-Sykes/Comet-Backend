<?php

namespace App\Services\Platforms;

// Fuses the two platform menus (from MenuApifyScraper) into the single persisted
// menu. The CONTENT platform (Uber Eats preferred) supplies the canonical
// structure — categories, names, descriptions, images, modifiers. For each dish
// we look up its counterpart on the OTHER platform by name (mild-strict match)
// and use it to:
//   • fill a missing image (Uber Eats image preferred, DoorDash fills the gap),
//   • attach the DoorDash 👍 rating + badges (Uber Eats exposes neither), and
//   • price the opposite mode.
//
// Per-mode pricing follows the user's platform→mode mapping (the Google-harvested
// `type` on their ordering links): pickup_price comes from whichever platform is
// the pickup platform, delivery_price from the delivery platform. When a mode has
// no platform (only one platform connected, or that mode untyped) its price stays
// null and the dish shows its base price.
class MenuMerger
{
    // Mild-strict matching needs at least this many characters in the shorter
    // name before a containment match counts — stops short tokens ("Naan",
    // "Cola") pairing every dish that happens to include the word.
    private const MIN_CONTAINMENT_LEN = 5;

    /**
     * @param  array{store:array<string,mixed>, categories:list<array<string,mixed>>}|null  $ueMenu
     * @param  array{store:array<string,mixed>, categories:list<array<string,mixed>>}|null  $ddMenu
     * @return array{store:array<string,mixed>, categories:list<array{name:string, sourcePlatform:string, items:list<array<string,mixed>>}>}
     */
    public function merge(?array $ueMenu, ?array $ddMenu, string $contentSource, ?string $pickupPlatform, ?string $deliveryPlatform): array
    {
        $canonical = ($contentSource === 'uber-eats' ? $ueMenu : $ddMenu) ?? ['store' => [], 'categories' => []];
        $other = $contentSource === 'uber-eats' ? $ddMenu : $ueMenu;

        $index = $this->index($other['categories'] ?? []);

        $categories = [];
        foreach ($canonical['categories'] as $category) {
            $items = [];
            foreach ((array) ($category['items'] ?? []) as $item) {
                $matched = $this->match((string) ($item['name'] ?? ''), $index);
                $items[] = $this->fuse($item, $matched, $contentSource, $pickupPlatform, $deliveryPlatform);
            }
            if ($items !== []) {
                $categories[] = [
                    'name' => (string) ($category['name'] ?? 'Menu'),
                    'sourcePlatform' => $contentSource,
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
     * Resolve one canonical dish + its (optional) cross-platform match into the
     * persisted item shape.
     *
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>|null  $matched
     * @return array<string,mixed>
     */
    private function fuse(array $item, ?array $matched, string $contentSource, ?string $pickupPlatform, ?string $deliveryPlatform): array
    {
        // The Uber Eats-sourced + DoorDash-sourced versions of this dish — one is
        // the canonical item, the other is the match (or null when unmatched).
        $ue = $contentSource === 'uber-eats' ? $item : $matched;
        $dd = $contentSource === 'doordash' ? $item : $matched;

        $priceFor = static function (?string $platform) use ($ue, $dd): ?float {
            $price = match ($platform) {
                'uber-eats' => $ue['price'] ?? null,
                'doordash' => $dd['price'] ?? null,
                default => null,
            };

            return is_numeric($price) ? (float) $price : null;
        };
        $pickupPrice = $priceFor($pickupPlatform);
        $deliveryPrice = $priceFor($deliveryPlatform);

        return [
            'name' => (string) $item['name'],
            'description' => $item['description'] ?? ($matched['description'] ?? null),
            // Uber Eats image preferred; DoorDash fills the gap when UE has none.
            'imageUrl' => ($ue['image'] ?? null) ?? ($dd['image'] ?? null),
            'modifiers' => $ue['modifiers'] ?? null,                 // Uber Eats only
            'isSoldOut' => (bool) ($item['isSoldOut'] ?? false),
            'rating' => $dd['rating'] ?? null,                       // DoorDash only
            'ratingCount' => $dd['ratingCount'] ?? null,
            'badges' => $dd['badges'] ?? null,
            'basePrice' => isset($item['price']) && is_numeric($item['price']) ? (float) $item['price'] : null,
            'pickupPrice' => $pickupPrice,
            'pickupSource' => $pickupPrice !== null ? $pickupPlatform : null,
            'deliveryPrice' => $deliveryPrice,
            'deliverySource' => $deliveryPrice !== null ? $deliveryPlatform : null,
            'ueExternalId' => $ue['externalId'] ?? null,
            'ddExternalId' => $dd['externalId'] ?? null,
        ];
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
     * Build a lookup over the other platform's items: an exact normalized-name
     * map (first wins) plus a flat list for the fuzzy fallback.
     *
     * @param  list<array<string,mixed>>  $categories
     * @return array{exact:array<string,array<string,mixed>>, all:list<array{norm:string, item:array<string,mixed>}>}
     */
    private function index(array $categories): array
    {
        $exact = [];
        $all = [];
        foreach ($categories as $category) {
            foreach ((array) ($category['items'] ?? []) as $item) {
                $norm = $this->norm((string) ($item['name'] ?? ''));
                if ($norm === '') {
                    continue;
                }
                $exact[$norm] ??= $item;
                $all[] = ['norm' => $norm, 'item' => $item];
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
     * @param  array{exact:array<string,array<string,mixed>>, all:list<array{norm:string, item:array<string,mixed>}>}  $index
     * @return array<string,mixed>|null
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
                return $candidate['item'];
            }
        }

        return null;
    }

    /** lowercase, non-alphanumerics → single spaces, trimmed — for name matching. */
    private function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('~[^a-z0-9]+~', ' ', $s) ?? '';

        return trim((string) preg_replace('~\s+~', ' ', $s));
    }
}
