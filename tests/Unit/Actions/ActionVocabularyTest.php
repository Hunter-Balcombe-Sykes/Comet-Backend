<?php

use App\Services\PublicSite\Actions\ActionVocabulary;
use Tests\TestCase;

// Pins the fixed 25-action vocabulary (23 static ids + 2 dynamic families) —
// LOCKSTEP with apps/pages test/actions-vocabulary.test.ts. Change both sides
// together or the wire and the frontend resolver drift.
uses(TestCase::class)->in(__FILE__);

it('exposes exactly the 23 static ids in canonical order', function () {
    expect(ActionVocabulary::STATIC_IDS)->toBe([
        'reservations', 'shop', 'shop-tracks', 'events', 'booking-services', 'menu', 'contact',
        'spotify', 'soundcloud', 'apple-music', 'apple-podcasts', 'twitch',
        'instagram', 'facebook', 'linkedin', 'youtube', 'tiktok', 'x',
        'snapchat', 'threads', 'discord', 'reddit', 'telegram',
    ]);
});

it('exposes exactly the 2 dynamic families', function () {
    expect(ActionVocabulary::FAMILIES)->toBe(['ordering', 'custom']);
});

it('validates static ids', function () {
    expect(ActionVocabulary::isValidId('menu'))->toBeTrue()
        ->and(ActionVocabulary::isValidId('reservations'))->toBeTrue()
        ->and(ActionVocabulary::isValidId('burger'))->toBeFalse()
        ->and(ActionVocabulary::isValidId(''))->toBeFalse();
});

it('validates family ids', function () {
    expect(ActionVocabulary::isValidId('ordering:order-abc123'))->toBeTrue()
        ->and(ActionVocabulary::isValidId('custom:9b2f1c34-aaaa-bbbb-cccc-121212121212'))->toBeTrue()
        ->and(ActionVocabulary::isValidId('custom:'))->toBeFalse()
        ->and(ActionVocabulary::isValidId('unknownfamily:abc'))->toBeFalse()
        ->and(ActionVocabulary::isValidId('ordering:'.str_repeat('a', 161)))->toBeFalse()
        ->and(ActionVocabulary::isValidId('custom:has a space'))->toBeFalse()
        ->and(ActionVocabulary::isValidId('custom:has/a/slash'))->toBeTrue()
        ->and(ActionVocabulary::isValidId('custom:has:a:colon'))->toBeTrue();
});

it('resolves priors: exact id, family prefix, then default', function () {
    config(['partna.actions.priors' => [
        'menu' => 0.28,
        'instagram' => 0.05,
        'ordering' => 0.25,
        'custom' => 0.05,
    ]]);
    config(['partna.actions.default_prior' => 0.05]);

    expect(ActionVocabulary::priorFor('menu'))->toBe(0.28)
        ->and(ActionVocabulary::priorFor('instagram'))->toBe(0.05)
        ->and(ActionVocabulary::priorFor('ordering:order-abc'))->toBe(0.25)
        ->and(ActionVocabulary::priorFor('custom:some-key'))->toBe(0.05)
        ->and(ActionVocabulary::priorFor('unknown-static-id'))->toBe(0.05);
});

it('labels every static id', function () {
    foreach (ActionVocabulary::STATIC_IDS as $id) {
        expect(ActionVocabulary::labelFor($id))->toBeString()->not->toBe('');
    }
    expect(ActionVocabulary::labelFor('booking-services'))->toBe('Booking and services')
        ->and(ActionVocabulary::labelFor('shop-tracks'))->toBe('Shop tracks')
        ->and(ActionVocabulary::labelFor('apple-music'))->toBe('Apple Music')
        ->and(ActionVocabulary::labelFor('x'))->toBe('X');
});
