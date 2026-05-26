<?php

/** @phpstan-ignore-all */
// The backend has no public landing page — `/` is intentionally a 404.
// Public traffic goes through subdomain routing (handled by the Cloudflare
// Worker); the API origin only serves /api/* and /p/{uuid}.svg.
test('the root path is intentionally not exposed', function () {
    $response = $this->get('/');

    $response->assertStatus(404);
});
