<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Contact link-outs (mailto:/tel:) as click events.
//
// The tracker fires on any anchor whose origin differs from the page's, and
// `mailto:`/`tel:` URLs parse to the opaque origin "null" — so every contact
// tap has always reached /public/analytics/clicks. ClickRequest then validated
// the destination with `url:http,https`, which rejects both schemes, so the
// single most valuable conversion signal a professional page emits has never
// recorded a row. These tests pin the accepted shape AND the normalisation
// that keeps analytics.link_clicks.url one destination per contact point.
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupLinkClicksTable();
    Queue::fake();
});

it('records a tel: tap and normalises the dialled number to one destination', function () {
    $tenant = createTenant('tel-tap');

    $this->withHeader('Origin', 'https://tel-tap.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/clicks', [
            'site_id' => $tenant->site->id,
            'visitor_id' => (string) Str::uuid(),
            'url' => 'tel:+61 (400) 000-000',
            'section_key' => 'contact',
            'label' => 'Call',
        ])->assertStatus(201);

    $row = DB::connection('pgsql')->table('analytics.link_clicks')->first();
    expect($row)->not->toBeNull()
        ->and($row->url)->toBe('tel:+61400000000')
        ->and($row->section_key)->toBe('contact');
});

it('records a mailto: click and drops the subject/body template from the destination', function () {
    $tenant = createTenant('mailto-click');

    $this->withHeader('Origin', 'https://mailto-click.'.config('partna.public_domain'))
        ->postJson('/api/public/analytics/clicks', [
            'site_id' => $tenant->site->id,
            'visitor_id' => (string) Str::uuid(),
            'url' => 'mailto:Hello@Example.COM?subject=Booking%20enquiry&body=Hi%20there',
            'section_key' => 'contact',
        ])->assertStatus(201);

    $row = DB::connection('pgsql')->table('analytics.link_clicks')->first();
    expect($row)->not->toBeNull()
        ->and($row->url)->toBe('mailto:hello@example.com');
});

it('collapses two spellings of one dialled number onto a single dedup key', function () {
    $tenant = createTenant('tel-dedup');
    $visitorId = (string) Str::uuid();
    $origin = 'https://tel-dedup.'.config('partna.public_domain');

    $base = ['site_id' => $tenant->site->id, 'visitor_id' => $visitorId];

    // Same digits, two keyboards. One tap, one row.
    $this->withHeader('Origin', $origin)
        ->postJson('/api/public/analytics/clicks', $base + ['url' => 'tel:+61 400 000 000'])
        ->assertStatus(201);
    $this->withHeader('Origin', $origin)
        ->postJson('/api/public/analytics/clicks', $base + ['url' => 'tel:+61(400)000-000'])
        ->assertStatus(201);

    // Normalisation has to happen BEFORE the dedup target is hashed, or the same
    // tap spelled two ways mints two keys and the 3s dedup window never fires.
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->pluck('url')->all())
        ->toBe(['tel:+61400000000']);
});

it('keeps a national number and its international form apart, because it cannot know they are one number', function () {
    $tenant = createTenant('tel-e164');
    $visitorId = (string) Str::uuid();
    $origin = 'https://tel-e164.'.config('partna.public_domain');

    $base = ['site_id' => $tenant->site->id, 'visitor_id' => $visitorId];

    $this->withHeader('Origin', $origin)
        ->postJson('/api/public/analytics/clicks', $base + ['url' => 'tel:+61 400 000 000'])
        ->assertStatus(201);
    $this->withHeader('Origin', $origin)
        ->postJson('/api/public/analytics/clicks', $base + ['url' => 'tel:0400-000-000'])
        ->assertStatus(201);

    // This test carried the name of the one above it and asserted the opposite:
    // it posted two DIFFERENT digit strings and expected two rows. Renamed to
    // what it actually pins, and the missing collapse case written properly above.
    //
    // Merging these two WOULD be the more useful behaviour — +61400000000 and
    // 0400000000 are one Australian mobile — but only for a caller who knows the
    // dialling region, and nothing on this wire does. `tel:` carries no country,
    // the beacon sends no locale, and site.sites holds no dialling region; a
    // leading 0 is a trunk prefix in Australia and a significant digit in Italy.
    // Guessing +61 would silently rewrite a foreign owner's number into a
    // different real number and file clicks under a destination nobody dialled —
    // corrupting the url column to spare a duplicate row. When a dialling region
    // lands on the site record, E.164 normalisation belongs in
    // AnalyticsEventSanitizer::telDestination() and this test gets rewritten.
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->pluck('url')->all())
        ->toBe(['tel:+61400000000', 'tel:0400000000']);
});

it('rejects an unusable destination rather than nulling it into an accepted click', function () {
    $tenant = createTenant('null-smuggle');
    $block = createLinkBlockFor($tenant);
    $origin = 'https://null-smuggle.'.config('partna.public_domain');

    // prepareForValidation() normalises BEFORE the rules run, so what the rule
    // sees is whatever the sanitiser returned — and for a destination it refuses
    // that is null. Without the `?? $url` fallback that null is merged into the
    // request, `nullable` short-circuits the scheme rule, and a javascript: URL
    // posted alongside a block_id answers 201 and writes a row with url NULL:
    // a rejected destination silently promoted to an accepted click. Keeping the
    // raw value is what lets the rule fail it, and fail it naming what arrived.
    $this->withHeader('Origin', $origin)
        ->postJson('/api/public/analytics/clicks', [
            'site_id' => $tenant->site->id,
            'block_id' => $block->id,
            'url' => 'javascript:alert(1)',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('url');

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('still rejects a scheme that is not a real destination', function (string $bad) {
    $tenant = createTenant('scheme-guard');
    $origin = 'https://scheme-guard.'.config('partna.public_domain');

    // clickUrl() is an ALLOWLIST — a `match` over http/https/tel/mailto with
    // `default => null` — not a list of bad schemes to block. The entries below
    // that nobody would think to blocklist (file:, vbscript:, chrome:, intent:)
    // are here to pin that: they are refused because they were never named, so a
    // scheme invented after this line was written is refused too. A denylist here
    // would be wrong by construction, since the url column is read back and
    // rendered, and the set of schemes a renderer will act on is open-ended.
    // One case per test, not a loop: throttle:analytics-click is a real
    // middleware on this route and ten posts from one IP inside a test 429s,
    // which would pass a `422 or bust` loop for the wrong reason.
    $this->withHeader('Origin', $origin)
        ->postJson('/api/public/analytics/clicks', [
            'site_id' => $tenant->site->id,
            'url' => $bad,
        ])->assertStatus(422);

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
})->with([
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html,x',
    'schemeless' => 'not-a-url',
    'empty mailto' => 'mailto:',
    'empty tel' => 'tel:',
    'file' => 'file:///etc/passwd',
    'vbscript' => 'vbscript:msgbox(1)',
    'browser-internal' => 'chrome://settings',
    'android intent' => 'intent://scan/#Intent;scheme=zxing;end',
    'blob' => 'blob:https://x.example/abc',
]);
