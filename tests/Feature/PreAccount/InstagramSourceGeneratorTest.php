<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ProfileFetchFailure;
use App\Services\Platforms\ProfileFetchResult;
use App\Services\PreAccount\Generators\InstagramSourceGenerator;
use App\Services\PreAccount\SourceGenerationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates site.platform_connections (tests/Pest.php)
});

it('normalizes typed refs', function () {
    $gen = app(InstagramSourceGenerator::class);
    expect($gen->normalizeRef(' @JaneDoe '))->toBe('janedoe')
        ->and($gen->dedupeKey('janedoe'))->toBe('janedoe')
        ->and($gen->handleSeed('janedoe', null))->toBe('janedoe');
    $gen->normalizeRef('   ');
})->throws(InvalidArgumentException::class);

it('scrapes, seeds a connection, and writes profile fields onto the provisional user', function () {
    // IMPORTANT (repo gotcha): bind scraper mocks BEFORE any IntegrationConnection
    // is saved — the SEC-1 saving-guard resolves PlatformRegistry eagerly on first save.
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfileResult')->once()->with('janedoe', Mockery::type('string'))
        ->andReturn(ProfileFetchResult::ok(['fullName' => 'Jane Doe', 'biography' => 'Hair by Jane']));
    // Seeder path: stub the mirror-level collaborators via the seeder itself — mock it
    // wholesale; its own behavior is covered by InstagramConnectJob's existing tests.
    $seeder = Mockery::mock(InstagramConnectionSeeder::class);
    $seeder->shouldReceive('seed')->once()->andReturn(['fullName' => 'Jane Doe']);
    app()->instance(InstagramScraper::class, $scraper);
    app()->instance(InstagramConnectionSeeder::class, $seeder);

    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null, 'display_name' => 'janedoe', 'first_name' => 'janedoe']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(InstagramSourceGenerator::class)->generate($user, $site, 'janedoe');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->where('resource_id', 'instagram')->exists())->toBeTrue()
        ->and($user->fresh()->display_name)->toBe('Jane Doe')
        ->and($user->fresh()->first_name)->toBe('Jane');
});

it('drops internal auto-sync bookkeeping from the persisted payload while keeping the WYSIWYG preview (PRIV-2)', function () {
    // Drives the REAL seeder, stubbing only its vendor I/O. This test used to mock
    // InstagramConnectionSeeder wholesale and simulate its write, which worked while
    // the strip was a post-seed trim in the generator — but the strip now lives at
    // the writer, so a wholesale mock would assert against the simulation instead of
    // the code. The generator path still needs its own coverage: it is one of three
    // callers, and the only one where the owner is unclaimed by construction.
    Storage::fake('media');
    Queue::fake();

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfileResult')->once()->with('janedoe', Mockery::type('string'))
        ->andReturn(ProfileFetchResult::ok([
            'fullName' => 'Jane Doe',
            'biography' => 'Hair by Jane',
            'businessCategoryName' => 'Hair salon',
            'followersCount' => 1234,
            'postsCount' => 56,
        ]));
    $scraper->shouldReceive('latestMedia')->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->andReturn(null);
    // A real bio link, so bioLinks/syncFindings/unmatched are genuinely populated
    // before the strip — otherwise this could pass on an empty auto-sync run.
    $scraper->shouldReceive('bioLinks')->andReturn(['https://example.com/other-link']);
    app()->instance(InstagramScraper::class, $scraper);

    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null, 'display_name' => 'janedoe', 'first_name' => 'janedoe']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(InstagramSourceGenerator::class)->generate($user, $site, 'janedoe');

    $payload = IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->value('payload');

    // Asserted key by key, not via a negated toHaveKeys: a single negated
    // multi-key assertion passes when only ONE of them is absent.
    expect($payload)->not->toHaveKey('bioLinks')
        ->and($payload)->not->toHaveKey('syncFindings')
        ->and($payload)->not->toHaveKey('unmatched');
    // The preview fields the unclaimed sitepage renders from are untouched.
    expect($payload['username'])->toBe('janedoe')
        ->and($payload['fullName'])->toBe('Jane Doe')
        ->and($payload['businessCategory'])->toBe('Hair salon')
        ->and($payload['followersCount'])->toBe(1234)
        ->and($payload['postsCount'])->toBe(56);
});

it('maps a missing profile to source_not_found', function () {
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfileResult')->once()
        ->andReturn(ProfileFetchResult::failed(ProfileFetchFailure::ProfileNotFound));
    app()->instance(InstagramScraper::class, $scraper);

    $user = User::factory()->create(['status' => 'unclaimed']);
    $site = Site::factory()->create(['user_id' => $user->id]);

    try {
        app(InstagramSourceGenerator::class)->generate($user, $site, 'ghost');
        $this->fail('expected SourceGenerationException');
    } catch (SourceGenerationException $e) {
        expect($e->failureCode)->toBe(PreAccountBuild::FAILURE_SOURCE_NOT_FOUND);
    }
});

