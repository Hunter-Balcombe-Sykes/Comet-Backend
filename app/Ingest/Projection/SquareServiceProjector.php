<?php

namespace App\Ingest\Projection;

use App\Ingest\Support\Text;

/**
 * Square Appointments service → the `service` kind (2026-09-02). Price
 * arrives in cents already converted to a float by SquareBookingPage; the
 * qualifier ('exact' | 'from') is the connector's call because only it sees
 * the variations. Categories carry vendor tokens but the projector keeps the
 * id-less tag rule the Treatwell projector documents.
 */
class SquareServiceProjector implements Projector
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
        $price = $view->float('price');
        $currency = $view->string('currency');
        $currency = is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
        $qualifier = $view->string('price_qualifier') === 'from' ? 'from' : 'exact';
        $offer = $price === null ? null : [
            'channel' => 'square',
            'qualifier' => $price === 0.0 ? 'free' : $qualifier,
            'amount_minor' => (int) round($price * 100),
            'currency' => $currency,
        ];
        $seconds = $view->int('duration_seconds');
        $category = $view->string('category');
        $description = $view->string('description');
        $url = $view->string('url');

        return [
            'kind' => self::kind(),
            'headline' => $name,
            'facets' => array_filter([
                'f_text' => $description === null ? null : ['body' => mb_substr($description, 0, Text::MAX_LENGTH)],
                'f_link' => $url === null ? null : ['url' => $url],
                'f_duration' => $seconds === null || $seconds <= 0 ? null : ['seconds' => $seconds],
            ]),
            'tags' => $category === null ? [] : [['tag' => $category, 'tag_type' => 'category']],
            'offers' => $offer === null ? [] : [$offer],
        ];
    }
}
