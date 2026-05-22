<?php

it('registers countdown as a section_block_type', function () {
    expect(config('partna.section_block_types'))->toContain('countdown');
});

it('allows countdown for individual account type', function () {
    expect(config('partna.account_type_defaults.individual.allowed_sections'))
        ->toContain('countdown');
});

it('does NOT auto-provision countdown in default_sections', function () {
    // Countdown is opt-in — professionals configure the timeline when they want one.
    expect(config('partna.account_type_defaults.individual.default_sections'))
        ->not->toContain('countdown');
});
