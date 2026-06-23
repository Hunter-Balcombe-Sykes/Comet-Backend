<?php

/**
 * STRIP-4: Verify that post-standalone dead section types are no longer in
 * account_type_defaults. 'shop' was removed with the Shopify commerce strip;
 * it must not reappear in allowed_sections or default_sections.
 */
it('does NOT list shop in individual allowed_sections', function () {
    expect(config('partna.account_type_defaults.individual.allowed_sections'))
        ->not->toContain('shop');
});

it('does NOT list shop in individual default_sections', function () {
    expect(config('partna.account_type_defaults.individual.default_sections'))
        ->not->toContain('shop');
});

it('still lists booking in individual allowed_sections', function () {
    // booking is a live section type (SectionVisibilityService, SitepageDataResolverService)
    // and must remain in the allowed set.
    expect(config('partna.account_type_defaults.individual.allowed_sections'))
        ->toContain('booking');
});
