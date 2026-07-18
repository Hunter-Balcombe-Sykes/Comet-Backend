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

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeTrue()
        ->and($user->fresh()->display_name)->toBe('Jane Doe')
        ->and($user->fresh()->first_name)->toBe('Jane');
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
