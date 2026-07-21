<?php

// public-surface/SEC-1 — GET /config/integrations (Google Maps key) moved
// behind `user.api` auth from the former unauthenticated
// /public/config/integrations. The only named consumer is the logged-in
// dashboard's address autocomplete, so there's no product reason to serve
// this pre-auth.

it('requires auth — an unauthenticated request is rejected', function () {
    $response = $this->getJson('/api/config/integrations');

    $response->assertStatus(401);
});

it('returns the Google Maps key for an authenticated dashboard request', function () {
    $tenant = createTenant('config-integrations-auth');
    config(['services.google_maps.api_key' => 'test-maps-key-123']);

    $response = actingAsUser($tenant)->getJson('/api/config/integrations');

    $response->assertStatus(200);
    $response->assertJson(['googleMapsApiKey' => 'test-maps-key-123']);
});

it('is no longer reachable at the old unauthenticated public path', function () {
    $response = $this->getJson('/api/public/config/integrations');

    $response->assertStatus(404);
});

it('does not carry a public CDN cache header on the authenticated response', function () {
    $tenant = createTenant('config-integrations-nocache');

    $response = actingAsUser($tenant)->getJson('/api/config/integrations');

    // successCached() (public, CDN-cacheable) must not be used here — this
    // endpoint is now per-auth, not a static unauthenticated config blob.
    expect($response->headers->get('Cache-Control'))->not->toContain('public');
});
