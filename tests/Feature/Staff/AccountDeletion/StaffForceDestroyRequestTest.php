<?php

use App\Http\Requests\Api\Staff\StaffForceDestroyRequest;
use Illuminate\Support\Facades\Validator;

function forceDestroyValidator(array $data)
{
    $request = new StaffForceDestroyRequest;

    return Validator::make($data, $request->rules(), $request->messages());
}

it('rejects a missing reason', function () {
    expect(forceDestroyValidator([])->fails())->toBeTrue();
});

it('rejects a reason shorter than 10 chars', function () {
    expect(forceDestroyValidator(['reason' => 'too short'])->fails())->toBeTrue();
});

it('rejects a reason longer than 500 chars', function () {
    expect(forceDestroyValidator(['reason' => str_repeat('a', 501)])->fails())->toBeTrue();
});

it('accepts a valid reason with optional override_obligations', function () {
    $v = forceDestroyValidator(['reason' => 'Spam account — ticket #123', 'override_obligations' => true]);
    expect($v->fails())->toBeFalse();
});
