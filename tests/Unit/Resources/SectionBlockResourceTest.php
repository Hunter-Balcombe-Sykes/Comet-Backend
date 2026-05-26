<?php

uses(Tests\TestCase::class)->in(__FILE__);

use App\Http\Resources\SectionBlockResource;
use App\Models\Core\Site\Block;
use Illuminate\Support\Carbon;

function makeSectionBlock(array $overrides = []): Block
{
    $block = new Block([
        'user_id' => '11111111-1111-1111-1111-111111111111',
        'site_id' => '22222222-2222-2222-2222-222222222222',
        'block_type' => 'gallery',
        'block_group' => 'sections',
        'title' => null,
        'url' => null,
        'icon_key' => null,
        'sort_order' => 1,
        'is_active' => true,
        'is_enabled' => true,
        'settings' => ['heading' => 'My work'],
    ]);
    $block->id = '33333333-3333-3333-3333-333333333333';
    $block->created_at = Carbon::parse('2026-01-01T00:00:00Z');
    $block->updated_at = Carbon::parse('2026-01-02T00:00:00Z');
    $block->deleted_at = null;

    foreach ($overrides as $k => $v) {
        $block->setAttribute($k, $v);
    }

    return $block;
}

it('ships the section base shape + computed publication_state/is_live', function () {
    $array = (new SectionBlockResource(makeSectionBlock()))->resolve();

    expect(array_keys($array))->toEqual([
        'id', 'user_id', 'site_id', 'block_type', 'block_group',
        'title', 'url', 'icon_key', 'sort_order', 'is_active', 'is_enabled',
        'settings', 'created_at', 'updated_at', 'publication_state', 'is_live',
    ]);
    expect($array['publication_state'])->toBe('live');
    expect($array['is_live'])->toBeTrue();
});

it('reports draft state when is_active is false', function () {
    $block = makeSectionBlock();
    $block->is_active = false;

    $array = (new SectionBlockResource($block))->resolve();

    expect($array['publication_state'])->toBe('draft');
    expect($array['is_live'])->toBeFalse();
});

it('adds can_publish + requirement_reason when visibility supplied', function () {
    $array = (new SectionBlockResource(makeSectionBlock(), [false, 'No images uploaded.']))->resolve();

    expect($array)->toHaveKey('can_publish');
    expect($array)->toHaveKey('requirement_reason');
    expect($array['can_publish'])->toBeFalse();
    expect($array['requirement_reason'])->toBe('No images uploaded.');
});

it('omits can_publish/requirement_reason when no visibility supplied', function () {
    $array = (new SectionBlockResource(makeSectionBlock()))->resolve();

    expect($array)->not->toHaveKey('can_publish');
    expect($array)->not->toHaveKey('requirement_reason');
});

it('does not leak extra columns', function () {
    $array = (new SectionBlockResource(makeSectionBlock([
        'admin_notes' => 'staff-only',
        'deleted_origin' => 'auto',
    ])))->resolve();

    expect($array)->not->toHaveKey('admin_notes');
    expect($array)->not->toHaveKey('deleted_origin');
});
