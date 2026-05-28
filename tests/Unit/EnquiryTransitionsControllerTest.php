<?php

use App\Http\Controllers\Api\User\Customers\UserEnquiryController;
use App\Models\Core\Site\Enquiry;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('transitions and is idempotent', function (string $action, string $controllerMethod, string $expectedStatus) {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid());

    $req = requestAs($user, 'POST', "/api/enquiries/{$enquiryId}/{$action}");
    $first = app(UserEnquiryController::class)->{$controllerMethod}($req, $enquiryId);
    expect($first->getStatusCode())->toBe(200);
    expect(Enquiry::find($enquiryId)->status->value)->toBe($expectedStatus);

    $second = app(UserEnquiryController::class)->{$controllerMethod}(
        requestAs($user, 'POST', "/api/enquiries/{$enquiryId}/{$action}"),
        $enquiryId
    );
    expect($second->getStatusCode())->toBe(200);
    expect(Enquiry::find($enquiryId)->status->value)->toBe($expectedStatus);
})->with([
    ['read',     'markRead',    'read'],
    ['replied',  'markReplied', 'replied'],
    ['archive',  'archive',     'archived'],
    ['restore',  'restore',     'new'],
]);

it('cross-tenant transitions return 404', function () {
    $me = makeInboxUser();
    $other = makeInboxUser();
    $enquiryId = seedInboxEnquiry($other->id, (string) Str::uuid());

    foreach (['markRead', 'markReplied', 'archive', 'restore'] as $method) {
        $response = app(UserEnquiryController::class)->{$method}(
            requestAs($me, 'POST', "/api/enquiries/{$enquiryId}/x"),
            $enquiryId
        );
        expect($response->getStatusCode())->toBe(404);
    }
});
