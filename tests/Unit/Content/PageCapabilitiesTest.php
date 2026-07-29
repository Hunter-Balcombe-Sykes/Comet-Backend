<?php

/**
 * #TEST-1 residual — PageCapabilities::allows()'s fail-closed branch
 * (app/Services/Content/PageCapabilities.php:79-81) had zero coverage. An
 * unknown capability can never reach the controller today (StorePageRequest /
 * UpdatePageRequest both apply Rule::in(PageCapabilities::keys())), so this is
 * the registry-shrank-under-a-stored-row case — a pure function with no HTTP
 * surface, hence a Unit test rather than a route-level one.
 */

use App\Models\Core\User\User;
use App\Services\Content\PageCapabilities;

function pageCapabilities_user(): User
{
    return (new User)->forceFill(['id' => 'a-user-id', 'account_type' => 'partna', 'status' => 'active']);
}

it('fails closed for a capability that is no longer in the registry', function () {
    expect(PageCapabilities::allows(pageCapabilities_user(), 'a-withdrawn-capability'))->toBeFalse();
});

it('treats a null or empty capability as ungated', function () {
    expect(PageCapabilities::allows(pageCapabilities_user(), null))->toBeTrue();
    expect(PageCapabilities::allows(pageCapabilities_user(), ''))->toBeTrue();
});
