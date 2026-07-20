<?php

// tests/Unit/Jobs/EnrichLinkCardJobTest.php

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function enrichUser(): User
{
    return User::create([
        'handle' => 'en', 'handle_lc' => 'en', 'display_name' => 'En',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'en@example.com',
    ]);
}

it('has the required queue-hygiene properties and unique id', function () {
    $job = new EnrichLinkCardJob('u', 'custom', 'link-abc', 'https://x.com');
    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 120, 300])
        ->and($job->timeout)->toBe(60)
        ->and($job->uniqueId())->toBe('custom:link-abc');
});

it('upgrades display fields from the snapshot and marks the row ok', function () {
    $user = enrichUser();
    $row = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'custom', 'resource_id' => 'link-abc',
        'payload' => ['kind' => 'link', 'url' => 'https://x.com', 'name' => 'x.com', 'favicon' => 'g', 'logo' => null, 'description' => null],
        'last_refresh_status' => 'pending',
    ]);

    $this->mock(LinkCardScraper::class, function ($m) {
        $m->shouldReceive('snapshot')->andReturn([
            'url' => 'https://x.com/final', 'name' => 'The Real Title', 'description' => 'desc',
            'favicon' => 'https://x.com/fav.ico', 'logo' => 'https://x.com/og.png',
        ]);
    });

    (new EnrichLinkCardJob($user->id, 'custom', 'link-abc', 'https://x.com'))->handle(app(LinkCardScraper::class));

    $row->refresh();
    expect($row->last_refresh_status)->toBe('ok')
        ->and($row->payload['name'])->toBe('The Real Title')
        ->and($row->payload['logo'])->toBe('https://x.com/og.png')
        ->and($row->payload['url'])->toBe('https://x.com')   // URL preserved — dedup keys intact
        ->and($row->payload['kind'])->toBe('link');           // controller field preserved
});

it('leaves the minimal card and marks ok when the snapshot fails', function () {
    $user = enrichUser();
    $row = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'custom', 'resource_id' => 'link-abc',
        'payload' => ['kind' => 'link', 'url' => 'https://x.com', 'name' => 'x.com', 'favicon' => 'g', 'logo' => null, 'description' => null],
        'last_refresh_status' => 'pending',
    ]);

    $this->mock(LinkCardScraper::class, fn ($m) => $m->shouldReceive('snapshot')->andReturnNull());

    (new EnrichLinkCardJob($user->id, 'custom', 'link-abc', 'https://x.com'))->handle(app(LinkCardScraper::class));

    $row->refresh();
    expect($row->last_refresh_status)->toBe('ok')       // minimal card is an acceptable final state
        ->and($row->payload['name'])->toBe('x.com');
});

it('no-ops when the row is gone', function () {
    $user = enrichUser();
    $this->mock(LinkCardScraper::class, fn ($m) => $m->shouldReceive('snapshot')->never());

    (new EnrichLinkCardJob($user->id, 'custom', 'missing', 'https://x.com'))->handle(app(LinkCardScraper::class));
})->throwsNoExceptions();
