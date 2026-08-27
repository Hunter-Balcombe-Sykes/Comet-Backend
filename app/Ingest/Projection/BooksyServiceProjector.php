<?php

namespace App\Ingest\Projection;

/**
 * Booksy schema.org offer → the `service` kind (T27b, 2026-08-28). Numeric
 * price + ISO currency arrive structured (no Fresha-style string parsing);
 * Booksy's block carries no duration or category, so those facets simply
 * don't emit.
 */
class BooksyServiceProjector implements Projector
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
        $offer = $price === null ? null : [
            'channel' => 'booksy',
            'qualifier' => $price === 0.0 ? 'free' : 'exact',
            'amount_minor' => (int) round($price * 100),
            'currency' => $currency,
        ];

        $image = $view->string('image');

        return [
            'kind' => self::kind(),
            'headline' => $name,
            'facets' => array_filter([
                'f_link' => $view->string('url') === null ? null : ['url' => $view->string('url')],
            ]),
            'offers' => $offer === null ? [] : [$offer],
            'media' => $image === null ? [] : [['role' => 'cover', 'url' => $image]],
        ];
    }
}
