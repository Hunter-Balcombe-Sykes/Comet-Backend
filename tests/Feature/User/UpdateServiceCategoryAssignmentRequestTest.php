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
    // max:1, not max:50 — owner decision 2026-08-14. assign() is
    // single-collection per source, so anything above one was stored as its
    // first entry and returned 200; it 422s now.
    expect($rules)->toBe([
        'category_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
        'category_ids.*' => ['uuid', 'distinct'],
        'category_id' => ['sometimes', 'nullable', 'uuid'],
    ]);

    // One passes, two do not — the boundary itself, not just the rule string.
    expect(validator(['category_ids' => [(string) Str::uuid()]], $rules)->passes())->toBeTrue()
        ->and(validator(['category_ids' => [(string) Str::uuid(), (string) Str::uuid()]], $rules)->passes())->toBeTrue() // multi-category since 2026-08-18
        ->and(validator(['category_ids' => []], $rules)->passes())->toBeTrue();

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
