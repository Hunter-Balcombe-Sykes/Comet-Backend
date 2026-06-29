<?php

use App\Services\PublicSite\IndividualProfilePayloadBuilder;

// Locks the §28.8 contract: the public profile payload always exposes
// profile.smartLinks as an empty array. SmartLinks is being removed; this
// test guarantees the payload stays stable — and never touches the (soon
// deleted) SmartLink model — after the feature code is stripped.
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    setupBlocksTable();
    setupServicesTable();
    setupDesignKitsTable();
});

it('emits an empty profile.smartLinks array on the public profile payload', function () {
    $pro = createTenant('sllock');
    $site = $pro->site;

    $payload = app(IndividualProfilePayloadBuilder::class)->build($pro->fresh('site'), $site);

    expect($payload['profile'])->toHaveKey('smartLinks');
    expect($payload['profile']['smartLinks'])->toBe([]);
});
