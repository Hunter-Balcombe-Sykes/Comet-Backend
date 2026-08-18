<?php

namespace App\Ingest\Support;

/**
 * Category-grouped menu shape (the same {categories[] → items[]} structure
 * the legacy MenuPlatformDrivers produced) → flat landed records, one per
 * menu item, each carrying its category and position so grouping is
 * reconstructable at projection without a second table.
 *
 * Keys must be stable across runs: the vendor's item id when it exists,
 * otherwise a digest of category+name (Uber Eats' flattened list carries no
 * per-item id — the same limitation MenuMerger name-matched around).
 */
final class MenuRecords
{
    /**
     * Defensive on purpose: each connector maps its vendor shape first, but
     * a drifted actor payload must degrade to skipped rows, never a throw.
     *
     * @param  list<array<string, mixed>>  $categories
     * @return list<array{key: string, doc: array<string, mixed>}>
     */
    public static function flatten(array $categories, ?string $storeName, ?string $currency): array
    {
        $out = [];
        $categoryPosition = 0;

        foreach ($categories as $category) {
            $categoryName = trim((string) ($category['name'] ?? '')) ?: 'Menu';
            // Vendor order twice over (F30, 2026-08-18): the category's rank in
            // the menu and the dish's rank inside its category. Both are
            // seeds — an owner's reorder wins on the next run.
            $position = 0;

            foreach ((array) ($category['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $externalId = isset($item['externalId']) && is_string($item['externalId']) && $item['externalId'] !== ''
                    ? $item['externalId']
                    : null;

                $key = $externalId ?? substr(sha1(mb_strtolower($categoryName.'|'.$name)), 0, 16);

                $out[] = ['key' => $key, 'doc' => array_filter([
                    'external_id' => $externalId,
                    'name' => $name,
                    'description' => isset($item['description']) && is_string($item['description']) && trim($item['description']) !== ''
                        ? trim($item['description'])
                        : null,
                    'price' => is_numeric($item['price'] ?? null) ? round((float) $item['price'], 2) : null,
                    'currency' => isset($item['currency']) && is_string($item['currency']) && $item['currency'] !== ''
                        ? strtoupper($item['currency'])
                        : ($currency !== null ? strtoupper($currency) : null),
                    'image' => isset($item['image']) && is_string($item['image']) && $item['image'] !== '' ? $item['image'] : null,
                    'category' => $categoryName,
                    'category_position' => $categoryPosition,
                    'position' => $position++,
                    'store_name' => $storeName,
                ], static fn ($v) => $v !== null)];
            }
            $categoryPosition++;
        }

        return $out;
    }
}
