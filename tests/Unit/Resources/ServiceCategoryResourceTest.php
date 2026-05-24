<?php

uses(Tests\TestCase::class)->in(__FILE__);

use App\Http\Resources\ServiceCategoryResource;
use App\Models\Core\Professional\ServiceCategory;
use Illuminate\Support\Carbon;

it('ships only the allowlisted fields and drops extras', function () {
    $category = new ServiceCategory([
        'professional_id' => '11111111-1111-1111-1111-111111111111',
        'title' => 'Haircuts',
        'sort_order' => 0,
    ]);
    $category->id = '22222222-2222-2222-2222-222222222222';
    $category->created_at = Carbon::parse('2026-01-01T00:00:00Z');
    $category->updated_at = Carbon::parse('2026-01-02T00:00:00Z');
    $category->deleted_at = null;
    $category->setAttribute('admin_notes', 'internal');
    $category->setAttribute('deleted_origin', 'admin_purge');

    $array = (new ServiceCategoryResource($category))->resolve();

    expect(array_keys($array))->toEqual([
        'id', 'professional_id', 'title', 'sort_order',
        'created_at', 'updated_at', 'deleted_at',
    ]);
    expect($array)->not->toHaveKey('admin_notes');
    expect($array)->not->toHaveKey('deleted_origin');
    expect($array['id'])->toBeString()->toBe('22222222-2222-2222-2222-222222222222');
    expect($array['created_at'])->toBe('2026-01-01T00:00:00+00:00');
});
