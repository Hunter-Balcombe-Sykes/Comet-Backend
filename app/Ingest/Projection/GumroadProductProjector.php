<?php

namespace App\Ingest\Projection;

/**
 * Gumroad storefront product → the `product` item kind. Pay-what-you-want
 * prices are a floor, so their qualifier is `from`; a zero floor is `free`
 * (§6: offers are a set with honest qualifiers, never a resolved scalar).
 */
class GumroadProductProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'product';
    }

    public function project(RecordView $view): ?array
    {
        $name = $view->string('name');
        $url = $view->string('url');
        if ($name === null || $url === null) {
            return null;
        }

        $priceCents = $view->int('price_cents');
        $pwyw = $view->bool('pay_what_you_want') === true;

        $rating = $view->float('rating');

        return [
            'kind' => self::kind(),
            'headline' => $name,
            'facets' => array_filter([
                'f_link' => ['url' => $url],
                'f_rated' => $rating === null ? null : array_filter([
                    'rating' => $rating,
                    'rating_max' => 5.0,
                    'ratings_count' => $view->int('ratings_count'),
                ], static fn ($v) => $v !== null),
            ]),
            'offers' => $priceCents === null ? [] : [[
                'amount_minor' => $priceCents,
                'currency' => $view->string('currency'),
                'qualifier' => $priceCents === 0 ? 'free' : ($pwyw ? 'from' : 'exact'),
                'url' => $url,
            ]],
            'media' => $view->string('thumbnail') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('thumbnail')],
            ],
        ];
    }
}
