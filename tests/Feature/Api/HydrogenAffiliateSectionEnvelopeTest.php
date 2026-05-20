<?php

use App\Models\Core\Site\Block;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Str;

// Exercises the sectionEnvelope() helper on the shared resolver. The method
// used to live on HydrogenAffiliateController; extracted to the service so
// both the affiliate endpoint and the public §28.8 individual endpoint
// produce identical envelope shapes.
function invokeEnvelope($sections, string $type, callable $data): array
{
    return (new SitepageDataResolverService)->sectionEnvelope($sections, $type, $data);
}

it('omits block_id from the shop envelope when no block row exists', function () {
    $sections = collect(); // no blocks at all

    $result = invokeEnvelope($sections, 'shop', fn () => null);

    expect($result)
        ->toHaveKey('state', 'draft')
        ->toHaveKey('data', null)
        ->not->toHaveKey('block_id'); // absent, not null — Hydrogen guards on presence
});

it('includes block_id in the shop envelope when the block exists and is live', function () {
    $blockId = (string) Str::uuid();
    $block = new Block;
    $block->id = $blockId;
    $block->block_type = 'shop';
    $block->is_enabled = true;
    $block->is_active = true;
    $sections = collect(['shop' => $block]);

    $result = invokeEnvelope($sections, 'shop', fn () => null);

    expect($result)
        ->toHaveKey('state', 'live')
        ->toHaveKey('block_id', $blockId)
        ->toHaveKey('data', null);
});

it('includes block_id in the shop envelope when the block exists but is draft', function () {
    $blockId = (string) Str::uuid();
    $block = new Block;
    $block->id = $blockId;
    $block->block_type = 'shop';
    $block->is_enabled = true;
    $block->is_active = false;
    $sections = collect(['shop' => $block]);

    $result = invokeEnvelope($sections, 'shop', fn () => null);

    // Block exists (ID present), but section is toggled off — state='draft'.
    // block_id is still returned so Hydrogen knows the block is configured
    // but unpublished, rather than treating it as unconfigured.
    expect($result)
        ->toHaveKey('state', 'draft')
        ->toHaveKey('block_id', $blockId)
        ->toHaveKey('data', null);
});

it('treats is_active=true but is_enabled=false as draft (requirements gate)', function () {
    // The pro turned the section Live, but underlying data went away
    // (e.g. last gallery image deleted → SiteMediaObserver flipped
    // is_enabled to false). The public render path must hide it.
    $blockId = (string) Str::uuid();
    $block = new Block;
    $block->id = $blockId;
    $block->block_type = 'gallery';
    $block->is_enabled = false;
    $block->is_active = true;
    $sections = collect(['gallery' => $block]);

    $result = invokeEnvelope($sections, 'gallery', fn () => ['anything']);

    expect($result)
        ->toHaveKey('state', 'draft')
        ->toHaveKey('block_id', $blockId)
        ->toHaveKey('data', null);
});
