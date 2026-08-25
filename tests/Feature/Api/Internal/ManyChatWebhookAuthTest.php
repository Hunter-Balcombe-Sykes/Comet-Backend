<?php

// No table bootstrap needed here, uniquely: the middleware short-circuits
// before any DB access. Every OTHER new test file in this plan needs the full
// setup*/Queue::fake() block — see Global Constraints.

it('returns 503 when the webhook secret is not configured', function () {
    config(['services.manychat.webhook_secret' => '']);

    $this->postJson('/api/internal/webhooks/manychat/builds', [])
        ->assertStatus(503)
        ->assertJsonPath('error', 'hook_not_configured');
});

it('returns 401 when the secret header is absent', function () {
    config(['services.manychat.webhook_secret' => 'a-test-secret-value']);

    $this->postJson('/api/internal/webhooks/manychat/builds', [])->assertStatus(401);
});

it('returns 401 when the secret header is wrong', function () {
    config(['services.manychat.webhook_secret' => 'a-test-secret-value']);

    $this->withHeader('X-Partna-Webhook-Secret', 'not-the-secret')
        ->postJson('/api/internal/webhooks/manychat/builds', [])
        ->assertStatus(401);
});

it('passes the gate when the correct secret is presented', function () {
    config(['services.manychat.webhook_secret' => 'a-test-secret-value']);

    $response = $this->withHeader('X-Partna-Webhook-Secret', 'a-test-secret-value')
        ->postJson('/api/internal/webhooks/manychat/builds', []);

    // 422 (validation) is the expected answer for an empty body past the gate
    // — asserted directly now the route exists, so a 404 can no longer pass.
    $response->assertStatus(422);
});
