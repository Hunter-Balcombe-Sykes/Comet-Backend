<?php

// #MIG-1: real-Postgres coverage for ScrubUnifiedActionsLegacyCommand
// (app/Console/Commands/ScrubUnifiedActionsLegacyCommand.php), the re-runnable
// half of the unified-actions data cleanup that used to be inline DML in
// supabase/migrations/20260823100000_unified_actions.sql. SQLite cannot
// exercise this at all — the predicates use `!~` (POSIX regex) and
// jsonb_exists_any(), neither of which SQLite implements — so this is the
// ONLY lane that proves the command's actual behaviour. Runs via
// `composer test:pg` / phpunit.pg.xml.
//
// Minimal self-provisioned schema (like tests/Postgres/ContentRetentionConstraintsTest
// .php): only the columns the command's predicates and this test's assertions
// touch, narrowed from the real DDL in supabase/migrations/20260726000000_baseline_pilot.sql.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS analytics');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');

    $pg->statement('DROP TABLE IF EXISTS analytics.action_events');
    $pg->statement('DROP TABLE IF EXISTS analytics.content_popularity_scores');
    $pg->statement('DROP TABLE IF EXISTS site.sites');

    $pg->statement('CREATE TABLE analytics.action_events (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        site_id uuid NOT NULL,
        action_id text NOT NULL,
        event text NOT NULL,
        occurred_at timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE analytics.content_popularity_scores (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        site_id uuid NOT NULL,
        content_type text NOT NULL,
        content_key text NOT NULL,
        score double precision NOT NULL,
        rank integer NOT NULL,
        computed_at timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE site.sites (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL,
        subdomain text NOT NULL,
        settings jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
});

function ualsSeedActionEvent(string $actionId): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('analytics.action_events')->insert([
        'id' => $id,
        'site_id' => (string) Str::uuid(),
        'action_id' => $actionId,
        'event' => 'seen',
    ]);

    return $id;
}

function ualsSeedSiteWithSettings(array $settings): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $id,
        'user_id' => (string) Str::uuid(),
        'subdomain' => 'scrub-'.Str::random(8),
        'settings' => json_encode($settings),
    ]);

    return $id;
}

function ualsSeedScoreRow(string $contentType): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => $id,
        'site_id' => (string) Str::uuid(),
        'content_type' => $contentType,
        'content_key' => 'k-'.Str::random(6),
        'score' => 1.0,
        'rank' => 1,
    ]);

    return $id;
}

it('deletes only legacy-vocabulary action_events rows, leaving <kind>:<ref> rows untouched', function () {
    $legacy1 = ualsSeedActionEvent('instagram');
    $legacy2 = ualsSeedActionEvent('menu');
    $conforming1 = ualsSeedActionEvent('page:home');
    $conforming2 = ualsSeedActionEvent('platform:instagram');
    $conforming3 = ualsSeedActionEvent('item:abc123');
    $conforming4 = ualsSeedActionEvent('category:shop');

    $this->artisan('partna:scrub-unified-actions-legacy', ['--only' => 'action-events'])
        ->assertExitCode(0);

    $remaining = DB::connection('pgsql')->table('analytics.action_events')->pluck('id')->all();

    expect($remaining)->not->toContain($legacy1)
        ->and($remaining)->not->toContain($legacy2)
        ->and($remaining)->toContain($conforming1)
        ->and($remaining)->toContain($conforming2)
        ->and($remaining)->toContain($conforming3)
        ->and($remaining)->toContain($conforming4)
        ->and($remaining)->toHaveCount(4);
});

it('strips only the legacy settings keys from site.sites, leaving unrelated keys and conforming sites untouched', function () {
    $legacySite = ualsSeedSiteWithSettings(['smart_actions' => ['a'], 'manual_actions' => ['b'], 'unrelated_key' => 'keep-me']);
    $conformingSite = ualsSeedSiteWithSettings(['actions' => ['x'], 'pool_order' => ['y']]);

    $this->artisan('partna:scrub-unified-actions-legacy', ['--only' => 'site-settings'])
        ->assertExitCode(0);

    $legacySettings = json_decode(DB::connection('pgsql')->table('site.sites')->where('id', $legacySite)->value('settings'), true);
    $conformingSettings = json_decode(DB::connection('pgsql')->table('site.sites')->where('id', $conformingSite)->value('settings'), true);

    expect($legacySettings)->not->toHaveKey('smart_actions')
        ->and($legacySettings)->not->toHaveKey('manual_actions')
        ->and($legacySettings)->toHaveKey('unrelated_key', 'keep-me')
        ->and($conformingSettings)->toBe(['actions' => ['x'], 'pool_order' => ['y']]);
});

