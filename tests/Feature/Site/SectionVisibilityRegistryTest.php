<?php

use App\Services\User\Visibility\SectionVisibilityRegistry;

it('registers a rule for every requirement-bearing section type', function () {
    $registry = app(SectionVisibilityRegistry::class);

    $expected = [
        'gallery', 'documents', 'services', 'booking',
        'public_contact', 'workplace', 'contact',
    ];
    foreach ($expected as $type) {
        expect($registry->get($type))->not->toBeNull("missing rule: {$type}")
            ->and($registry->get($type)->blockType())->toBe($type);
    }
});

it('returns null for requirement-free section types', function () {
    $registry = app(SectionVisibilityRegistry::class);

    foreach (['contacts_collection', 'barbershop_info', 'newsletter', 'bio'] as $type) {
        expect($registry->get($type))->toBeNull();
    }
});
