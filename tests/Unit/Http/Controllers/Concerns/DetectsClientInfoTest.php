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
