<?php

use App\Jobs\Design\ResolveDesignPresetsJob;
use App\Models\Core\Site\DesignKitContribution;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupDesignKitContributionsTable();
});

it('dispatches a preset resolve for every user with a stale previous-website or outside-websites contribution row', function () {
    Queue::fake();
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    DesignKitContribution::create([
        'site_id' => (string) $site->id, 'source' => 'previous-website:styles', 'integration' => 'website',
        'priority' => 84, 'mode' => 'fill', 'target_var' => 'color_accent', 'value' => '#ff5500',
    ]);

    $this->artisan('partna:sweep-stale-design-kit-contributions')->assertSuccessful();

    Queue::assertPushed(ResolveDesignPresetsJob::class, fn ($job) => $job->userId === (string) $user->id);
});

it('dedupes: one dispatch per user even with rows from both stale sources', function () {
    Queue::fake();
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    DesignKitContribution::create([
        'site_id' => (string) $site->id, 'source' => 'previous-website:styles', 'integration' => 'website',
        'priority' => 84, 'mode' => 'fill', 'target_var' => 'color_accent', 'value' => '#ff5500',
    ]);
    DesignKitContribution::create([
        'site_id' => (string) $site->id, 'source' => 'outside-websites:styles', 'integration' => 'custom',
        'priority' => 10, 'mode' => 'fill', 'target_var' => 'typography_font_family', 'value' => 'inter',
    ]);

    $this->artisan('partna:sweep-stale-design-kit-contributions')->assertSuccessful();

    Queue::assertPushed(ResolveDesignPresetsJob::class, 1);
});

it('does not dispatch for a currently-registered source', function () {
    Queue::fake();
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    DesignKitContribution::create([
        'site_id' => (string) $site->id, 'source' => 'sector:styles', 'integration' => 'sector',
        'priority' => 60, 'mode' => 'fill', 'target_var' => 'typography_font_family', 'value' => 'inter',
    ]);

    $this->artisan('partna:sweep-stale-design-kit-contributions')->assertSuccessful();

    Queue::assertNotPushed(ResolveDesignPresetsJob::class);
});

it('dry-run dispatches nothing', function () {
    Queue::fake();
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    DesignKitContribution::create([
        'site_id' => (string) $site->id, 'source' => 'previous-website:styles', 'integration' => 'website',
        'priority' => 84, 'mode' => 'fill', 'target_var' => 'color_accent', 'value' => '#ff5500',
    ]);

    $this->artisan('partna:sweep-stale-design-kit-contributions --dry-run')->assertSuccessful();

    Queue::assertNotPushed(ResolveDesignPresetsJob::class);
});

it('is a safe no-op when there are no stale rows', function () {
    Queue::fake();
    $this->artisan('partna:sweep-stale-design-kit-contributions')->assertSuccessful();
    Queue::assertNotPushed(ResolveDesignPresetsJob::class);
});
