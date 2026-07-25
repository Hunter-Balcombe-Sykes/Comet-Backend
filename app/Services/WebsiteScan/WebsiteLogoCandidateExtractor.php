<?php

namespace App\Services\WebsiteScan;

use App\Services\Http\UrlAbsolutizer;

/**
 * Best-effort static-HTML equivalent of the deleted EvidenceConclusions::
 * logoCandidates() — everything except rendered-geometry filtering (top
 * 250px / >=400px^2), which needs real layout this plan deliberately
 * doesn't have. Output feeds the unchanged LogoAutoGrabber::grabIfEmpty():
 * confirmed that class never reads natW/natH/w/h at all for raster
 * candidates (slotRejection() gates on the DOWNLOADED image's real
 * dimensions, not anything candidate-declared), and for inline-svg it falls
 * back to viewBox when w/h are null (svgAspect()) — so leaving those fields
 * null here, as this extractor always does, needs no further handling on
 * LogoAutoGrabber's side.
 */
class WebsiteLogoCandidateExtractor
{
    private const MAX_CANDIDATES = 16;

    private const MAX_SVG_BYTES = 60000;

    /** Cap on the doc-wide logo-hinted rescue pass (see headerElementCandidates). */
    private const MAX_HINTED_EXTRAS = 6;

    public function extract(string $html, string $baseUrl): array
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        $candidates = [
            ...$this->iconCandidates($xpath, $baseUrl),
            ...$this->manifestCandidates($xpath, $baseUrl),
            ...$this->metaImageCandidates($xpath, $baseUrl),
            ...$this->headerElementCandidates($xpath, $doc, $baseUrl),
        ];