it('does not change site.sites.updated_at when scrubbing settings — a deliberate choice, not an oversight', function () {
    $siteId = ualsSeedSiteWithSettings(['smart_actions' => ['a']]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');

    // Postgres timestamp resolution is sub-microsecond; sleep a moment so an
    // accidental bump would be observably different, not masked by equal
    // clock reads within the same statement.
    usleep(50_000);

    $this->artisan('partna:scrub-unified-actions-legacy', ['--only' => 'site-settings'])
        ->assertExitCode(0);

    $after = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');

    expect($after)->toBe($before);
});

it('deletes only page-typed popularity scores, leaving every other content_type untouched', function () {
    $page1 = ualsSeedScoreRow('page');
    $page2 = ualsSeedScoreRow('page');
    $action = ualsSeedScoreRow('action');
    $shopProduct = ualsSeedScoreRow('shop_product');

    $this->artisan('partna:scrub-unified-actions-legacy', ['--only' => 'page-scores'])
        ->assertExitCode(0);

    $remaining = DB::connection('pgsql')->table('analytics.content_popularity_scores')->pluck('id')->all();

    expect($remaining)->not->toContain($page1)
        ->and($remaining)->not->toContain($page2)
        ->and($remaining)->toContain($action)
        ->and($remaining)->toContain($shopProduct)
        ->and($remaining)->toHaveCount(2);
});

it('is idempotent — a second run reports 0 rows affected across all three steps', function () {
    ualsSeedActionEvent('instagram');
    ualsSeedSiteWithSettings(['smart_actions' => ['a']]);
    ualsSeedScoreRow('page');

    $this->artisan('partna:scrub-unified-actions-legacy')->assertExitCode(0);

    // Second run: nothing left to touch.
    $this->artisan('partna:scrub-unified-actions-legacy')
        ->expectsOutputToContain('Affected 0 total row(s)')
        ->assertExitCode(0);
});

it('--dry-run writes nothing across all three steps', function () {
    $legacyEvent = ualsSeedActionEvent('instagram');
    $legacySite = ualsSeedSiteWithSettings(['smart_actions' => ['a']]);
    $legacyScore = ualsSeedScoreRow('page');

    $this->artisan('partna:scrub-unified-actions-legacy', ['--dry-run' => true])
        ->expectsOutputToContain('Would affect 3 total row(s)')
        ->assertExitCode(0);

    expect(DB::connection('pgsql')->table('analytics.action_events')->where('id', $legacyEvent)->exists())->toBeTrue();
    $settings = json_decode(DB::connection('pgsql')->table('site.sites')->where('id', $legacySite)->value('settings'), true);
    expect($settings)->toHaveKey('smart_actions');
    expect(DB::connection('pgsql')->table('analytics.content_popularity_scores')->where('id', $legacyScore)->exists())->toBeTrue();
});

it('--chunk=2 over 5 legacy action_events rows still ends at 0 remaining', function () {
    for ($i = 0; $i < 5; $i++) {
        ualsSeedActionEvent('instagram-'.$i);
    }

    $this->artisan('partna:scrub-unified-actions-legacy', ['--only' => 'action-events', '--chunk' => 2])
        ->assertExitCode(0);

    expect(DB::connection('pgsql')->table('analytics.action_events')->count())->toBe(0);
});

it('rejects a non-positive --chunk before touching the database', function () {
    $legacyEvent = ualsSeedActionEvent('instagram');

    $this->artisan('partna:scrub-unified-actions-legacy', ['--chunk' => 0])
        ->expectsOutputToContain('--chunk must be a positive integer')
        ->assertExitCode(1);

    expect(DB::connection('pgsql')->table('analytics.action_events')->where('id', $legacyEvent)->exists())->toBeTrue();
});
