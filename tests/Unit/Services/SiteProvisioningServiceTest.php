<?php

use App\Models\Core\User\User;
use App\Services\User\SiteProvisioningService;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('creates an unpublished site when asked (pre-account signup builds)', function () {
    setupUsersTable();
    setupSitesTable();
    $user = User::factory()->create();

    $site = app(SiteProvisioningService::class)->createSiteWithRetry($user->id, 'janedoe', published: false);

    expect($site->is_published)->toBeFalse()->and($site->subdomain)->toBe('janedoe');
});
