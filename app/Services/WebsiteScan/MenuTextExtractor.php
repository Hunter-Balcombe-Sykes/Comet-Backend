<?php

namespace App\Services\WebsiteScan;

use App\Services\Http\MetadataParser;

/**
 * Extracts menu items from an already-fetched homepage's schema.org Menu
 * JSON-LD (Menu.hasMenuSection[].hasMenuItem[], recursing into nested
 * subsections) — reusing MetadataParser rather than a new hand-rolled
 * parser. Output matches MenuScanApplier::apply()'s $items shape exactly.
 */
class MenuTextExtractor
{
    public function __construct(private MetadataParser $parser) {}

    /**
     * @return list<array{name:string, description:?string, price:?float, category:?string}>
     */
    public function extract(string $html, string $baseUrl): array
    {
        $parsed = $this->parser->parse($html, $baseUrl);
        $menu = $parsed->jsonLdOfType('Menu');
        if ($menu === null) {
            return [];
        }

        $items = [];
        $this->walkSections($menu, null, $items);

        return $items;
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  list<array{name:string, description:?string, price:?float, category:?string}>  $items
     */
    private function walkSections(array $node, ?string $sectionName, array &$items): void
    {
        foreach ($this->asList($node['hasMenuItem'] ?? null) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $description = is_string($item['description'] ?? null) ? trim($item['description']) : '';

            $items[] = [
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'price' => $this->firstPrice($item['offers'] ?? null),
                'category' => $sectionName,
            ];
        }

        foreach ($this->asList($node['hasMenuSection'] ?? null) as $section) {
            if (! is_array($section)) {
                continue;
            }
            $childName = is_string($section['name'] ?? null) ? trim($section['name']) : $sectionName;
            $this->walkSections($section, $childName !== '' ? $childName : $sectionName, $items);
        }
    }

    /** A JSON-LD field that's sometimes a single object, sometimes a list — normalize to a list. */
    private function asList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }

    private function firstPrice(mixed $offers): ?float
    {
        foreach ($this->asList($offers) as $offer) {
            $price = is_array($offer) ? ($offer['price'] ?? null) : null;
            if (is_numeric($price)) {
                return (float) $price;
            }
        }

        return null;
    }
}
