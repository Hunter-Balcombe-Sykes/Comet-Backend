<?php

use Tests\Authz\AuthzTestCase;
use Tests\Authz\Expectations;

uses(AuthzTestCase::class);

it('loads the checked-in file without error', function () {
    expect(Expectations::load())->toBeInstanceOf(Expectations::class);
});

it('rejects an exemption with no reason', function () {
    $yaml = <<<'YAML'
    - route: "api/foo/{id}"
      expect: exempt
    YAML;

    expect(fn () => Expectations::fromString($yaml))
        ->toThrow(RuntimeException::class, 'reason');
});

it('rejects an exemption with an empty reason', function () {
    $yaml = <<<'YAML'
    - route: "api/foo/{id}"
      expect: exempt
      reason: "   "
    YAML;

    expect(fn () => Expectations::fromString($yaml))
        ->toThrow(RuntimeException::class, 'reason');
});

it('rejects an unknown key so typos cannot silently do nothing', function () {
    $yaml = <<<'YAML'
    - route: "api/foo/{id}"
      expects: 404
    YAML;

    expect(fn () => Expectations::fromString($yaml))
        ->toThrow(RuntimeException::class, 'expects');
});

it('rejects duplicate entries for one route', function () {
    $yaml = <<<'YAML'
    - route: "api/foo/{id}"
      fixture: { id: "App\\Models\\Core\\Site\\Site" }
    - route: "api/foo/{id}"
      expect: exempt
      reason: "conflicting second entry"
    YAML;

    expect(fn () => Expectations::fromString($yaml))
        ->toThrow(RuntimeException::class, 'duplicate');
});

it('exposes fixture mappings and bodies', function () {
    $yaml = <<<'YAML'
    - route: "api/enquiries/{id}"
      fixture: { id: "App\\Models\\Core\\Site\\Enquiry" }
    - route: "api/routing/connections/{connection}/primary"
      fixture: { connection: "App\\Models\\Core\\User\\Service" }
      body: { primary: true }
    YAML;

    $e = Expectations::fromString($yaml);

    expect($e->fixtureFor('api/enquiries/{id}', 'id'))
        ->toBe('App\Models\Core\Site\Enquiry');
    expect($e->bodyFor('api/routing/connections/{connection}/primary'))
        ->toBe(['primary' => true]);
    expect($e->bodyFor('api/enquiries/{id}'))->toBe([]);
});
