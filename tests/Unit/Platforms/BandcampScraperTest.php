<?php

use App\Services\Platforms\BandcampScraper;
use App\Services\SmartLinks\SafeUrlFetcher;

afterEach(function () {
    Mockery::close();
});

function bandcampScraperWith(string $html, int $status = 200): BandcampScraper
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn([
        'status' => $status, 'body' => $html, 'finalUrl' => 'https://artist.bandcamp.com/music', 'contentType' => 'text/html',
    ]);

    return new BandcampScraper($fetcher);
}

it('normalizes any bandcamp URL to its canonical origin', function () {
    $scraper = bandcampScraperWith('');

    expect($scraper->normalizeOrigin('https://KingGizzard.bandcamp.com/album/x?from=menu'))
        ->toBe('https://kinggizzard.bandcamp.com');
    expect($scraper->normalizeOrigin('http://artist.bandcamp.com'))
        ->toBe('https://artist.bandcamp.com');
    expect($scraper->normalizeOrigin('https://bandcamp.com/discover'))->toBeNull();
    expect($scraper->normalizeOrigin('https://example.com'))->toBeNull();
});

// Mirrors the real /music page grid: lazy images keep the art in
// data-original; the title <p> may carry an artist-override span.
it('parses the music grid into release items', function () {
    $html = <<<'HTML'
<html><head>
<meta property="og:title" content="Music | Mock Artist">
<meta property="og:image" content="https://f4.bcbits.com/img/avatar_1.jpg">
</head><body>
<ol id="music-grid">
<li data-item-id="album-111" data-band-id="9" class="music-grid-item square first-four" data-bind="css: {}">
  <a href="/album/first-record">
    <div class="art"><img class="lazy" src="/img/0.gif" data-original="https://f4.bcbits.com/img/a111_2.jpg" alt=""></div>
    <p class="title">First Record</p>
  </a>
</li>
<li data-item-id="album-222" class="music-grid-item">
  <a href="https://other.bandcamp.com/album/guest?label=1">
    <div class="art"><img src="https://f4.bcbits.com/img/a222_2.jpg" alt=""></div>
    <p class="title">
      Guest Record
      <br><span class="artist-override">Other Artist</span>
    </p>
  </a>
</li>
<li data-item-id="track-333" class="music-grid-item">
  <a href="/track/single"><div class="art"></div><p class="title">A Single</p></a>
</li>
</ol>
</body></html>
HTML;

    $profile = bandcampScraperWith($html)->fetchProfile('https://artist.bandcamp.com');

    expect($profile['name'])->toBe('Mock Artist');
    expect($profile['thumbnail'])->toBe('https://f4.bcbits.com/img/avatar_1.jpg');
    expect($profile['items'])->toHaveCount(3);
    expect($profile['items'][0])->toMatchArray([
        'itemId' => 'album-111',
        'name' => 'First Record',
        'thumbnail' => 'https://f4.bcbits.com/img/a111_2.jpg',
        'link' => 'https://artist.bandcamp.com/album/first-record',
    ]);
    // Absolute hrefs (label pages) pass through untouched.
    expect($profile['items'][1]['link'])->toBe('https://other.bandcamp.com/album/guest?label=1');
    // No art → null thumbnail, item still kept.
    expect($profile['items'][2]['thumbnail'])->toBeNull();
});

it('returns null when the page is unreachable', function () {
    expect(bandcampScraperWith('', 503)->fetchProfile('https://artist.bandcamp.com'))->toBeNull();
});
