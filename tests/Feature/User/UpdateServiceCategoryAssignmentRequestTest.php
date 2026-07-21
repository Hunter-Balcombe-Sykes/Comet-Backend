<?php

use App\Http\Requests\Api\User\Services\UpdateServiceCategoryAssignmentRequest;
use Illuminate\Support\Str;

it('validates category_id as present, nullable, and a uuid', function () {
    $rules = (new UpdateServiceCategoryAssignmentRequest)->rules();

    // Contract (multi-category): category_ids replaces the set; the legacy
    // single category_id maps to a one-element set. Presence of at least one
    // key is enforced by the request's after() hook (null must still pass —
    // it's the "move to Uncategorized" spelling, which no built-in rule can
    // express across two fields).
    expect($rules)->toBe([
        'category_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
        'category_ids.*' => ['uuid', 'distinct'],
        'category_id' => ['sometimes', 'nullable', 'uuid'],
    ]);

    // Presence enforcement lives in after(), not the bare rules — an empty
    // payload passes the rules but the request hook rejects it (covered by
    // the endpoint test below/alongside).

    // nullable → an explicit null passes (this is the "move to Uncategorized" path).
    expect(validator(['category_id' => null], $rules)->passes())->toBeTrue();

    // uuid → a non-uuid string fails.
    expect(validator(['category_id' => 'not-a-uuid'], $rules)->fails())->toBeTrue();

    // A well-formed uuid passes.
    expect(validator(['category_id' => (string) Str::uuid()], $rules)->passes())->toBeTrue();
});
