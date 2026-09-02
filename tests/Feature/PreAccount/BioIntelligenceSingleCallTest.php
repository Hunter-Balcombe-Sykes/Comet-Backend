<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramIdentitySync;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ProfileFetchResult;
use App\Services\PreAccount\Generators\InstagramSourceGenerator;
use App\Services\Profile\BioSource;
use App\Services\Profile\ProfileEnricher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * BioIntelligence::analyse() is a PAID model call, and AiSpendBudget::tryClaim is
 * a DAILY limiter, not a per-request cache — so a second call inside one build is
 * simply billed twice.
 *
 * It ran twice on every Instagram build. InstagramSourceGenerator::generate()
 * analysed the profile, then seed() reached InstagramIdentitySync::applyIdentity,
 * which analysed the SAME handle/fullName/biography again whenever any of
 * bio/email/phone was still blank. Instagram withholds business email and phone
 * from a logged-out scrape (applyContactFields' docblock), so those two are
 * ALWAYS blank after the first pass — the duplicate was the normal case, not an
 * edge one. The result is now threaded through instead.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
    setupPreAccountBuildsTable(); // A.7: the IG generator reads built_via

    config([
        'services.deepseek.key' => 'test-key',
        'partna.limits.ai_spend.actors.deepseek_bio' => 100,
        'partna.limits.ai_spend.global_daily_cap' => 1000,
    ]);
});

function igModelCalls(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.deepseek.com'))
        ->values()
        ->all();
}

it('makes exactly one model call across the whole instagram generate -> seed flow', function () {
    Http::fake([
        'api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'display_name' => 'Jane Doe',
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                // Deliberately no email/phone: that is the real Instagram shape,
                // and it is what left the second pass with blanks to chase.
                'about' => 'Hair by Jane in Melbourne.',
                'email' => null,
                'phone' => null,
                'mentions' => [],
            ])]]],
        ]),
        '*' => Http::response([], 200),
    ]);

    $profile = ['username' => 'janedoe', 'fullName' => 'Jane Doe', 'biography' => 'Hair by Jane in Melbourne'];

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfileResult')->once()->andReturn(ProfileFetchResult::ok($profile));
    app()->instance(InstagramScraper::class, $scraper);

    // The seeder is mocked (its R2/auto-sync internals are covered elsewhere) but
    // it FORWARDS into the real InstagramIdentitySync, which is the second caller
    // this test exists to catch. Mocking that away would make the test vacuous.
    $seeder = Mockery::mock(InstagramConnectionSeeder::class);
    $seeder->shouldReceive('seed')->once()->andReturnUsing(
        function ($connection, $username, $userId, $profile, $autoConnectBooking = false, $intel = null) {
            app(InstagramIdentitySync::class)->applyIdentity(User::find($userId), $profile, $intel);

            return $profile;
        }
    );
    app()->instance(InstagramConnectionSeeder::class, $seeder);

    Queue::fake();

    $user = User::factory()->create([
        'status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null,
        'display_name' => 'janedoe', 'first_name' => 'janedoe',
        'bio' => null, 'public_contact_email' => null, 'public_contact_number' => null,
    ]);
    $site = Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    app(InstagramSourceGenerator::class)->generate($user, $site, 'janedoe');

    expect(igModelCalls())->toHaveCount(1);
});

it('still fills an empty About from the threaded result, without analysing again', function () {
    Http::fake([
        'api.deepseek.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'about' => 'Hair by Jane in Melbourne.',
                'email' => null, 'phone' => null, 'mentions' => [],
            ])]]],
        ]),
        '*' => Http::response([], 200),
    ]);

    $profile = ['username' => 'janedoe', 'fullName' => 'Jane Doe', 'biography' => 'Hair by Jane in Melbourne'];

    $user = User::factory()->create([
        'status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null,
        'bio' => null, 'public_contact_email' => null, 'public_contact_number' => null,
    ]);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);

    // Straight at the second caller: handed a result, it must use it and not
    // reach for the model itself.
    $intel = app(ProfileEnricher::class)->enrich(
        $user,
        new BioSource('janedoe', 'Jane Doe', 'Hair by Jane in Melbourne'),
    );
    expect(igModelCalls())->toHaveCount(1);

    $user->bio = null;
    $user->saveQuietly();

    app(InstagramIdentitySync::class)->applyIdentity($user->fresh(), $profile, $intel);

    expect(igModelCalls())->toHaveCount(1)
        ->and($user->fresh()->bio)->toBe('Hair by Jane in Melbourne.');
});
