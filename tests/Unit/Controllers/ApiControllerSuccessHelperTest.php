<?php

use App\Http\Controllers\Api\ApiController;
use Tests\TestCase;

// Bootstrap the Laravel app so response()->json() is available.
uses(TestCase::class)->in(__FILE__);

/**
 * Expose the protected success() helper on a concrete subclass for testing.
 */
function makeController(): object
{
    return new class extends ApiController
    {
        public function callSuccess($data = null, int $status = 200)
        {
            return $this->success($data, $status);
        }
    };
}

it('returns 200 with data by default', function () {
    $response = makeController()->callSuccess(['key' => 'value']);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['key' => 'value']);
});

it('returns the given status code', function () {
    $response = makeController()->callSuccess(['id' => 1], 201);

    expect($response->getStatusCode())->toBe(201);
});

it('returns 200 when called with no arguments', function () {
    $response = makeController()->callSuccess();

    expect($response->getStatusCode())->toBe(200);
});

// Documents the common footgun: success($data, 'string', 200) passes a string where
// an int is required. PHP 8+ throws TypeError even without strict_types. The third
// argument is silently dropped — the message, not 200, becomes $status.
it('throws TypeError when a non-numeric string is passed as status', function () {
    expect(fn () => makeController()->callSuccess(['data'], 'Created')) // @phpstan-ignore-line
        ->toThrow(TypeError::class);
});