// The account exists and is public; only the upstream read broke (2026-08-10:
// Meta 400-ing its own logged-out profile endpoint). Reporting that as
// "source not found" tells a real prospect their own Instagram doesn't exist,
// and invites a retry that costs another paid scrape for the same answer.
it('maps an upstream scrape failure to scrape_failed, not source_not_found', function () {
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfileResult')->once()
        ->andReturn(ProfileFetchResult::failed(ProfileFetchFailure::ProfileUnavailable));
    app()->instance(InstagramScraper::class, $scraper);

    $user = User::factory()->create(['status' => 'unclaimed']);
    $site = Site::factory()->create(['user_id' => $user->id]);

    try {
        app(InstagramSourceGenerator::class)->generate($user, $site, 'crucibletattooco');
        $this->fail('expected SourceGenerationException');
    } catch (SourceGenerationException $e) {
        expect($e->failureCode)->toBe(PreAccountBuild::FAILURE_SCRAPE_FAILED);
    }
});

it('maps a configuration failure to scrape_failed rather than blaming the handle', function () {
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfileResult')->once()
        ->andReturn(ProfileFetchResult::failed(ProfileFetchFailure::NotConfigured));
    app()->instance(InstagramScraper::class, $scraper);

    $user = User::factory()->create(['status' => 'unclaimed']);
    $site = Site::factory()->create(['user_id' => $user->id]);

    try {
        app(InstagramSourceGenerator::class)->generate($user, $site, 'janedoe');
        $this->fail('expected SourceGenerationException');
    } catch (SourceGenerationException $e) {
        expect($e->failureCode)->toBe(PreAccountBuild::FAILURE_SCRAPE_FAILED);
    }
});

// Actor field-shape drift is why display_name silently became the handle from
// ~2026-07-21: figue switched to Instagram's raw GraphQL snake_case, and this
// generator read only camelCase `fullName` — unlike InstagramConnector and
// InstagramConnectionSeeder, which read either. The `if` never fired, so the
// placeholder handle set at build time survived as the display name.
it('writes display_name from a snake_case full_name payload (actor shape drift)', function () {
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfileResult')->once()->with('janedoe', Mockery::type('string'))
        ->andReturn(ProfileFetchResult::ok(['full_name' => 'Jane Doe', 'biography' => 'Hair by Jane']));
    $seeder = Mockery::mock(InstagramConnectionSeeder::class);
    $seeder->shouldReceive('seed')->once()->andReturn(['fullName' => 'Jane Doe']);
    app()->instance(InstagramScraper::class, $scraper);
    app()->instance(InstagramConnectionSeeder::class, $seeder);

    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null, 'display_name' => 'janedoe', 'first_name' => 'janedoe']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(InstagramSourceGenerator::class)->generate($user, $site, 'janedoe');

    expect($user->fresh()->display_name)->toBe('Jane Doe')
        ->and($user->fresh()->first_name)->toBe('Jane');
});

it('a descriptor vanity name never becomes a person name — the gate is wired, not just written', function () {
    // The 2026-09-01 re-audit exhibit: "Brisbane Personal Trainer" split into
    // first "Brisbane" / last "Trainer" by the fallback parse, and the AI lane
    // has produced the same shape ("The"/"Edit"). This test exercises the
    // GENERATOR path, because NameShapeGateTest alone left the call site
    // mutable — bypassing NameShapeGate::apply() in generate() survived every
    // existing test (proved by mutation, 2026-09-01).
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfileResult')->once()->with('cassandraskinnerpt', Mockery::type('string'))
        ->andReturn(ProfileFetchResult::ok(['fullName' => 'Brisbane Personal Trainer', 'biography' => null]));
    $seeder = Mockery::mock(InstagramConnectionSeeder::class);
    $seeder->shouldReceive('seed')->once()->andReturn(['fullName' => 'Brisbane Personal Trainer']);
    app()->instance(InstagramScraper::class, $scraper);
    app()->instance(InstagramConnectionSeeder::class, $seeder);

    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null, 'display_name' => 'cassandraskinnerpt', 'first_name' => 'cassandraskinnerpt']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(InstagramSourceGenerator::class)->generate($user, $site, 'cassandraskinnerpt');

    $fresh = $user->fresh();
    // The handle plainly contains her name, so the gate recovers it — and a
    // descriptor pair ("Brisbane"/"Trainer") must never survive as first/last.
    expect($fresh->display_name)->toBe('Cassandra Skinner')
        ->and($fresh->first_name)->toBe('Cassandra')
        ->and($fresh->last_name)->toBe('Skinner');
});
