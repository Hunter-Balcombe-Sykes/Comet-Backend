<?php

use App\Models\Core\Site\Site;
use App\Site\Actions\ActionSettings;

it('defaults to newest with no slots', function () {
    $s = ActionSettings::fromSite(null);
    expect($s->mode)->toBe('newest')->and($s->slots)->toBe([]);
    $s = ActionSettings::fromSite(new Site(['settings' => ['actions' => ['mode' => 'bogus']]]));
    expect($s->mode)->toBe('newest');
});

it('reads mode and sorts slots by position, dropping malformed rows', function () {
    $s = ActionSettings::fromSite(new Site(['settings' => ['actions' => [
        'mode' => 'smart',
        'slots' => [['position' => 3, 'id' => 'item:b'], ['position' => 0, 'id' => 'page:services'], ['id' => 'x'], 'junk', ['position' => 1, 'id' => 'legacy']],
    ]]]));
    expect($s->mode)->toBe('smart')
        ->and($s->slots)->toBe([['position' => 0, 'id' => 'page:services'], ['position' => 3, 'id' => 'item:b']]);
});

it('reads pool modes sparsely with newest default; events and unknown pools are ignored', function () {
    $site = new Site(['settings' => ['pool_order' => ['watch' => 'smart', 'events' => 'smart', 'bogus' => 'manual', 'listen' => 'nope']]]);
    expect(ActionSettings::poolModes($site))->toBe(['watch' => 'smart'])
        ->and(ActionSettings::fromSite($site)->poolMode('watch'))->toBe('smart')
        ->and(ActionSettings::fromSite($site)->poolMode('listen'))->toBe('newest');
});

it('reads pool locks per pool sorted by position, dropping malformed rows and unknown pools', function () {
    $site = new Site(['settings' => ['pool_locks' => [
        'watch' => [['position' => 2, 'id' => 'b'], ['position' => 0, 'id' => 'a'], ['id' => 'x'], 'junk'],
        'events' => [['position' => 0, 'id' => 'e']],
    ]]]);
    expect(ActionSettings::poolLocks($site))->toBe(['watch' => [['position' => 0, 'id' => 'a'], ['position' => 2, 'id' => 'b']]])
        ->and(ActionSettings::fromSite($site)->poolLocksFor('watch'))->toHaveCount(2)
        ->and(ActionSettings::fromSite($site)->poolLocksFor('listen'))->toBe([]);
});

// D2 (2026-08-27 unclaimed-signup quality plan, issue 6): `newest` is a
// meaningless order for menus/services — undated items sort by ingestion
// recency, which inverted St Ali's curated Uber Eats menu (scan stragglers
// first, the store's own first section dead last, verified on the live wire).
// Those two pools default to `smart`: identical to the curated stored-position
// order until popularity data exists (every fresh signup), engagement-ranked
// after claim. An explicit owner setting still wins. Recency pools keep newest.
it('defaults menus and services to smart; explicit settings still win (D2)', function () {
    $bare = ActionSettings::fromSite(null);
    expect($bare->poolMode('menus'))->toBe('smart')
        ->and($bare->poolMode('services'))->toBe('smart')
        ->and($bare->poolMode('watch'))->toBe('newest')
        ->and($bare->poolMode('listen'))->toBe('newest')
        ->and($bare->poolMode('media'))->toBe('newest');

    $site = new Site(['settings' => ['pool_order' => ['menus' => 'manual', 'services' => 'newest']]]);
    $s = ActionSettings::fromSite($site);
    expect($s->poolMode('menus'))->toBe('manual')
        ->and($s->poolMode('services'))->toBe('newest');
});
