<?php

use App\Services\SmartLinks\SafeUrlFetcher;
use App\Services\SmartLinks\SmartLinkResolver;

/** A fetcher that returns canned responses by URL substring — no network/DNS. */
function fakeFetcher(array $responses): SafeUrlFetcher
{
    return new class($responses) extends SafeUrlFetcher
    {
        public function __construct(private array $responses) {}

        public function fetch(string $url, array $headers = []): array
        {
            foreach ($this->responses as $needle => $resp) {
                if (str_contains($url, $needle)) {
                    return $resp + ['finalUrl' => $url];
                }
            }

            return ['status' => 404, 'body' => '', 'finalUrl' => $url, 'contentType' => ''];
        }
    };
}

it('resolves a Shopify product through the full chain', function () {
    $this->app->instance(SafeUrlFetcher::class, fakeFetcher([
        '/products/widget.json' => [
            'status' => 200,
            'contentType' => 'application/json',
            'body' => json_encode(['product' => [
                'title' => 'Widget',
                'vendor' => 'ACME',
                'images' => [['src' => 'https://cdn.shop.com/w.jpg']],
                'variants' => [['price' => '35.00', 'available' => true]],
            ]]),
        ],
        '/meta.json' => [
            'status' => 200,
            'contentType' => 'application/json',
            'body' => json_encode(['currency' => 'AUD']),
        ],
    ]));

    $resolved = app(SmartLinkResolver::class)->resolve('https://shop.com/products/widget?variant=99', 'product');

    expect($resolved->valid)->toBeTrue()
        ->and($resolved->type)->toBe('commerce.product')
        ->and($resolved->platform)->toBe('shopify')
        ->and($resolved->data->title)->toBe('Widget')
        ->and($resolved->data->metadata['price'])->toBe(35.0)
        ->and($resolved->data->metadata['currency'])->toBe('AUD')
        ->and($resolved->data->metadata['stockStatus'])->toBe('in_stock')
        ->and($resolved->url->canonical)->toBe('https://shop.com/products/widget');
});

it('returns invalid when the link cannot be read', function () {
    $this->app->instance(SafeUrlFetcher::class, fakeFetcher([])); // everything 404s

    $resolved = app(SmartLinkResolver::class)->resolve('https://shop.com/products/ghost', 'product');

    expect($resolved->valid)->toBeFalse()
        ->and($resolved->reason)->not->toBeNull();
});

it('rejects a selection/URL mismatch before fetching', function () {
    $resolved = app(SmartLinkResolver::class)->resolve('https://www.youtube.com/watch?v=abc', 'spotify');

    expect($resolved->valid)->toBeFalse();
});

it('resolves a Spotify track via OG with artist + album (no oEmbed)', function () {
    $html = '<html><head>'
        .'<meta property="og:title" content="Loving Is Easy"/>'
        .'<meta property="og:image" content="https://i.scdn.co/image/abc"/>'
        .'<meta property="og:description" content="Rex Orange County · Apricot Princess · Song · 2017"/>'
        .'<meta name="music:musician_description" content="Rex Orange County"/>'
        .'</head><body></body></html>';

    $this->app->instance(SafeUrlFetcher::class, fakeFetcher([
        'open.spotify.com/track/abc' => [
            'status' => 200,
            'contentType' => 'text/html',
            'body' => $html,
        ],
    ]));

    $resolved = app(SmartLinkResolver::class)->resolve('https://open.spotify.com/track/abc?si=xyz', 'spotify');

    expect($resolved->valid)->toBeTrue()
        ->and($resolved->type)->toBe('content.music.track')
        ->and($resolved->platform)->toBe('spotify')
        ->and($resolved->data->title)->toBe('Loving Is Easy')
        ->and($resolved->data->metadata['artist'])->toBe('Rex Orange County')
        ->and($resolved->data->metadata['album'])->toBe('Apricot Princess');
});
