<?php

namespace App\Services\WebsiteScan;

/** Finds absolute, deduped .pdf-suffixed anchor hrefs on an already-fetched page — feeds the website menu-PDF OCR path. */
class PdfLinkDetector
{
    /** @return list<string> */
    public function find(string $html, string $baseUrl): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        $seen = [];
        foreach ($xpath->query('//a/@href') as $attr) {
            $href = trim((string) $attr->nodeValue);
            if ($href === '') {
                continue;
            }
            $abs = $this->absolutize($href, $baseUrl);
            if ($abs === null || ! $this->isPdfPath($abs)) {
                continue;
            }
            $seen[$abs] = true;
        }

        return array_keys($seen);
    }

    private function isPdfPath(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_ends_with(strtolower($path), '.pdf');
    }

    private function absolutize(string $href, string $baseUrl): ?string
    {
        if ($href === '' || str_starts_with($href, 'data:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return null;
        }
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        $base = parse_url($baseUrl);
        if (! isset($base['scheme'], $base['host'])) {
            return null;
        }
        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

        return $href[0] === '/' ? $origin.$href : $origin.'/'.ltrim($href, '/');
    }
}
