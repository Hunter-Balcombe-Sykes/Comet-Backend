<?php

namespace App\Services\WebsiteScan;

/**
 * Heuristic "About"/"Our Story" heading → following-paragraph prose
 * extraction — distinct from AboutTextExtractor, which only reads
 * structured JSON-LD/meta description fields. This one reads actual
 * authored prose off the page, which is why it's preferred over Google's
 * editorial summary when both are available (see WorkplaceContentApplier::
 * applyProseDescription()'s overwrite-unless-manual precedence).
 *
 * Deliberately narrow: matches a heading (h1-h4) whose text contains an
 * "about"/"our story" keyword, then collects paragraph text from its
 * following-sibling elements (direct <p> siblings, or <p> nested one level
 * inside a sibling wrapper div/section — the two most common real-world
 * heading+prose markup shapes). Sites that split the heading and its prose
 * across non-sibling containers won't be picked up — a general layout-aware
 * parser is out of scope for a plain-HTTP-fetch pipeline with no headless
 * render.
 */
class AboutProseExtractor
{
    private const HEADING_KEYWORDS = ['about', 'our story'];

    private const MAX_PARAGRAPHS = 3;

    private const MAX_CHARS = 1000;

    /** XPath 1.0 has no case-insensitive contains(); fold via translate(). */
    private const LOWER_FOLD = 'translate(., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';

    public function extract(string $html): ?string
    {
        $doc = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOENT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($doc);
        $heading = $this->findAboutHeading($xpath);
        if ($heading === null) {
            return null;
        }

        $paragraphs = $this->collectFollowingParagraphs($xpath, $heading);
        if ($paragraphs === []) {
            return null;
        }

        return $this->clamp(implode("\n\n", $paragraphs));
    }

    private function findAboutHeading(\DOMXPath $xpath): ?\DOMElement
    {
        $conditions = [];
        foreach (self::HEADING_KEYWORDS as $keyword) {
            $conditions[] = 'contains('.self::LOWER_FOLD.', "'.$keyword.'")';
        }
        $query = '//h1[%1$s]|//h2[%1$s]|//h3[%1$s]|//h4[%1$s]';
        $query = sprintf($query, implode(' or ', $conditions));

        $node = $xpath->query($query)->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    /** @return list<string> */
    private function collectFollowingParagraphs(\DOMXPath $xpath, \DOMElement $heading): array
    {
        $paragraphs = [];
        $sibling = $heading->nextSibling;
        $checked = 0;

        while ($sibling !== null && count($paragraphs) < self::MAX_PARAGRAPHS && $checked < 12) {
            if ($sibling instanceof \DOMElement) {
                $checked++;
                $tag = strtolower($sibling->tagName);
                // Another heading ends this section's prose run.
                if (preg_match('/^h[1-6]$/', $tag) === 1) {
                    break;
                }

                if ($tag === 'p') {
                    $this->pushIfSubstantial($paragraphs, $sibling->textContent);
                } else {
                    // One level of indirection — a sibling wrapper div/section
                    // holding the actual <p> tags is a common real-world shape.
                    foreach ($xpath->query('.//p', $sibling) as $inner) {
                        if (count($paragraphs) >= self::MAX_PARAGRAPHS) {
                            break;
                        }
                        if ($inner instanceof \DOMElement) {
                            $this->pushIfSubstantial($paragraphs, $inner->textContent);
                        }
                    }
                }
            }
            $sibling = $sibling->nextSibling;
        }

        return $paragraphs;
    }

    /** @param list<string> $paragraphs */
    private function pushIfSubstantial(array &$paragraphs, string $text): void
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        // A handful of words is noise (a nav label, a button caption picked up
        // by the one-level-indirection widen) — real prose runs longer.
        if (mb_strlen($text) >= 30) {
            $paragraphs[] = $text;
        }
    }

    private function clamp(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX_CHARS);
        $lastSpace = mb_strrpos($cut, ' ');

        return ($lastSpace !== false ? mb_substr($cut, 0, $lastSpace) : $cut).'…';
    }
}
