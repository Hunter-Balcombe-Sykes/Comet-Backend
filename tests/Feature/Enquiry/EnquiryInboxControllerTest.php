<?php

use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('returns counts across all five statuses', function () {
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    foreach (['new', 'new', 'new', 'read', 'read', 'replied', 'archived', 'archived', 'archived', 'archived', 'archived', 'spam', 'spam', 'spam', 'spam'] as $status) {
        seedInboxEnquiry($user->id, $siteId, ['status' => $status]);
    }

    actingAsUser($user)
        ->getJson('/api/enquiries/counts')
        ->assertOk()
        ->assertJson([
            'new' => 3, 'read' => 2, 'replied' => 1, 'archived' => 5, 'spam' => 4,
        ]);
});

it('excludes other pros enquiries from counts', function () {
    $me = makeInboxUser();
    $other = makeInboxUser();
    seedInboxEnquiry($me->id, (string) Str::uuid(), ['status' => 'new']);
    seedInboxEnquiry($other->id, (string) Str::uuid(), ['status' => 'new']);

    actingAsUser($me)
        ->getJson('/api/enquiries/counts')
        ->assertOk()
        ->assertJson(['new' => 1]);
});
