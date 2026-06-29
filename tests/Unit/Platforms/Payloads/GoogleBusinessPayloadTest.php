<?php

use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('preserves the stored map verbatim (lossless toArray)', function () {
    $raw = [
        'url' => 'https://maps.google/x', 'name' => 'Fade Lab', 'lat' => -37.8, 'lng' => 144.9,
        'placeId' => 'ChIJ', 'rating' => 4.7, 'apifyStatus' => 'ok',
        'syncFindings' => [['platform' => 'opentable', 'outcome' => 'seeded']],
    ];

    expect(GoogleBusinessPayload::fromArray($raw)->toArray())->toBe($raw);
});

it('exposes typed accessors and tolerates absence', function () {
    $dto = GoogleBusinessPayload::fromArray(['name' => 'Fade Lab', 'placeId' => 'ChIJ', 'apifyStatus' => 'pending']);
    expect($dto->name())->toBe('Fade Lab');
    expect($dto->placeId())->toBe('ChIJ');
    expect($dto->apifyStatus())->toBe('pending');
    expect($dto->syncFindings())->toBe([]);

    $empty = GoogleBusinessPayload::fromArray(null);
    expect($empty->name())->toBeNull();
    expect($empty->toArray())->toBe([]);
});

it('syncFindings returns the verbatim findings list or [] for garbage', function () {
    $findings = [['platform' => 'facebook', 'outcome' => 'conflict']];
    expect(GoogleBusinessPayload::fromArray(['syncFindings' => $findings])->syncFindings())->toBe($findings);
    expect(GoogleBusinessPayload::fromArray(['syncFindings' => 'nope'])->syncFindings())->toBe([]);
});

it('resource output is identical whether fed the raw map or the DTO round-trip (variable keys preserved)', function () {
    // A legacy link-parse selection: only the 5 base keys, NO enrichment keys.
    $legacy = ['url' => 'https://maps.google/x', 'name' => 'Fade Lab', 'address' => '1 St', 'lat' => -37.8, 'lng' => 144.9];
    expect((new GoogleBusinessConnectionResource(GoogleBusinessPayload::fromArray($legacy)->toArray()))->resolve())
        ->toBe((new GoogleBusinessConnectionResource($legacy))->resolve());

    // An enriched selection: enrichment keys present must survive.
    $enriched = [...$legacy, 'placeId' => 'ChIJ', 'rating' => 4.7, 'apifyStatus' => 'ok'];
    $out = (new GoogleBusinessConnectionResource(GoogleBusinessPayload::fromArray($enriched)->toArray()))->resolve();
    expect($out)->toBe((new GoogleBusinessConnectionResource($enriched))->resolve());
    expect($out)->toHaveKey('rating');
});
