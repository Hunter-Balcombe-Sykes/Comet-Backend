<?php

// tests/Feature/PreAccount/SignupPairingMatrixTest.php

use App\Enums\AccountType;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Queue;

// B2 (spec 2026-08-18-pipeline-assurance §5): the account_type × source_type
// pairing table, DERIVED from config('partna.pre_account.sources') ×
// AccountType::cases() × config('partna.pre_account.generators') at runtime, so a
// third source or a new pairing gets a row automatically. Allowed → 202, every
// other pair → 422 SOURCE_PAIRING_INVALID. Also pins: the two registries agree.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

dataset('pairing_matrix', function () {
    // Pest evaluates dataset() closures while building the test suite, BEFORE
    // any test's setUp() boots the app — config()/app() throw "Target class
    // [config] does not exist" here (no container instance exists yet). A
    // bare include() of config/partna.php still crashes the same way even
    // though 'pre_account' itself is config()-free: an unrelated top-level
    // entry ('analytics_endpoint') calls config('app.url') at array-literal
    // build time, and PHP evaluates every argument in the file regardless of
    // which key we actually want. Register a throwaway config repository
    // just so that one call resolves; the real Application replaces this
    // container wholesale in the first test's setUp()
    // (Illuminate\Foundation\Application::__construct() calls
    // static::setInstance($this) unconditionally), so none of this survives
    // into an actual test.
    $container = Container::getInstance();
    if (! $container->bound('config')) {
        $container->instance('config', new Repository(['app' => ['url' => 'http://localhost']]));
    }
    $raw = include dirname(__DIR__, 3).'/config/partna.php';
    $sources = $raw['pre_account']['sources'] ?? [];
    $generators = array_keys($raw['pre_account']['generators'] ?? []);
    $rows = [];
    $n = 0;
    foreach (AccountType::cases() as $type) {
        foreach ($generators as $sourceType) {
            $allowed = in_array($sourceType, $sources[$type->value] ?? [], true);
            $rows["{$type->value} × {$sourceType} → ".($allowed ? '202' : '422')] = [$type->value, $sourceType, $allowed, 'ref'.(++$n)];
        }
    }

    return $rows;
});

it('accepts allowed pairs with 202 and rejects every other pair with 422 SOURCE_PAIRING_INVALID', function (string $accountType, string $sourceType, bool $allowed, string $ref) {
    $body = ['account_type' => $accountType, 'source_type' => $sourceType, 'source_ref' => $ref];
    if ($sourceType === 'google_business') {
        $body['source_name'] = 'Acme '.$ref;
    }

    $res = $this->postJson('/api/public/signup/build', $body);

    if ($allowed) {
        $res->assertStatus(202)->assertJsonStructure(['build_id', 'build_state']);
    } else {
        $res->assertStatus(422)->assertJsonPath('code', 'SOURCE_PAIRING_INVALID');
    }
})->with('pairing_matrix');

it('lists every allowed source in the generator registry and every account type in the pairing map', function () {
    $sources = config('partna.pre_account.sources', []);
    $generators = array_keys(config('partna.pre_account.generators', []));

    foreach ($sources as $accountType => $allowedSources) {
        expect(AccountType::tryFrom($accountType))->not->toBeNull("sources map has unknown account_type '{$accountType}'");
        foreach ($allowedSources as $s) {
            // toContain is variadic over needles with no message param (Pest
            // Expectation.php:184) — a trailing string becomes a SECOND needle
            // to search for and would fail even when $s is present. Use the
            // PHPUnit assertion (available in Pest closures via TestCase) for
            // a real failure message instead.
            $this->assertContains($s, $generators, "sources map allows '{$s}' but no generator is registered");
        }
    }
    foreach (AccountType::cases() as $type) {
        // toHaveKey's second parameter is the EXPECTED VALUE, not a message
        // (Pest Expectation.php:628) — passing a string there would assert
        // $sources[$type->value] === that string, always false. Use the
        // PHPUnit assertion for a real failure message instead.
        $this->assertArrayHasKey($type->value, $sources, "account_type '{$type->value}' has no sources entry");
    }
});

it('rejects an unknown source_type at validation, before pairing', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'tiktok', 'source_ref' => 'x'])
        ->assertStatus(422)
        ->assertJsonMissingPath('code');
});
