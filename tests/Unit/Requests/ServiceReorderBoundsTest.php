<?php

use App\Http\Requests\Api\Staff\UserSite\Services\StaffReorderServiceCategoryRequest;
use App\Http\Requests\Api\Staff\UserSite\Services\StaffReorderServiceLayoutRequest;
use App\Http\Requests\Api\User\Services\ReorderServiceCategoryRequest;
use App\Http\Requests\Api\User\Services\ReorderServiceLayoutRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

// #SEC-13: pure rule-level checks (no HTTP, no DB) — the bound lives entirely
// in rules(), so Validator::make() against a payload is the cheap way to pin
// it, matching UpsertWorkplaceRequestTest's pattern for the same class of check.
uses(TestCase::class)->in(__FILE__);

/**
 * @param  class-string<FormRequest>  $requestClass
 * @return array<string, mixed>
 */
function validateReorderPayload(string $requestClass, array $payload): array
{
    $request = new $requestClass;
    $request->merge($payload);

    return Validator::make($request->all(), $request->rules())->errors()->toArray();
}

/** @return list<string> */
function reorderUuids(int $count): array
{
    return array_map(fn () => (string) Str::uuid(), range(1, $count));
}

it('#SEC-13: ReorderServiceLayoutRequest rejects more than 200 categories', function () {
    $categories = array_map(
        fn (string $id) => ['id' => $id, 'service_ids' => []],
        reorderUuids(201),
    );

    $errors = validateReorderPayload(ReorderServiceLayoutRequest::class, ['categories' => $categories]);

    expect($errors)->toHaveKey('categories');
});

it('#SEC-13: ReorderServiceLayoutRequest accepts exactly 200 categories', function () {
    $categories = array_map(
        fn (string $id) => ['id' => $id, 'service_ids' => []],
        reorderUuids(200),
    );

    $errors = validateReorderPayload(ReorderServiceLayoutRequest::class, ['categories' => $categories]);

    expect($errors)->toBe([]);
});

it('#SEC-13: ReorderServiceLayoutRequest rejects more than 200 service_ids in one category block', function () {
    $errors = validateReorderPayload(ReorderServiceLayoutRequest::class, [
        'categories' => [['id' => null, 'service_ids' => reorderUuids(201)]],
    ]);

    expect($errors)->toHaveKey('categories.0.service_ids');
});

it('#SEC-13: ReorderServiceLayoutRequest accepts exactly 200 service_ids in one category block', function () {
    $errors = validateReorderPayload(ReorderServiceLayoutRequest::class, [
        'categories' => [['id' => null, 'service_ids' => reorderUuids(200)]],
    ]);

    expect($errors)->toBe([]);
});

it('#SEC-13: StaffReorderServiceLayoutRequest — the user twin — carries the same 200-category bound', function () {
    $categories = array_map(
        fn (string $id) => ['id' => $id, 'service_ids' => []],
        reorderUuids(201),
    );

    $errors = validateReorderPayload(StaffReorderServiceLayoutRequest::class, ['categories' => $categories]);

    expect($errors)->toHaveKey('categories');
});

it('#SEC-13: StaffReorderServiceLayoutRequest accepts exactly 200 categories', function () {
    $categories = array_map(
        fn (string $id) => ['id' => $id, 'service_ids' => []],
        reorderUuids(200),
    );

    $errors = validateReorderPayload(StaffReorderServiceLayoutRequest::class, ['categories' => $categories]);

    expect($errors)->toBe([]);
});

it('#SEC-13: ReorderServiceCategoryRequest rejects more than 200 ids', function () {
    $errors = validateReorderPayload(ReorderServiceCategoryRequest::class, ['ids' => reorderUuids(201)]);

    expect($errors)->toHaveKey('ids');
});

it('#SEC-13: ReorderServiceCategoryRequest accepts exactly 200 ids', function () {
    $errors = validateReorderPayload(ReorderServiceCategoryRequest::class, ['ids' => reorderUuids(200)]);

    expect($errors)->toBe([]);
});

it('#SEC-13: StaffReorderServiceCategoryRequest — the user twin — carries the same 200-id bound', function () {
    $errors = validateReorderPayload(StaffReorderServiceCategoryRequest::class, ['ids' => reorderUuids(201)]);

    expect($errors)->toHaveKey('ids');
});

it('#SEC-13: StaffReorderServiceCategoryRequest accepts exactly 200 ids', function () {
    $errors = validateReorderPayload(StaffReorderServiceCategoryRequest::class, ['ids' => reorderUuids(200)]);

    expect($errors)->toBe([]);
});
