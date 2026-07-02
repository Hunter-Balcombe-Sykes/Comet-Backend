<?php

use App\Services\Platforms\LinkCardScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('builds a minimal card from the URL with no HTTP', function () {
    Http::fake();
    $card = app(LinkCardScraper::class)->minimalCard('https://www.ubereats.com/store/x');

    expect($card['url'])->toBe('https://www.ubereats.com/store/x')
        ->and($card['name'])->toBe('ubereats.com')
        ->and($card['favicon'])->toContain('google.com/s2/favicons');
    Http::assertNothingSent();
});
