<?php

namespace App\Ingest\Projection;

/**
 * Fresha booking-flow service → the `service` item kind.
 *
 * Fresha serves price and duration as DISPLAY strings ("from A$120",
 * "1h 30min"), so the typed columns are parsed conservatively: a shape this
 * parser does not recognise yields no offer/duration row rather than a wrong
 * number — a missing price renders honestly, an invented one is a lie on a
 * booking page (plan §6: offers are never fabricated, `from` stays `from`).
 */
class FreshaServiceProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'service';
    }

    public function project(RecordView $view): ?array
    {
        $name = $view->string('name');
        if ($name === null) {
            return null;
        }

        $category = $view->string('category');
        $duration = $this->durationSeconds($view->string('duration'));
        $offer = $this->offer($view->string('price'));

        return [
            'kind' => self::kind(),
            'headline' => $name,
            'facets' => array_filter([
                'f_text' => $view->string('description') === null ? null : ['body' => $view->string('description')],
                'f_duration' => $duration === null ? null : ['seconds' => $duration],
            ]),
            'tags' => $category === null ? [] : [['tag' => $category, 'tag_type' => 'category']],
            'offers' => $offer === null ? [] : [$offer],
        ];
    }

    /** "1h 30min" | "45min" | "2h" → seconds; anything else → null. */
    private function durationSeconds(?string $caption): ?int
    {
        if ($caption === null) {
            return null;
        }

        $hours = preg_match('/(\d+)\s*h/i', $caption, $h) ? (int) $h[1] : 0;
        $minutes = preg_match('/(\d+)\s*min/i', $caption, $m) ? (int) $m[1] : 0;

        return ($hours + $minutes) > 0 ? $hours * 3600 + $minutes * 60 : null;
    }

    /**
     * "A$58" | "from A$120" | "Free" → a typed offer, qualifiers preserved.
     *
     * @return array<string, mixed>|null
     */
    private function offer(?string $price): ?array
    {
        if ($price === null || trim($price) === '') {
            return null;
        }
        $price = trim($price);

        if (strcasecmp($price, 'free') === 0) {
            return ['channel' => 'fresha', 'qualifier' => 'free', 'amount_minor' => 0, 'currency' => null];
        }

        if (! preg_match('/^(?<from>from\s+)?(?<currency>A?\$)\s*(?<amount>\d+(?:\.\d{1,2})?)$/i', $price, $m)) {
            return null;
        }

        return [
            'channel' => 'fresha',
            'qualifier' => ($m['from'] ?? '') !== '' ? 'from' : 'exact',
            'amount_minor' => (int) round(((float) $m['amount']) * 100),
            // "A$" is unambiguous; a bare "$" is not, and never USD-defaults.
            'currency' => strcasecmp($m['currency'], 'A$') === 0 ? 'AUD' : null,
        ];
    }
}
