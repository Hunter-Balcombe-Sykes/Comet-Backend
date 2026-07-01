<?php

use App\Services\User\Visibility\SectionVisibilityRegistry;

it('registers a rule for every requirement-bearing section type', function () {
    $registry = app(SectionVisibilityRegistry::class);

    $expected = [
        'gallery', 'documents', 'services', 'booking', 'credentials',
        'experience', 'public_contact', 'workplace', 'countdown', 'contact',
    ];
    foreach ($expected as $type) {
        expect($registry->get($type))->not->toBeNull("missing rule: {$type}")
            ->and($registry->get($type)->blockType())->toBe($type);
    }
});

it('returns null for requirement-free section types', function () {
    $registry = app(SectionVisibilityRegistry::class);

    foreach (['contacts_collection', 'sitepage_analytics', 'barbershop_info', 'newsletter', 'bio'] as $type) {
        expect($registry->get($type))->toBeNull();
    }
});
