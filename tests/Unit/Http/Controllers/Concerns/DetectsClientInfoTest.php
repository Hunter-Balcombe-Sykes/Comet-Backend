<?php

use App\Http\Controllers\Concerns\DetectsClientInfo;
use Illuminate\Http\Request;

function makeClientInfoDetector(): object
{
    return new class
    {
        use DetectsClientInfo;

        public function lat(Request $r): ?float
        {
            return $this->detectLatitude($r);
        }

        public function lon(Request $r): ?float
        {
            return $this->detectLongitude($r);
        }

        public function deviceType(?string $ua): ?string
        {
            return $this->detectDeviceType($ua);
        }

        public function isBot(?string $ua): bool
        {
            return $this->isBotUserAgent($ua);
        }

        /** @return list<string> */
        public function botSignals(): array
        {
            return self::BOT_UA_SIGNALS;
        }
    };
}

function requestWithCoords(?string $lat, ?string $lon): Request
{
    $r = Request::create('/');
    if ($lat !== null) {
        $r->headers->set('X-Visitor-Lat', $lat);
    }
    if ($lon !== null) {
        $r->headers->set('X-Visitor-Lon', $lon);
    }

    return $r;
}

it('rounds a high-precision positive coordinate to 4 decimal places', function () {
    $detector = makeClientInfoDetector();
    // 5th decimal is 5 -> must round up, not truncate: 51.5007|59 -> 51.5008.
    $r = requestWithCoords('51.500759', null);

    expect($detector->lat($r))->toBe(51.5008);
});

it('rounds a negative coordinate away from zero, not toward it', function () {
    $detector = makeClientInfoDetector();
    // Remaining digits after the 4th decimal (851) are unambiguously > half,
    // so correct rounding increases the magnitude to -33.8689. A naive
    // toward-zero truncation would wrongly yield -33.8688.
    $r = requestWithCoords('-33.868851', null);

    expect($detector->lat($r))->toBe(-33.8689);
});

it('rounds negative longitude away from zero symmetrically with positive', function () {
    $detector = makeClientInfoDetector();
    $r = requestWithCoords(null, '-151.207359');

    expect($detector->lon($r))->toBe(-151.2074);
});

it('still rejects out-of-range coordinates as null', function () {
    $detector = makeClientInfoDetector();
    $r = requestWithCoords('95.0', '185.0');

    expect($detector->lat($r))->toBeNull();
    expect($detector->lon($r))->toBeNull();
});

it('still rejects non-numeric coordinates as null without throwing', function () {
    $detector = makeClientInfoDetector();
    $r = requestWithCoords('not-a-number', 'also-bad');

    expect($detector->lat($r))->toBeNull();
    expect($detector->lon($r))->toBeNull();
});

it('still returns null when the header is absent', function () {
    $detector = makeClientInfoDetector();
    $r = requestWithCoords(null, null);

    expect($detector->lat($r))->toBeNull();
    expect($detector->lon($r))->toBeNull();
});

// #SEM-6: detectDeviceType() used to re-test a 3-substring subset
// (bot/spider/crawler) of isBotUserAgent()'s 23-signal list instead of
// delegating — these 12 signals were reachable by isBotUserAgent() but not by
// detectDeviceType(), so a request from one of these UAs recorded
// device_type='desktop'/'mobile' instead of 'bot'.
dataset('previously missed bot signals', [
    'facebookexternalhit' => ['facebookexternalhit/1.1'],
    'yahoo slurp' => ['Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)'],
    'python-requests' => ['python-requests/2.31.0'],
    'python-urllib' => ['Python-urllib3/2.0'],
    'curl' => ['curl/8.4.0'],
    'wget' => ['Wget/1.21'],
    'libwww-perl' => ['libwww-perl/6.0'],
    'headlesschrome' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 HeadlessChrome/120.0.0.0 Safari/537.36'],
    'phantomjs' => ['Mozilla/5.0 (Unknown; Linux x86_64) AppleWebKit/538.1 (KHTML, like Gecko) PhantomJS/2.1.1 Safari/538.1'],
    'puppeteer' => ['Mozilla/5.0 Puppeteer/21.0'],
    'playwright' => ['Mozilla/5.0 Playwright/1.40'],
    'selenium' => ['Mozilla/5.0 (selenium-webdriver)'],
]);

it('classifies a previously-missed bot signal as bot', function (string $ua) {
    $detector = makeClientInfoDetector();

    expect($detector->deviceType($ua))->toBe('bot');
})->with('previously missed bot signals');

it('a mobile-emulating headless UA is bot, not mobile — bot check takes precedence', function () {
    $detector = makeClientInfoDetector();
    $ua = 'Mozilla/5.0 (Linux; Android 10; Mobile) AppleWebKit/537.36 HeadlessChrome/120.0.0.0 Mobile Safari/537.36';

    expect($detector->deviceType($ua))->toBe('bot');
});

it('regression: still classifies Googlebot, tablets, phones, and plain desktops correctly', function () {
    $detector = makeClientInfoDetector();

    expect($detector->deviceType('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'))->toBe('bot')
        ->and($detector->deviceType('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15'))->toBe('tablet')
        ->and($detector->deviceType('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15'))->toBe('mobile')
        ->and($detector->deviceType('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36'))->toBe('desktop')
        ->and($detector->deviceType('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15'))->toBe('desktop');
});

it('null UA stays null (unchanged); whitespace-only UA is now bot (CHANGED)', function () {
    $detector = makeClientInfoDetector();

    expect($detector->deviceType(null))->toBeNull()
        ->and($detector->deviceType('   '))->toBe('bot');
});

it('parity: every non-empty signal isBotUserAgent() catches, detectDeviceType() also classifies as bot', function () {
    $detector = makeClientInfoDetector();

    foreach ($detector->botSignals() as $signal) {
        expect($detector->isBot($signal))->toBeTrue();
        expect($detector->deviceType($signal))->toBe('bot');
    }
});
