<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Site\SitePolicyResolver;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Pure-logic coverage for the policy resolver — the single source of truth
// for the public payload's `policies` key and the dashboard's automated
// preview. No DB: in-memory models are enough for every branch.

function policyUser(string $accountType = 'partna'): User
{
    return new User([
        'handle' => 'jane-doe',
        'display_name' => 'Jane Doe',
        'account_type' => $accountType,
    ]);
}

function policySite(array $privacy = []): Site
{
    return new Site(['settings' => $privacy === [] ? [] : ['privacy' => $privacy]]);
}

it('defaults both policies to auto with personalized sections', function () {
    $resolved = app(SitePolicyResolver::class)->resolve(policyUser(), policySite());

    expect($resolved['privacy']['mode'])->toBe('auto')
        ->and($resolved['terms']['mode'])->toBe('auto')
        ->and($resolved['privacy']['sections'])->not->toBeNull()
        ->and($resolved['privacy']['text'])->toContain('Jane Doe')
        ->and($resolved['terms']['text'])->toContain('Jane Doe');

    $domain = config('partna.public_domain');
    expect($resolved['privacy']['text'])->toContain("https://jane-doe.{$domain}")
        ->and($resolved['terms']['text'])->toContain("https://jane-doe.{$domain}");
});

it('uses the workplace name for business accounts', function () {
    $resolved = app(SitePolicyResolver::class)->resolve(
        policyUser('business'),
        policySite(),
        'Marion Wine Bar',
    );

    expect($resolved['privacy']['text'])->toContain('Marion Wine Bar')
        ->and($resolved['privacy']['text'])->not->toContain('Jane Doe');
});

it('ignores the workplace name for standard accounts', function () {
    $resolved = app(SitePolicyResolver::class)->resolve(
        policyUser(),
        policySite(),
        'Marion Wine Bar',
    );

    expect($resolved['privacy']['text'])->toContain('Jane Doe');
});

it('uses manual text when automated is off and text is present — per policy, not both', function () {
    $resolved = app(SitePolicyResolver::class)->resolve(policyUser(), policySite([
        'automated_privacy' => false,
        'privacy_manual_text' => 'My own privacy words.',
    ]));

    expect($resolved['privacy'])->toMatchArray([
        'mode' => 'manual',
        'text' => 'My own privacy words.',
        'sections' => null,
    ])->and($resolved['terms']['mode'])->toBe('auto');
});

it('falls back to auto when automated is off but manual text is empty', function () {
    $resolved = app(SitePolicyResolver::class)->resolve(policyUser(), policySite([
        'automated_privacy' => false,
        'privacy_manual_text' => '   ',
    ]));

    expect($resolved['privacy']['mode'])->toBe('auto')
        ->and($resolved['privacy']['sections'])->not->toBeNull();
});

it('personalizes the contact line with the public contact email when known', function () {
    $with = app(SitePolicyResolver::class)->resolve(policyUser(), policySite(), null, 'hi@janedoe.com');
    $without = app(SitePolicyResolver::class)->resolve(policyUser(), policySite());

    expect($with['privacy']['text'])->toContain('hi@janedoe.com')
        ->and($without['privacy']['text'])->not->toContain('@');
});

it('emits flat auto texts (heading + body blocks) for the dashboard preview', function () {
    $texts = app(SitePolicyResolver::class)->autoTexts(policyUser(), policySite());

    expect($texts['privacy'])->toContain("Overview\n\n")
        ->and($texts['privacy'])->toContain('Your rights')
        ->and($texts['terms'])->toContain('Governing law')
        ->and($texts['terms'])->toContain('Australian Consumer Law');
});
