<?php

it('registers contact as a section_block_type', function () {
    expect(config('partna.section_block_types'))->toContain('contact');
});

it('exposes platform-default contact subject options', function () {
    $defaults = config('partna.contact_subject_defaults');

    expect($defaults)
        ->toBeArray()
        ->toContain('General enquiry')
        ->toContain('Booking')
        ->toContain('Press')
        ->toContain('Collaboration')
        ->toContain('Other');
});
