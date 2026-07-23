<?php

namespace App\Services\WebsiteScan;

/**
 * Walks an already-fetched page's body and collects its visible text, with a
 * newline inserted at block-level element boundaries so a name/price/
 * description sequence (e.g. a menu item) keeps recognisable line structure
 * instead of collapsing into one run-on string. Feeds MenuAiExtractor's
 * text-in/items-out structuring call (the same one already used for PDF OCR
 * and Google-photo OCR text) as a general fallback for on-page HTML menus —
 * see MenuTextExtractor's header comment for why a narrow JSON-LD-only
 * lookup misses the vast majority of real sites.
 */
class VisibleTextExtractor
{
    // Mirrors MenuAiExtractor::MAX_OCR_TEXT_CHARS — same downstream call, same cap.
    private const MAX_CHARS = 60000;

    /** Tags whose own text is pure noise, never menu content. */
    private const SKIP_TAGS = ['script', 'style', 'noscript', 'svg'];

    /** Tags that force a line break so adjacent text doesn't run together. */
    private const BLOCK_TAGS = [
        'p', 'div', 'li', 'tr', 'td', 'th', 'br', 'hr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article', 'header', 'footer', 'ul', 'ol',
    ];

    public function extract(string $html): string
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
        if ($body === null) {
            return '';
        }

        $lines = [];
        $this->walk($body, $lines);

        $text = implode("\n", array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
        // Collapse 3+ blank-line runs (common once empty containers are
        // filtered) down to a single blank line, purely for token economy.
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return mb_substr($text, 0, self::MAX_CHARS);
    }

    /** @param  list<string>  $lines */
    private function walk(\DOMNode $node, array &$lines): void
    {
        if ($node instanceof \DOMElement && in_array(strtolower($node->tagName), self::SKIP_TAGS, true)) {
            return;
        }

        if ($node instanceof \DOMText) {
            $text = trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');
            if ($text !== '') {
                $lines[] = $text;
            }

            return;
        }

        foreach (iterator_to_array($node->childNodes ?? []) as $child) {
            $this->walk($child, $lines);
        }

        if ($node instanceof \DOMElement && in_array(strtolower($node->tagName), self::BLOCK_TAGS, true)) {
            $lines[] = '';
        }
    }
}
