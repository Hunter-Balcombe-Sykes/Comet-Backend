<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessAutoSync;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
    Queue::fake();
});

/** The shape GoogleBusinessApifyScraper::map() produces for a booking link. */
function gbEnrichmentWithFresha(string $url): array
{
    return ['booking' => [$url]];
}

$shareUrl = 'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260';

it('auto-connects a fresha link found on a google listing when the origin is marked', function () use ($shareUrl) {
    // The case Josh asked for: staff put in a Google Business source, the
    // listing carries a Fresha booking link, and nobody is present to pick.
    $user = User::factory()->create(['account_type' => 'partna']);

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id, gbEnrichmentWithFresha($shareUrl), 'Anseo Studio', null, true,
    );

    Queue::assertPushed(ConnectFetchJob::class, fn (ConnectFetchJob $job): bool => $job->platform === 'fresha' && $job->systemInitiated === true);
});

it('does NOT auto-connect a google-listing fresha link for an unmarked origin', function () use ($shareUrl) {
    // Dashboard Google Business connect / public site-first signup — a picker is coming.
    $user = User::factory()->create(['account_type' => 'partna']);

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id, gbEnrichmentWithFresha($shareUrl), 'Anseo Studio', null, false,
    );

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('canonicalises the google-listing fresha url so the employee leg is possible', function () use ($shareUrl) {
    // Stored raw, slugFromUrl() returns null and every match degrades to
    // storewide — the feature would look wired up and always pick the store.
    $user = User::factory()->create(['account_type' => 'partna']);

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id, gbEnrichmentWithFresha($shareUrl), 'Anseo Studio', null, true,
    );

    $payload = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->payload;

    expect($payload['url'])->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna')
        ->and($payload['source'])->toBe('google-business')
        ->and($payload['connectMode'])->toBe('auto');
});

it('does NOT auto-connect when the booking slot already conflicts', function () use ($shareUrl) {
    // A conflict finding writes nothing, so there is no row to fetch for.
    $user = User::factory()->create(['account_type' => 'partna']);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'square', 'resource_id' => 'square',
        'payload' => ['url' => 'https://squareup.com/appointments/x'], 'is_active' => true,
    ]);

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id, gbEnrichmentWithFresha($shareUrl), 'Anseo Studio', null, true,
    );

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('does NOT auto-connect a google-listing fresha link when the kill switch is off', function () use ($shareUrl) {
    config()->set('partna.connect.auto_booking.enabled', false);
    $user = User::factory()->create(['account_type' => 'partna']);

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id, gbEnrichmentWithFresha($shareUrl), 'Anseo Studio', null, true,
    );

    Queue::assertNotPushed(ConnectFetchJob::class);
});
