<?php

/*
|--------------------------------------------------------------------------
| Public form timing field is mandatory
|--------------------------------------------------------------------------
| INH-7-DRIFT: PublicEarlyAccessSignupRequest hand-rolled `form_started_at_ms`
| as `nullable`, and every controller gates its timing check on the field being
| present — so omitting it silently disabled anti-automation on that endpoint.
| All four public submission forms now take the rule from WithBotProtection;
| this pins the `required` half so the drift can't come back on any of them.
|
| One dataset case per endpoint: each case boots the app fresh, so the array
| rate limiter starts empty and the `leads` bucket can't bleed across cases.
*/

it('rejects a submission with form_started_at_ms absent', function (string $uri, array $payload) {
    $this->postJson($uri, $payload, ['X-Site-Subdomain' => 'testpro'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['form_started_at_ms']);
})->with([
    'early access' => ['/api/public/early-access', [
        'email' => 'timing@example.test',
        'type' => 'partna',
        'platforms' => ['instagram', 'fresha'],
        'source_type' => 'instagram',
        'source_ref' => 'timing_handle',
    ]],
    'enquiry' => ['/api/public/enquiry', [
        'name' => 'Sarah Jones',
        'email' => 'timing@example.test',
        'subject' => 'Wholesale',
        'message' => 'Hi, I would love to stock your products in my shop.',
    ]],
    'customer lead' => ['/api/public/customers', [
        'full_name' => 'Sarah Jones',
        'email' => 'timing@example.test',
        'phone' => '+44 7700 900000',
    ]],
    'email subscribe' => ['/api/public/subscribe', [
        'email' => 'timing@example.test',
        'list_key' => 'marketing',
    ]],
]);
