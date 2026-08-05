<?php

use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('default list excludes archived and spam', function () {
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    foreach (['new', 'read', 'replied', 'archived', 'spam'] as $s) {
        seedInboxEnquiry($user->id, $siteId, ['status' => $s]);
    }

    $response = actingAsUser($user)->getJson('/api/enquiries')->assertOk();
    expect(count($response->json('data')))->toBe(3);  // new, read, replied
});

it('?status=archived returns only archived enquiries', function () {
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    seedInboxEnquiry($user->id, $siteId, ['status' => 'archived']);
    seedInboxEnquiry($user->id, $siteId, ['status' => 'archived']);
    seedInboxEnquiry($user->id, $siteId, ['status' => 'new']);

    $response = actingAsUser($user)->getJson('/api/enquiries?status=archived')->assertOk();
    expect(count($response->json('data')))->toBe(2);
});
