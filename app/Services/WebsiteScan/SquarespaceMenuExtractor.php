<?php

namespace App\Services\WebsiteScan;

/**
 * Zero-cost, precise fast-path for Squarespace's own native restaurant-menu
 * block (`.menu-item`/`.menu-item-title`/`.menu-item-description`/
 * `.menu-section-title` — confirmed stable, hand-authored markup, the exact
 * shape that exposed MenuTextExtractor's JSON-LD-only gap). Tried before the
 * general AI-structuring fallback (WebsiteMenuHtmlScanJob) since it costs
 * nothing and is exact when it hits — never widened into a general
 * multi-CMS selector library (see the 2026-07-23 signup-testing-repairs plan,
 * item 6: every other platform researched either has no stable convention or
 * isn't reachable by a plain HTTP fetch at all).
 */
class SquarespaceMenuExtractor
{
    /**
     * @return list<array{name: string, description: ?string, price: ?float, category: ?string, dietary: ?list<string>}>
     */
    public function extract(string $html): array
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        // LIBXML_NONET (#FU-1): $html is a third party's scraped page — this
        // blocks any network access libxml would make while parsing (external
        // entity/DTD/stylesheet), same flag and reasoning as MetadataParser,
        // AboutProseExtractor and WebsiteLinkHarvester::extractLinks().
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        $items = [];
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " menu-item ")]') as $itemNode) {
            $title = $this->firstMatchText($xpath, $itemNode, 'menu-item-title');
            if ($title === null) {
                continue;
            }
            $description = $this->firstMatchText($xpath, $itemNode, 'menu-item-description');
            $category = $this->nearestSectionTitle($xpath, $itemNode);

            $items[] = [
                'name' => mb_substr($title, 0, 160),
                'description' => $description,
                'price' => $description !== null ? $this->extractPrice($description) : null,
                'category' => $category,
                'dietary' => null,
            ];
        }

        return $items;
    }

    private function firstMatchText(\DOMXPath $xpath, \DOMNode $context, string $class): ?string
    {
        $nodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]', $context);
        $node = $nodes !== false ? $nodes->item(0) : null;
        if ($node === null) {
            return null;
        }
        $text = trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');

        return $text !== '' ? $text : null;
    }

    // Nearest preceding .menu-section-title anywhere earlier in the document
    // — Squarespace nests items inside a .menu-section, but walking up to the
    // section ancestor and back down is more fragile than a documequery
    // "closest preceding heading" pass across the whole page.
    private function nearestSectionTitle(\DOMXPath $xpath, \DOMNode $itemNode): ?string
    {
        $section = $xpath->query('ancestor::*[contains(concat(" ", normalize-space(@class), " "), " menu-section ")][1]', $itemNode);
        $sectionNode = $section !== false ? $section->item(0) : null;
        if ($sectionNode === null) {
            return null;
        }

        return $this->firstMatchText($xpath, $sectionNode, 'menu-section-title');
    }

    private function extractPrice(string $description): ?float
    {
        if (preg_match('/(\d+(?:\.\d{1,2})?)/', $description, $m) !== 1) {
            return null;
        }

        return round((float) $m[1], 2);
    }
}
