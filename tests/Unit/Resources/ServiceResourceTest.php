<?php

// Unit tests opt into Tests\TestCase via this hook (Pest config only auto-binds
// Tests\TestCase under tests/Feature). Required for ->resolve() to bootstrap
// the container that wraps JsonResource.
uses(Tests\TestCase::class)->in(__FILE__);

use App\Http\Resources\ServiceResource;
use App\Models\Core\User\Service;
use Illuminate\Support\Carbon;

function makeService(array $overrides = []): Service
{
    $service = new Service([
        'user_id' => '11111111-1111-1111-1111-111111111111',
        'category_id' => '22222222-2222-2222-2222-222222222222',
        'title' => 'Mens cut',
        'description' => 'Quick haircut',
        'price_cents' => 4500,
        'currency_code' => 'AUD',
        'duration_minutes' => 30,
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $service->id = '33333333-3333-3333-3333-333333333333';
    $service->created_at = Carbon::parse('2026-01-01T12:00:00Z');
    $service->updated_at = Carbon::parse('2026-01-02T12:00:00Z');
    $service->deleted_at = null;

    foreach ($overrides as $k => $v) {
        $service->setAttribute($k, $v);
    }

    return $service;
}

it('ships only the allowlisted fields', function () {
    $array = (new ServiceResource(makeService()))->resolve();

    expect(array_keys($array))->toEqual([
        'id', 'user_id', 'category_id', 'title', 'description',
        'price_cents', 'currency_code', 'duration_minutes', 'is_active',
        'sort_order', 'created_at', 'updated_at', 'deleted_at',
    ]);
});

it('does not leak future/internal columns added to the model', function () {
    // The audit specifically warns about `internal_cost_cents` (future) and
    // the existing internal `deleted_origin`. Both must stay hidden.
    $service = makeService([
        'internal_cost_cents' => 1500,
        'deleted_origin' => 'admin_purge',
        'category' => 'legacy_text_column',
    ]);

    $array = (new ServiceResource($service))->resolve();

    expect($array)->not->toHaveKey('internal_cost_cents');
    expect($array)->not->toHaveKey('deleted_origin');
    expect($array)->not->toHaveKey('category');
});

it('casts id to string and timestamps to ISO-8601', function () {
    $array = (new ServiceResource(makeService()))->resolve();

    expect($array['id'])->toBeString()
        ->and($array['id'])->toBe('33333333-3333-3333-3333-333333333333');
    expect($array['created_at'])->toBe('2026-01-01T12:00:00+00:00');
    expect($array['updated_at'])->toBe('2026-01-02T12:00:00+00:00');
    expect($array['deleted_at'])->toBeNull();
});

it('surfaces deleted_at when set', function () {
    $service = makeService();
    $service->deleted_at = Carbon::parse('2026-02-01T00:00:00Z');

    $array = (new ServiceResource($service))->resolve();

    expect($array['deleted_at'])->toBe('2026-02-01T00:00:00+00:00');
});
