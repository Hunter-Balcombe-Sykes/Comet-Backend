<?php

use App\Enums\PublicFeature;

it('maps each case to its feature.<name> availability key', function () {
    expect(PublicFeature::Enquiries->availabilityKey())->toBe('feature.enquiries')
        ->and(PublicFeature::EmailSignup->availabilityKey())->toBe('feature.email_signup')
        ->and(PublicFeature::CustomerLeads->availabilityKey())->toBe('feature.customer_leads');
});

it('enumerates exactly the three enforceable features', function () {
    expect(collect(PublicFeature::cases())->map->value->all())
        ->toBe(['enquiries', 'email_signup', 'customer_leads']);
});

it('has a non-empty human label for every case', function () {
    foreach (PublicFeature::cases() as $feature) {
        expect($feature->label())->toBeString()->not->toBe('');
    }
});
