<?php

uses(Tests\TestCase::class)->in(__FILE__);

use App\Http\Resources\LinkBlockResource;
use App\Models\Core\Site\Block;
use Illuminate\Support\Carbon;

it('ships only the allowlisted fields and drops extras', function () {
    $block = new Block([
        'user_id' => '11111111-1111-1111-1111-111111111111',
        'site_id' => '22222222-2222-2222-2222-222222222222',
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'Instagram',
        'url' => 'https://instagram.com/example',
        'icon_key' => 'instagram',
        'sort_order' => 0,
        'is_active' => true,
        'is_enabled' => true,
        'settings' => ['platform' => 'instagram', 'handle' => 'example', 'category' => 'social'],
    ]);
    $block->id = '33333333-3333-3333-3333-333333333333';
    $block->created_at = Carbon::parse('2026-01-01T00:00:00Z');
    $block->updated_at = Carbon::parse('2026-01-02T00:00:00Z');
    $block->deleted_at = null;
    $block->setAttribute('internal_flag', 'should_not_ship');

    $array = (new LinkBlockResource($block))->resolve();

    expect(array_keys($array))->toEqual([
        'id', 'user_id', 'site_id', 'block_type', 'block_group',
        'title', 'url', 'icon_key', 'sort_order', 'is_active', 'is_enabled',
        'settings', 'created_at', 'updated_at',
    ]);
    expect($array)->not->toHaveKey('internal_flag');
    expect($array)->not->toHaveKey('deleted_at');
    expect($array['id'])->toBeString();
});

it('emits settings as an object so {} round-trips correctly', function () {
    $block = new Block([
        'user_id' => '11111111-1111-1111-1111-111111111111',
        'site_id' => '22222222-2222-2222-2222-222222222222',
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'Empty settings',
        'url' => 'https://example.com',
        'sort_order' => 0,
        'is_active' => true,
        'is_enabled' => true,
        'settings' => [],
    ]);
    $block->id = '44444444-4444-4444-4444-444444444444';

    $array = (new LinkBlockResource($block))->resolve();

    // (object)[] serializes to {} in JSON — without the cast it would be []
    // which silently shifts the API contract for empty-settings rows.
    expect($array['settings'])->toBeInstanceOf(stdClass::class);
});
