<?php

use App\Models\Core\Site\Block;

it('exposes block_types keyed by group, matching the CHECK enum', function () {
    $sections = [
        'gallery', 'services', 'booking', 'contacts_collection',
        'barbershop_info', 'documents', 'newsletter', 'contact',
        'public_contact', 'workplace', 'credentials', 'experience', 'bio',
    ];

    expect(config('partna.block_types.links'))->toBe(['link']);
    expect(config('partna.block_types.sections'))->toBe($sections);
});

it('derives section_block_types from block_types so the two never drift', function () {
    expect(config('partna.section_block_types'))
        ->toBe(config('partna.block_types.sections'));
});

it('Block group/type constants match the config keys', function () {
    expect(Block::GROUP_LINKS)->toBe('links');
    expect(Block::GROUP_SECTIONS)->toBe('sections');
    expect(Block::TYPE_LINK)->toBe('link');
    expect(array_keys(config('partna.block_types')))
        ->toBe([Block::GROUP_LINKS, Block::GROUP_SECTIONS]);
});
