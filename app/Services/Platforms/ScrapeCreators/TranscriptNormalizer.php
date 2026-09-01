<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11e (2026-09-01): four transcript endpoints, four response dialects —
// TikTok answers a WEBVTT cue sheet, Facebook an SRT one (numbered cues; the
// vendor's docs show plain text, so both dialects are accepted), YouTube a
// segment array plus transcript_only_text, Instagram a per-slide transcripts
// array (carousels = one entry per video slide, text null when no speech).
// All of it flattens to ONE contract — {text, language, source} — because the
// consumers are AI-enrichment passes that eat prose, never players that need
// cue timing. Timing is deliberately discarded, not preserved.
//
// Contract-lossy like every normalizer in this directory: any missing or odd
// shape → null → the caller reads a vendor miss. A NotFound husk bills with
// success:true and simply lacks the transcript keys, so gating on positive
// text presence covers it; an empty or whitespace-only transcript is ALSO a
// miss ("no speech detected" must never persist as an empty transcript).
class TranscriptNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one transcript-endpoint response
     * @return array{text: string, language: string|null, source: string}|null
     */
    public function normalize(string $platform, array $body): ?array
    {
        $text = match ($platform) {
            'instagram' => $this->fromSlides($body['transcripts'] ?? null),
            'tiktok', 'facebook' => $this->fromCueSheet($body['transcript'] ?? null),
            'youtube' => $this->flatten($body['transcript_only_text'] ?? null),
            default => null,
        };
        if ($text === null) {
            return null;
        }

        // Only YouTube names its caption track ("English", the full word —
        // not a code); elsewhere the key rides as null rather than being
        // omitted, so consumers never need isset() gymnastics.
        $language = $platform === 'youtube' ? $this->flatten($body['language'] ?? null) : null;

        return ['text' => $text, 'language' => $language, 'source' => $platform];
    }

    /**
     * WEBVTT and SRT share the same skeleton: timing lines (`-->`), optional
     * bare-integer cue indexes, a WEBVTT header, blank separators — and the
     * speech itself on everything else. Dropping the skeleton and flowing the
     * rest back together reconstructs sentences that the cue wrapping split
     * mid-clause. A spoken line that is ONLY a bare integer is lost with the
     * indexes — acceptable under the lossy contract.
     */
    private function fromCueSheet(mixed $transcript): ?string
    {
        if (! is_string($transcript)) {
            return null;
        }

        $speech = [];
        foreach (preg_split('/\R/u', $transcript) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'WEBVTT' || str_contains($line, '-->') || preg_match('/^\d+$/', $line) === 1) {
                continue;
            }
            $speech[] = $line;
        }

        return $this->flatten(implode(' ', $speech));
    }

    /** Carousel slides are distinct videos — kept as separate paragraphs, speechless slides skipped. */
    private function fromSlides(mixed $transcripts): ?string
    {
        if (! is_array($transcripts)) {
            return null;
        }

        $slides = [];
        foreach ($transcripts as $slide) {
            $text = is_array($slide) ? $this->flatten($slide['text'] ?? null) : null;
            if ($text !== null) {
                $slides[] = $text;
            }
        }

        return $slides === [] ? null : implode("\n\n", $slides);
    }

    /** One line of prose: runs of whitespace collapsed (YouTube double-pads every word), empty → null. */
    private function flatten(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : $text;
    }
}
