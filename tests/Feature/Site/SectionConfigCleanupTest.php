<?php

/**
 * STRIP-4: Verify that post-standalone dead section types are no longer in
 * the section registry. 'shop' was removed with the Shopify commerce strip;
 * it must not reappear in section_block_types (the config that drives
 * section provisioning via syncAllowedSections).
 */
it('does NOT list shop in section_block_types', function () {
    expect(config('partna.section_block_types'))
        ->not->toContain('shop');
});

it('still lists booking in section_block_types', function () {
    // booking is a live section type (SectionVisibilityService, SitepageDataResolverService)
    // and must remain in the allowed set.
    expect(config('partna.section_block_types'))
        ->toContain('booking');
});
