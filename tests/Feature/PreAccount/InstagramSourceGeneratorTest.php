<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use App\Services\PreAccount\Generators\InstagramSourceGenerator;
use App\Services\PreAccount\SourceGenerationException;

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
    $scraper->shouldReceive('fetchProfile')->once()->with('janedoe', Mockery::type('string'))
        ->andReturn(['fullName' => 'Jane Doe', 'biography' => 'Hair by Jane']);
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
    // The real seeder does R2 mirroring + HTTP fetches — stub it wholesale (as the
    // test above does) but simulate its real write so the generator's post-seed
    // trim has something real to trim.
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->with('janedoe', Mockery::type('string'))
        ->andReturn(['fullName' => 'Jane Doe', 'biography' => 'Hair by Jane']);

    $seeder = Mockery::mock(InstagramConnectionSeeder::class);
    $seeder->shouldReceive('seed')->once()->andReturnUsing(function (IntegrationConnection $connection) {
        $selection = [
            'username' => 'janedoe',
            'fullName' => 'Jane Doe',
            'businessCategory' => 'Hair salon',
            'followersCount' => 1234,
            'postsCount' => 56,
            'images' => ['https://cdn.example/photo.jpg'],
            'videoUrl' => 'https://cdn.example/reel.mp4',
            'videoPoster' => 'https://cdn.example/reel-cover.jpg',
            'website' => 'https://janedoe.example',
            'bioLinks' => ['https://instagram.com/janedoe/tiktok'],
            'syncFindings' => [['platform' => 'tiktok', 'outcome' => 'seeded']],
            'unmatched' => ['https://example.com/other-link'],
        ];
        $connection->update([
            'payload' => $selection,
            'is_active' => true,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ]);

        return $selection;
    });
    app()->instance(InstagramScraper::class, $scraper);
    app()->instance(InstagramConnectionSeeder::class, $seeder);

    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null, 'display_name' => 'janedoe', 'first_name' => 'janedoe']);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(InstagramSourceGenerator::class)->generate($user, $site, 'janedoe');

    $payload = IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->value('payload');

    expect($payload)->not->toHaveKeys(['bioLinks', 'syncFindings', 'unmatched'])
        ->and($payload['images'])->toBe(['https://cdn.example/photo.jpg'])
        ->and($payload['videoUrl'])->toBe('https://cdn.example/reel.mp4')
        ->and($payload['videoPoster'])->toBe('https://cdn.example/reel-cover.jpg')
        ->and($payload['followersCount'])->toBe(1234)
        ->and($payload['postsCount'])->toBe(56)
        ->and($payload['businessCategory'])->toBe('Hair salon');
});

it('maps a missing profile to source_not_found', function () {
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturnNull();
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
