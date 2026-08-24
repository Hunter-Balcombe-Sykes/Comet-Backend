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
