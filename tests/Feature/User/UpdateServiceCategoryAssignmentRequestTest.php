<?php

use App\Http\Requests\Api\User\Services\UpdateServiceCategoryAssignmentRequest;
use Illuminate\Support\Str;

it('validates category_id as present, nullable, and a uuid', function () {
    $rules = (new UpdateServiceCategoryAssignmentRequest)->rules();

    // Contract: exactly one rule set, on category_id.
    expect($rules)->toBe(['category_id' => ['present', 'nullable', 'uuid']]);

    // present → omitting the key fails.
    expect(validator([], $rules)->fails())->toBeTrue();

    // nullable → an explicit null passes (this is the "move to Uncategorized" path).
    expect(validator(['category_id' => null], $rules)->passes())->toBeTrue();

    // uuid → a non-uuid string fails.
    expect(validator(['category_id' => 'not-a-uuid'], $rules)->fails())->toBeTrue();

    // A well-formed uuid passes.
    expect(validator(['category_id' => (string) Str::uuid()], $rules)->passes())->toBeTrue();
});
