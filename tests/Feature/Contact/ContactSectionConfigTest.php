<?php

it('registers contact as a section_block_type', function () {
    expect(config('partna.section_block_types'))->toContain('contact');
});

it('allows contact for individual account type', function () {
    expect(config('partna.account_type_defaults.individual.allowed_sections'))
        ->toContain('contact');
});

it('does NOT auto-provision contact in default_sections', function () {
    expect(config('partna.account_type_defaults.individual.default_sections'))
        ->not->toContain('contact');
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
