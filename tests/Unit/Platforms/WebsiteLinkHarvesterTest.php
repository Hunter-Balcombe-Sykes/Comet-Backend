<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\WebsiteLinkHarvester;

function harvesterFor(string $html): WebsiteLinkHarvester
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn([
        'status' => 200,
        'body' => $html,
        'finalUrl' => 'https://example.com.au/',
    ]);

    return new WebsiteLinkHarvester($fetcher);
}

it('classifies socials, reservations, ordering and booking links off a homepage', function () {
    $html = <<<'HTML'
    <html><body>
      <a href="https://www.instagram.com/docpizza">IG</a>
      <a href="https://www.facebook.com/docpizzabar">FB</a>
      <a href="https://www.facebook.com/sharer/sharer.php?u=x">share widget</a>
      <a href="https://www.opentable.com.au/r/doc-pizza?rid=12345">Book a table</a>
      <a href="https://www.ubereats.com/au/store/doc-pizza/abc">Order</a>
      <a href="https://www.fresha.com/a/doc-cuts">Book a cut</a>
      <a href="/menu">Menu</a>
      <a href="mailto:hi@example.com">Email</a>
    </body></html>
    HTML;

    $out = harvesterFor($html)->harvest('https://example.com.au');

    expect($out['socials']['instagram'])->toBe('https://www.instagram.com/docpizza')
        ->and($out['socials']['facebook'])->toBe('https://www.facebook.com/docpizzabar')
        ->and($out['reservation']['links'][0]['url'])->toContain('opentable.com.au')
        ->and($out['order']['providers'][0]['name'])->toBe('Uber Eats')
        ->and($out['booking'][0])->toContain('fresha.com');
});

it('ignores share widgets and bare social domains', function () {
    $html = '<a href="https://twitter.com/intent/tweet?x=1">t</a><a href="https://instagram.com/">bare</a>';

    expect(harvesterFor($html)->harvest('https://example.com.au'))->toBe([]);
});

it('returns nothing for a missing or non-http website', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $harvester = new WebsiteLinkHarvester($fetcher);

    expect($harvester->harvest(null))->toBe([])
        ->and($harvester->harvest('not-a-url'))->toBe([]);
});
