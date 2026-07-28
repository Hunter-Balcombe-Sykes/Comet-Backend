<?php

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('lists every kind the content schema allows', function () {
    $pro = createTenant('kinds-list');

    $response = actingAsUser($pro)->getJson('/api/content/kinds');

    $response->assertOk();
    // Fourteen, closed — the same set as the content.items CHECK constraint.
    expect($response->json('kinds'))->toHaveCount(14);
});

it('refuses to let reviews be edited or pinned', function () {
    // Showing or hiding a review is curation; editing one would be forgery.
    $pro = createTenant('kinds-reviews');

    $review = collect(actingAsUser($pro)->getJson('/api/content/kinds')->json('kinds'))
        ->firstWhere('kind', 'review');

    expect($review['editable'])->toBeFalse()
        ->and($review['pinnable'])->toBeFalse()
        ->and($review['orderable'])->toBeFalse()
        // An editable-column list would invite an edit form the API refuses.
        ->and($review['columns'])->toBe([])
        ->and($review['profile'])->toBe('sample');
});

it('lets ordinary content be edited and pinned', function () {
    $pro = createTenant('kinds-video');

    $video = collect(actingAsUser($pro)->getJson('/api/content/kinds')->json('kinds'))
        ->firstWhere('kind', 'video');

    expect($video['editable'])->toBeTrue()
        ->and($video['pinnable'])->toBeTrue()
        ->and(collect($video['columns'])->pluck('column'))->toContain('headline');
});

it('never advertises an identity column as hand-editable', function () {
    // Retyping an ISRC would not correct the catalogue, it would poison the
    // resolver's joining keys.
    $pro = createTenant('kinds-identity');

    $track = collect(actingAsUser($pro)->getJson('/api/content/kinds')->json('kinds'))
        ->firstWhere('kind', 'track');

    expect(collect($track['columns'])->pluck('column'))->not->toContain('isrc')
        ->and(collect($track['columns'])->pluck('column'))->not->toContain('gtin');
});

it('requires authentication', function () {
    $this->getJson('/api/content/kinds')->assertStatus(401);
});
