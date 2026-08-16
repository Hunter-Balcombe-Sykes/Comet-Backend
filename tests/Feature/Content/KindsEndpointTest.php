<?php

/**
 * GET /api/content/kinds — the schema the dashboard's field renderer consumes.
 *
 * The endpoint is a pure projection of KindRegistry + FacetRegistry +
 * PoolRegistry, so these tests pin the CONTRACT rather than the data: that
 * every kind is described, that `columns` is empty exactly when the kind is
 * uneditable, that pool membership and the pool's curation permissions ride
 * along, and that no column outside FacetRegistry ever appears. Those are the
 * four things a client would silently mis-render if they drifted.
 */

use App\Models\Core\User\User;
use App\Services\Content\FacetRegistry;
use App\Services\Content\KindRegistry;
use App\Site\Pools\PoolRegistry;

beforeEach(function () {
    setupUsersTable();
});

function kindsUser(): User
{
    return User::factory()->create();
}

it('requires authentication', function () {
    $this->getJson('/api/content/kinds')->assertUnauthorized();
});

it('describes every registered kind', function () {
    $body = actingAsUser(kindsUser())
        ->getJson('/api/content/kinds')
        ->assertOk()
        ->json();

    $returned = collect($body['kinds'])->pluck('kind')->all();

    expect($returned)->toEqualCanonicalizing(KindRegistry::kinds());
});

it('carries the full per-kind shape the renderer reads', function () {
    $body = actingAsUser(kindsUser())->getJson('/api/content/kinds')->assertOk()->json();

    $video = collect($body['kinds'])->firstWhere('kind', 'video');

    expect($video)->toHaveKeys([
        'kind', 'label', 'plural', 'profile', 'facets', 'columns',
        'pinnable', 'editable', 'orderable', 'mayDelete', 'staleDisplayDefault',
        'pool', 'poolAllowsPin', 'poolAllowsManualAdd',
    ]);

    expect($video['pool'])->toBe('watch')
        ->and($video['editable'])->toBeTrue()
        ->and($video['poolAllowsPin'])->toBeTrue()
        ->and($video['poolAllowsManualAdd'])->toBeTrue();
});

/**
 * The load-bearing one. An uneditable kind advertising editable columns would
 * invite a form the API answers with 422 — which is the exact failure
 * KindRegistry's own comment says `columns` exists to prevent.
 */
it('advertises columns only for editable kinds', function () {
    $body = actingAsUser(kindsUser())->getJson('/api/content/kinds')->assertOk()->json();

    foreach ($body['kinds'] as $kind) {
        expect($kind['columns'] === [])->toBe(! $kind['editable']);
    }
});

it('reports the reviews pool as locked in every direction', function () {
    $body = actingAsUser(kindsUser())->getJson('/api/content/kinds')->assertOk()->json();

    $review = collect($body['kinds'])->firstWhere('kind', 'review');

    expect($review['pool'])->toBe('reviews')
        ->and($review['editable'])->toBeFalse()
        ->and($review['pinnable'])->toBeFalse()
        ->and($review['orderable'])->toBeFalse()
        ->and($review['mayDelete'])->toBeFalse()
        ->and($review['columns'])->toBe([])
        ->and($review['poolAllowsPin'])->toBeFalse()
        ->and($review['poolAllowsManualAdd'])->toBeFalse();
});

it('reports a poolless kind as uncuratable rather than omitting it', function () {
    $body = actingAsUser(kindsUser())->getJson('/api/content/kinds')->assertOk()->json();

    $document = collect($body['kinds'])->firstWhere('kind', 'document');

    expect($document)->not->toBeNull()
        ->and($document['pool'])->toBeNull()
        ->and($document['poolAllowsPin'])->toBeFalse()
        ->and($document['poolAllowsManualAdd'])->toBeFalse();
});

it('never advertises a column the override endpoint would refuse', function () {
    $body = actingAsUser(kindsUser())->getJson('/api/content/kinds')->assertOk()->json();

    foreach ($body['kinds'] as $kind) {
        foreach ($kind['columns'] as $column) {
            expect(FacetRegistry::allows($column['facet'], $column['column']))->toBeTrue();
        }
    }
});

it('sends the flat facet table alongside the per-kind lists', function () {
    $body = actingAsUser(kindsUser())->getJson('/api/content/kinds')->assertOk()->json();

    expect($body['facets'])->toEqualCanonicalizing(FacetRegistry::facets());

    foreach ($body['columns'] as $column) {
        expect($column)->toHaveKeys(['facet', 'column', 'type'])
            ->and(FacetRegistry::allows($column['facet'], $column['column']))->toBeTrue();
    }
});

it('agrees with PoolRegistry on which pool owns each kind', function () {
    $body = actingAsUser(kindsUser())->getJson('/api/content/kinds')->assertOk()->json();

    foreach ($body['kinds'] as $kind) {
        expect($kind['pool'])->toBe(PoolRegistry::poolForKind($kind['kind']));
    }
});
