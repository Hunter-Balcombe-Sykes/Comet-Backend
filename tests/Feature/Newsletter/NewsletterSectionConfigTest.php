<?php

it('registers newsletter as a section_block_type', function () {
    expect(config('partna.section_block_types'))->toContain('newsletter');
});

