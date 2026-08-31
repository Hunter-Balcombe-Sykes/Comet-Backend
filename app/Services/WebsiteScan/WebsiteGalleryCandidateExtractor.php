<?php

namespace App\Services\WebsiteScan;

use App\Services\Http\UrlAbsolutizer;

/**
 * Harvests candidate content-photo URLs from an already-fetched page —
 * <img> tags outside the header/nav/footer chrome (those are the logo
 * extractor's territory), filtered by src-pattern noise (icons, sprites,
 * tracking pixels, data: URIs) and declared width/height when the markup
 * carries it. Real size/aspect validation happens downstream against the
 * DOWNLOADED bytes (GalleryAutoGrabber), same division of labour as
 * WebsiteLogoCandidateExtractor → LogoAutoGrabber.
 */
class WebsiteGalleryCandidateExtractor
{
    private const MAX_CANDIDATES = 20;

    /** Declared px below this on EITHER axis (when declared at all) is almost certainly chrome, not a photo. */
    private const MIN_DECLARED_PX = 150;

    /** src/class/alt substrings that flag decorative chrome rather than content photography. */
    private const NOISE_PATTERNS = ['icon', 'logo', 'sprite', 'pixel', 'spacer', 'avatar', 'badge', 'tracking'];

    /** @return list<string> absolute, deduped candidate photo URLs, document order */
    public function extract(string $html, string $baseUrl): array
    {
        if (trim($html) === '') {
            return [];
        }

        $doc = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET (#FU-1): $html is a third party's scraped page — this
        // blocks any network access libxml would make while parsing (external
        // entity/DTD/stylesheet), same flag and reasoning as MetadataParser,
        // AboutProseExtractor and WebsiteLinkHarvester::extractLinks().
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($doc);
        $seen = [];
        $out = [];

        foreach ($xpath->query('//img[not(ancestor::header) and not(ancestor::nav) and not(ancestor::footer)]') as $img) {
            if (! $img instanceof \DOMElement || count($out) >= self::MAX_CANDIDATES) {
                break;
            }

            $src = trim($img->getAttribute('src')) ?: trim($img->getAttribute('data-src'));
            $abs = UrlAbsolutizer::absolutize($src, $baseUrl);
            if ($abs === null || isset($seen[$abs])) {
                continue;
            }

            $haystack = strtolower($abs.' '.$img->getAttribute('class').' '.$img->getAttribute('alt'));
            if ($this->looksLikeNoise($haystack)) {
                continue;
            }

            if ($this->declaredTooSmall($img)) {
                continue;
            }

            $seen[$abs] = true;
            $out[] = $abs;
        }

        return $out;
    }

    private function looksLikeNoise(string $haystack): bool
    {
        foreach (self::NOISE_PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function declaredTooSmall(\DOMElement $img): bool
    {
        $width = $this->intAttr($img, 'width');
        $height = $this->intAttr($img, 'height');

        return ($width !== null && $width < self::MIN_DECLARED_PX)
            || ($height !== null && $height < self::MIN_DECLARED_PX);
    }

    private function intAttr(\DOMElement $el, string $name): ?int
    {
        $value = trim($el->getAttribute($name));

        return ($value !== '' && ctype_digit($value)) ? (int) $value : null;
    }
}
