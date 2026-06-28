<?php

use App\Http\Resources\Platforms\NowBookitConnectionResource;
use App\Http\Resources\Platforms\OpenTableConnectionResource;
use App\Http\Resources\Platforms\ResDiaryConnectionResource;
use App\Services\Platforms\Payloads\FreshaSelection;
use App\Services\Platforms\Payloads\SelectionPayload;
use Tests\TestCase;

// The resource-output-equivalence cases below call JsonResource::resolve(), which
// injects a Request via the container — so this Unit file must boot the app.
// tests/Pest.php only binds TestCase ->in('Feature'); Unit files opt in here
// (mirrors tests/Unit/Platforms/Payloads/FeedPayloadTest.php).
uses(TestCase::class)->in(__FILE__);

// ── FreshaSelection (verbatim inner blob + typed accessors) ──────────────────

it('FreshaSelection preserves the inner blob verbatim (lossless toArray)', function () {
    $raw = [
        'url' => 'https://www.fresha.com/a/acme',
        'storeName' => 'Acme',
        'mode' => 'employee',
        'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo'],
        'services' => [['serviceId' => 's:1', 'name' => 'Cut']],
        'hiddenServiceIds' => ['s:2'],
        '_legacyExtra' => 'kept-verbatim', // a stray key must survive (public passes selection verbatim)
    ];

    expect(FreshaSelection::fromArray($raw)->toArray())->toBe($raw);
});

it('FreshaSelection exposes typed accessors over the raw blob', function () {
    $sel = FreshaSelection::fromArray([
        'url' => 'https://www.fresha.com/a/acme',
        'storeName' => 'Acme',
        'mode' => 'storewide',
        'employee' => null,
        'services' => [['serviceId' => 's:1']],
        'hiddenServiceIds' => ['s:1'],
    ]);

    expect($sel->url())->toBe('https://www.fresha.com/a/acme');
    expect($sel->storeName())->toBe('Acme');
    expect($sel->mode())->toBe('storewide');
    expect($sel->employee())->toBeNull();
    expect($sel->services())->toBe([['serviceId' => 's:1']]);
    expect($sel->hiddenServiceIds())->toBe(['s:1']);
});

it('FreshaSelection accessors are lenient — missing keys return null / empty', function () {
    $sel = FreshaSelection::fromArray(['url' => 'https://www.fresha.com/a/acme']);

    expect($sel->storeName())->toBeNull();
    expect($sel->mode())->toBeNull();        // the resource defaults a null mode to 'employee'
    expect($sel->employee())->toBeNull();
    expect($sel->services())->toBe([]);
    expect($sel->hiddenServiceIds())->toBe([]);
});

// ── SelectionPayload: Fresha two-level envelope ──────────────────────────────

it('SelectionPayload hydrates the Fresha {url, selection} envelope', function () {
    $payload = SelectionPayload::fromArray([
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => [
            'url' => 'https://www.fresha.com/a/acme',
            'storeName' => 'Acme',
            'mode' => 'employee',
            'employee' => ['employeeId' => 'e1'],
            'services' => [['serviceId' => 's:1']],
            'hiddenServiceIds' => [],
        ],
    ]);

    expect($payload->url)->toBe('https://www.fresha.com/a/acme');
    expect($payload->selection)->toBeInstanceOf(FreshaSelection::class);
    expect($payload->selection->storeName())->toBe('Acme');
    expect($payload->selection->mode())->toBe('employee');
});

it('SelectionPayload treats a pending Fresha row (selection null) as no inner blob', function () {
    $payload = SelectionPayload::fromArray([
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => null,
    ]);

    expect($payload->url)->toBe('https://www.fresha.com/a/acme');
    expect($payload->selection)->toBeNull();
});

// ── SelectionPayload: flat reservation / square shapes ───────────────────────

it('SelectionPayload hydrates flat reservation fields', function () {
    $payload = SelectionPayload::fromArray([
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
        'rid' => '266537',
        'name' => 'Ollies',
        'embedUrl' => 'https://www.opentable.com.au/widget/reservation/canvas?rid=266537',
        'source' => 'manual',
    ]);

    expect($payload->url)->toContain('266537');
    expect($payload->rid)->toBe('266537');
    expect($payload->name)->toBe('Ollies');
    expect($payload->embedUrl)->toContain('rid=266537');
    expect($payload->source)->toBe('manual');
    expect($payload->selection)->toBeNull();   // no Fresha inner blob
    expect($payload->microsite)->toBeNull();
});

it('SelectionPayload hydrates a bare {url} (Square)', function () {
    $payload = SelectionPayload::fromArray(['url' => 'https://book.squareup.com/appointments/x']);

    expect($payload->url)->toBe('https://book.squareup.com/appointments/x');
    expect($payload->rid)->toBeNull();
    expect($payload->selection)->toBeNull();
});

it('SelectionPayload coerces non-string scalars to null', function () {
    $payload = SelectionPayload::fromArray(['url' => 123, 'rid' => ['nested']]);

    expect($payload->url)->toBeNull();
    expect($payload->rid)->toBeNull();
});

// ── Resource-output equivalence (the reservation read-path contract guard) ───
// Tasks 4-5 re-point OT/RD/NB /selection to GenericPlatformController, which serves
// Resource(SelectionPayload::fromArray($payload)->toArray()). Prove that equals
// Resource($rawPayload) for each reservation resource, so the route flip is
// provably byte-identical.

it('OpenTable resource output is identical via the DTO round-trip', function () {
    $raw = [
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
        'rid' => '266537',
        'name' => 'Ollies',
        'embedUrl' => 'https://www.opentable.com.au/widget/reservation/canvas?rid=266537&domain=comau&iframe=true',
        'source' => 'manual',
    ];

    $viaDto = (new OpenTableConnectionResource(SelectionPayload::fromArray($raw)->toArray()))->resolve();
    $direct = (new OpenTableConnectionResource($raw))->resolve();

    expect($viaDto)->toBe($direct);
    expect($viaDto)->toBe([
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
        'rid' => '266537',
        'name' => 'Ollies',
        'embedUrl' => 'https://www.opentable.com.au/widget/reservation/canvas?rid=266537&domain=comau&iframe=true',
    ]);
});

it('ResDiary resource output is identical via the DTO round-trip', function () {
    $raw = [
        'url' => 'https://booking.resdiary.com/widget/Standard/Ollies',
        'microsite' => 'Ollies',
        'name' => 'Ollies',
        'embedUrl' => 'https://booking.resdiary.com/widget/Standard/Ollies',
        'source' => 'manual',
    ];

    expect((new ResDiaryConnectionResource(SelectionPayload::fromArray($raw)->toArray()))->resolve())
        ->toBe((new ResDiaryConnectionResource($raw))->resolve());
});

it('NowBookit resource output is identical via the DTO round-trip', function () {
    $raw = [
        'url' => 'https://booking.nowbookit.com/steps/sitting-details?accountid=12&venueid=34',
        'accountId' => '12',
        'venueId' => '34',
        'name' => 'Ollies',
        'embedUrl' => 'https://booking.nowbookit.com/widget?accountid=12&venueid=34',
        'source' => 'manual',
    ];

    expect((new NowBookitConnectionResource(SelectionPayload::fromArray($raw)->toArray()))->resolve())
        ->toBe((new NowBookitConnectionResource($raw))->resolve());
});
