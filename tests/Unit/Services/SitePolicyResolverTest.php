<?php

use App\Console\Commands\PurgeRawAnalyticsEvents;
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

// ── Truthfulness of the generated privacy policy ────────────────────────────
// The generated text is published on every sitepage under the OWNER'S OWN
// business name, so a claim in it is a claim that business is making. Until
// 2026-09-01 it made three the platform does not honour: that usage data "is
// aggregated and is not used to personally identify you", that the Site uses
// "only the minimal cookies and similar technologies needed for it to
// function", and that location is only "your approximate region". The
// analytics lane in fact mints a PERSISTENT per-visitor id in localStorage
// (pv_vid, apps/pages/src/analytics/beacon.ts) plus a per-tab sessionStorage
// id (pv_sid), attaches both to every beacon, and stores them alongside a
// city and rounded lat/lon derived from the visitor's IP
// (DetectsClientInfo::detectCity/detectLatitude/detectLongitude). These
// assertions pin the corrected text to that reality — they are content tests
// on purpose: the defect was never a crash, it was a sentence.

it('names the persistent browser identifier the beacon actually mints', function () {
    $text = app(SitePolicyResolver::class)->resolve(policyUser(), policySite())['privacy']['text'];

    expect($text)->toContain('pv_vid')
        ->and($text)->toContain('pv_sid')
        ->and($text)->toContain('local storage')
        ->and($text)->toContain('session storage')
        // The claim the identifier falsifies. Its survival is the whole bug.
        ->and($text)->not->toContain('not used to personally identify you')
        ->and($text)->not->toContain('This usage information is aggregated');
});

it('says approximate location is derived from the IP address, and names what is kept', function () {
    $text = app(SitePolicyResolver::class)->resolve(policyUser(), policySite())['privacy']['text'];

    expect($text)->toContain('IP address')
        ->and($text)->toContain('country')
        ->and($text)->toContain('city')
        ->and($text)->toContain('coordinates');
});

it('no longer claims cookie minimality, since the analytics storage is not functional', function () {
    $text = app(SitePolicyResolver::class)->resolve(policyUser(), policySite())['privacy']['text'];

    expect($text)->not->toContain('minimal cookies')
        ->and($text)->not->toContain('needed for it to function');
});

it('states the real retention window, tracking config rather than a hardcoded number', function () {
    config()->set('partna.analytics_raw_event_retention_days', 45);
    $text = app(SitePolicyResolver::class)->resolve(policyUser(), policySite())['privacy']['text'];

    expect($text)->toContain('45 days')
        ->and($text)->not->toContain('90 days');

    config()->set('partna.analytics_raw_event_retention_days', 120);
    $text = app(SitePolicyResolver::class)->resolve(policyUser(), policySite())['privacy']['text'];

    expect($text)->toContain('120 days');
});

it('promises no automatic deletion window when the purge floor means nothing is purged', function () {
    // Below PurgeRawAnalyticsEvents::MINIMUM_RETENTION_DAYS the purge command
    // aborts with FAILURE and deletes nothing, so naming a window there would
    // swap one false claim for a shorter one.
    config()->set('partna.analytics_raw_event_retention_days', 10);
    $text = app(SitePolicyResolver::class)->resolve(policyUser(), policySite())['privacy']['text'];

    expect($text)->not->toContain('10 days')
        ->and($text)->toContain('no fixed deletion date');
});

// The threshold that picks which of the two sentences a real business
// publishes had never been exercised AT the floor, only well below it, so
// `< MINIMUM` and `<= MINIMUM` were indistinguishable. Both sides are pinned
// here: exactly the floor is the configuration where the purge DOES run, and
// one day under is the configuration where it aborts.
it('names the window at exactly the retention floor, where the purge still runs', function () {
    config()->set('partna.analytics_raw_event_retention_days', PurgeRawAnalyticsEvents::MINIMUM_RETENTION_DAYS);
    $text = app(SitePolicyResolver::class)->resolve(policyUser(), policySite())['privacy']['text'];

    expect($text)->toContain('deleted automatically 30 days after they are recorded')
        ->and($text)->not->toContain('no fixed deletion date');
});

it('drops the window one day under the floor, where the purge aborts', function () {
    config()->set('partna.analytics_raw_event_retention_days', PurgeRawAnalyticsEvents::MINIMUM_RETENTION_DAYS - 1);
    $text = app(SitePolicyResolver::class)->resolve(policyUser(), policySite())['privacy']['text'];

    expect($text)->toContain('no fixed deletion date')
        ->and($text)->not->toContain('29 days');
});

// handle() calls retentionDays() AND scoresRetentionDays(), and either one
// below the floor returns FAILURE before the first DELETE — so a sub-floor
// scores window leaves the RAW events undeleted too. The policy checked only
// the raw window and kept promising automatic deletion in that configuration.
it('promises no automatic deletion when the scores window alone puts the purge below its floor', function () {
    config()->set('partna.analytics_raw_event_retention_days', 90);
    config()->set(
        'partna.analytics.content_popularity_scores_retention_days',
        PurgeRawAnalyticsEvents::MINIMUM_RETENTION_DAYS - 1,
    );

    $text = app(SitePolicyResolver::class)->resolve(policyUser(), policySite())['privacy']['text'];

    expect($text)->toContain('no fixed deletion date')
        ->and($text)->not->toContain('90 days')
        ->and($text)->not->toContain('deleted automatically');
});

it('keeps the window when the scores retention sits exactly on the floor', function () {
    config()->set('partna.analytics_raw_event_retention_days', 90);
    config()->set(
        'partna.analytics.content_popularity_scores_retention_days',
        PurgeRawAnalyticsEvents::MINIMUM_RETENTION_DAYS,
    );

    $text = app(SitePolicyResolver::class)->resolve(policyUser(), policySite())['privacy']['text'];

    expect($text)->toContain('deleted automatically 90 days after they are recorded')
        ->and($text)->not->toContain('no fixed deletion date');
});

it('carries the same corrections into the dashboard preview text', function () {
    $texts = app(SitePolicyResolver::class)->autoTexts(policyUser(), policySite());

    expect($texts['privacy'])->toContain('pv_vid')
        ->and($texts['privacy'])->toContain('IP address')
        ->and($texts['privacy'])->not->toContain('minimal cookies');
});
