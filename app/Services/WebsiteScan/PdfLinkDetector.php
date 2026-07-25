<?php

namespace App\Services\WebsiteScan;

use App\Services\Http\UrlAbsolutizer;

/**
 * Finds absolute, deduped .pdf-suffixed anchor hrefs on an already-fetched
 * page — feeds the website menu-PDF OCR path. Returns each link's anchor text
 * alongside its URL (menu-relevance filtering happens against both — a link's
 * own text, e.g. "Wine List", is often the only menu signal since the URL
 * path itself is frequently an opaque CMS-generated slug).
 */
class PdfLinkDetector
{
    /** @return list<array{url: string, text: string}> */
    public function find(string $html, string $baseUrl): array
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        $seen = [];
        foreach ($xpath->query('//a[@href]') as $node) {
            $href = trim((string) $node->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            // Anchor hrefs, unlike img srcs, carry contact schemes — reject before absolutizing.
            if (str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }
            $abs = UrlAbsolutizer::absolutize($href, $baseUrl);
            if ($abs === null || ! $this->isPdfPath($abs)) {
                continue;
            }
            if (! isset($seen[$abs])) {
                $seen[$abs] = trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');
            }
        }

        return array_map(fn ($url, $text) => ['url' => $url, 'text' => $text], array_keys($seen), array_values($seen));
    }

    private function isPdfPath(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_ends_with(strtolower($path), '.pdf');
    }
}