        return array_slice($candidates, 0, self::MAX_CANDIDATES);
    }

    private function iconCandidates(\DOMXPath $xpath, string $baseUrl): array
    {
        $out = [];
        foreach ($xpath->query('//link[@rel]') as $link) {
            // '//link[@rel]' is a tag-name node test — DOMXPath never returns
            // namespace nodes for it, so this is always a DOMElement at runtime.
            if (! $link instanceof \DOMElement) {
                continue;
            }
            $rel = strtolower((string) $link->getAttribute('rel'));
            if (! str_contains($rel, 'icon')) {
                continue;
            }
            $href = UrlAbsolutizer::absolutize(trim((string) $link->getAttribute('href')), $baseUrl);
            if ($href === null) {
                continue;
            }
            $out[] = [
                'kind' => str_contains($rel, 'apple-touch') ? 'apple-touch' : 'icon',
                'url' => $href,
                'sizes' => (string) $link->getAttribute('sizes'),
                'type' => (string) $link->getAttribute('type'),
            ];
        }

        return $out;
    }

    private function manifestCandidates(\DOMXPath $xpath, string $baseUrl): array
    {
        $out = [];
        foreach ($xpath->query('//link[translate(@rel,"MANIFEST","manifest")="manifest"]') as $link) {
            // Tag-name node test on 'link' — always a DOMElement at runtime.
            if (! $link instanceof \DOMElement) {
                continue;
            }
            $href = UrlAbsolutizer::absolutize(trim((string) $link->getAttribute('href')), $baseUrl);
            if ($href !== null) {
                $out[] = ['kind' => 'manifest', 'url' => $href];
            }
        }

        return $out;
    }

    private function metaImageCandidates(\DOMXPath $xpath, string $baseUrl): array
    {
        $out = [];
        foreach (['og:image' => 'og-image', 'twitter:image' => 'twitter-image'] as $property => $kind) {
            foreach ($xpath->query("//meta[@property=\"{$property}\" or @name=\"{$property}\"]") as $meta) {
                // Tag-name node test on 'meta' — always a DOMElement at runtime.
                if (! $meta instanceof \DOMElement) {
                    continue;
                }
                $url = UrlAbsolutizer::absolutize(trim((string) $meta->getAttribute('content')), $baseUrl);
                if ($url !== null) {
                    $out[] = ['kind' => $kind, 'url' => $url];
                }
            }
        }

        return $out;
    }

    private function headerElementCandidates(\DOMXPath $xpath, \DOMDocument $doc, string $baseUrl): array
    {
        $header = $xpath->query('//header')->item(0)
            ?? $xpath->query('//*[contains(translate(@class,"NAV","nav"),"nav")]')->item(0);
        $inHeader = $header !== null;
        $scope = $header ?? $doc->documentElement;
        if ($scope === null) {
            return [];
        }

        $out = [];
        foreach ($xpath->query('.//img', $scope) as $img) {
            // Tag-name node test on 'img' — always a DOMElement at runtime.
            if (! $img instanceof \DOMElement) {
                continue;
            }
            $src = UrlAbsolutizer::absolutize(trim((string) $img->getAttribute('src')), $baseUrl);
            if ($src === null) {
                continue;
            }
            $hint = $this->looksLikeLogo(
                (string) $img->getAttribute('class').' '.(string) $img->getAttribute('id').' '.
                (string) $img->getAttribute('alt').' '.$src
            );
            $out[] = [
                'kind' => 'header-img', 'url' => $src, 'natW' => null, 'natH' => null,
                'alt' => (string) $img->getAttribute('alt'), 'hint' => $hint, 'inHeader' => $inHeader,
            ];
        }

        foreach ($xpath->query('.//svg', $scope) as $svg) {
            // Tag-name node test on 'svg' — always a DOMElement at runtime.
            if (! $svg instanceof \DOMElement) {
                continue;
            }
            $svgHtml = $doc->saveHTML($svg);
            if ($svgHtml === false || strlen($svgHtml) > self::MAX_SVG_BYTES) {
                continue;
            }
            $hint = $this->looksLikeLogo((string) $svg->getAttribute('class').' '.(string) $svg->getAttribute('id'));
            $out[] = [
                // DOMDocument::loadHTML() parses via libxml's HTML parser, which
                // lowercases attribute names (confirmed directly, not assumed) —
                // "viewBox" on the source element is only readable as "viewbox" here.
                'kind' => 'inline-svg', 'svg' => $svgHtml, 'w' => null, 'h' => null,
                'viewBox' => (string) $svg->getAttribute('viewbox'), 'hint' => $hint, 'inHeader' => $inHeader,
            ];
        }

        // Doc-wide rescue pass (2026-07-23, signup-v2 Phase B live find): the
        // real branding often lives OUTSIDE the first semantic <header> —
        // Squarespace puts the logo <img> in a div-classed .Header-branding
        // block, so the scoped pass above collected only that header's UI icon
        // SVGs and the true logo never became a candidate (live supernormal
        // case). Sweep the whole document for imgs whose class/id/alt/src
        // explicitly hints logo/branding, deduped against the scoped pass and
        // bounded — hint=+8 trust already outranks bare icons downstream.
        $seen = [];
        foreach ($out as $candidate) {
            if (isset($candidate['url'])) {
                $seen[$candidate['url']] = true;
            }
        }
        $extras = 0;
        foreach ($xpath->query('//img') as $img) {
            if ($extras >= self::MAX_HINTED_EXTRAS) {
                break;
            }
            if (! $img instanceof \DOMElement) {
                continue;
            }
            $src = UrlAbsolutizer::absolutize(trim((string) $img->getAttribute('src')), $baseUrl);
            if ($src === null || isset($seen[$src])) {
                continue;
            }
            $haystack = strtolower(
                (string) $img->getAttribute('class').' '.(string) $img->getAttribute('id').' '.
                (string) $img->getAttribute('alt').' '.$src
            );
            if (! str_contains($haystack, 'logo') && ! str_contains($haystack, 'branding')) {
                continue;
            }
            $seen[$src] = true;
            $out[] = [
                'kind' => 'header-img', 'url' => $src, 'natW' => null, 'natH' => null,
                'alt' => (string) $img->getAttribute('alt'), 'hint' => true, 'inHeader' => false,
            ];
            $extras++;
        }

        return $out;
    }

    private function looksLikeLogo(string $haystack): bool
    {
        return str_contains(strtolower($haystack), 'logo');
    }
}
