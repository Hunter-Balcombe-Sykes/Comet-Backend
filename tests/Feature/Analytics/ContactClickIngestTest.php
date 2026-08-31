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

it('collapses two visually different spellings of the same number onto one dedup key', function () {
    $tenant = createTenant('tel-dedup');
    $visitorId = (string) Str::uuid();
    $origin = 'https://tel-dedup.'.config('partna.public_domain');

    $base = ['site_id' => $tenant->site->id, 'visitor_id' => $visitorId];

    $this->withHeader('Origin', $origin)
        ->postJson('/api/public/analytics/clicks', $base + ['url' => 'tel:+61 400 000 000'])
        ->assertStatus(201);
    $this->withHeader('Origin', $origin)
        ->postJson('/api/public/analytics/clicks', $base + ['url' => 'tel:0400-000-000'])
        ->assertStatus(201);

    // Normalisation has to happen BEFORE the dedup target is hashed, or the same
    // tap spelled two ways mints two keys and the 3s dedup window never fires.
    expect(DB::connection('pgsql')->table('analytics.link_clicks')->pluck('url')->all())
        ->toBe(['tel:+61400000000', 'tel:0400000000']);
});

it('still rejects a scheme that is not a real destination', function () {
    $tenant = createTenant('scheme-guard');
    $origin = 'https://scheme-guard.'.config('partna.public_domain');

    foreach (['javascript:alert(1)', 'data:text/html,x', 'not-a-url', 'mailto:', 'tel:'] as $bad) {
        $this->withHeader('Origin', $origin)
            ->postJson('/api/public/analytics/clicks', [
                'site_id' => $tenant->site->id,
                'url' => $bad,
            ])->assertStatus(422);
    }

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});
