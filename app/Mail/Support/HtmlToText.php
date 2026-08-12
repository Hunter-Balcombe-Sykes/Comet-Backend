<?php

namespace App\Mail\Support;

/**
 * Derives a plain-text alternative part from a rendered HTML email.
 *
 * Every outbound HTML message gets a text/plain part (P3, 2026-08-12) — the
 * single biggest deliverability lever the audit found (spam filters score
 * HTML-only mail down, and text-only clients/watch previews need it). One
 * generic converter rather than 31 hand-written text views: the templates are
 * simple enough (headline, paragraphs, one CTA) that a mechanical conversion
 * reads fine, and it can never drift out of date with the HTML.
 */
final class HtmlToText
{
    public static function convert(string $html): string
    {
        // Comments first — kills the MSO conditional blocks (VML buttons)
        // before their inner markup can leak into the text.
        $text = (string) preg_replace('/<!--.*?-->/s', '', $html);

        // Head, styles, scripts.
        $text = (string) preg_replace('/<head\b.*?<\/head>/is', '', $text);
        $text = (string) preg_replace('/<(style|script)\b.*?<\/\1>/is', '', $text);

        // Hidden elements — the preheader div must not surface in the text part.
        $text = (string) preg_replace('/<div[^>]*display\s*:\s*none[^>]*>.*?<\/div>/is', '', $text);

        // Anchors: keep the destination. "label (url)" unless the label IS the
        // url or it's a mailto (where the address is already the label).
        $text = (string) preg_replace_callback(
            '/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is',
            function (array $m): string {
                $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
                $label = trim(strip_tags($m[2]));
                // Image-only anchors (the header logo) have no text label —
                // drop them rather than leading the text part with a bare URL.
                if ($label === '') {
                    return '';
                }
                if (str_starts_with($href, 'mailto:') || $label === $href) {
                    return $label;
                }

                return "{$label} ({$href})";
            },
            $text
        );

        // Structural breaks before stripping tags.
        $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = (string) preg_replace('/<\/(p|h[1-6]|tr|table|li|blockquote)>/i', "\n\n", $text);

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        // Non-breaking spaces + zero-width chars (preheader padding uses both).
        $text = str_replace(["\u{00a0}", "\u{200c}", "\u{200b}"], ' ', $text);

        // Collapse horizontal whitespace per line, then runs of blank lines.
        $lines = array_map(
            fn (string $line): string => trim((string) preg_replace('/[ \t]+/', ' ', $line)),
            explode("\n", $text)
        );
        $text = implode("\n", $lines);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
