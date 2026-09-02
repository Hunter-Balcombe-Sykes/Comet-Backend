<?php

namespace App\Ingest\Projection;

use App\Ingest\Support\Text;

/**
 * Treatwell offer → the `service` kind (T27b, 2026-08-28). Structured price/
 * currency/duration arrive clean from the schema.org block; categories carry
 * no vendor ids, so they ride as tags only (the same id-less rule the Fresha
 * projector documents for its collections).
 */
class TreatwellServiceProjector implements Projector
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
            'channel' => 'treatwell',
            'qualifier' => $price === 0.0 ? 'free' : 'exact',
            'amount_minor' => (int) round($price * 100),
            'currency' => $currency,
        ];

        $seconds = $view->int('duration_seconds');
        $category = $view->string('category');

        return [
            'kind' => self::kind(),
            'headline' => $name,
            'facets' => array_filter([
                'f_text' => $view->string('description') === null ? null : ['body' => mb_substr((string) $view->string('description'), 0, Text::MAX_LENGTH)],
                'f_link' => $view->string('url') === null ? null : ['url' => $view->string('url')],
                'f_duration' => $seconds === null || $seconds <= 0 ? null : ['seconds' => $seconds],
            ]),
            'tags' => $category === null ? [] : [['tag' => $category, 'tag_type' => 'category']],
            'offers' => $offer === null ? [] : [$offer],
        ];
    }
}
