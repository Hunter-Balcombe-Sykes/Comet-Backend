<?php

// #W2-LIFE-16. "Change to" (BuildsAutoSyncFindings::runApply) removed the
// user's existing connection and THEN wrote the replacement, with nothing
// between them. Any throw from write() — a constraint, an observer, a dropped
// connection — left the slot empty and the swap half-done, and the XOR lock the
// booking/reservations arms take does not help: it stops a CONCURRENT writer
// observing the gap, not the gap itself.
//
// These pin (1) a failed write leaves the ORIGINAL connection in place, on both
// the locked and the unlocked arm; (2) the successful swap still works; and
// (3) the handled-hook branch — GB re-dispatching the Instagram scrape — whose
// removals are also one half of a pair, the other half being the scrape. That
// half is NOT made whole by a transaction (the hook dispatches
// InstagramConnectJob and `config/queue.php` sets `after_commit => false`, so
// on redis the job is pushed before the commit and a worker can beat the
// placeholder row into existence). It is made whole by ORDER instead: the hook
// runs the removals inside the platform seed lock, only once its dispatch can
// no longer decline, and a declined dispatch propagates false so the caller
// leaves the finding unsettled (#W2-LIFE-16, review round 2).

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\InstagramAutoSync;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    Http::fake();
});

function atomUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function atomConnection(User $user, string $platform, string $resourceId): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => $resourceId,
        'payload' => ['name' => 'The incumbent'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

/** Make the WRITE half of the swap fail, leaving the delete half already done. */
function breakTheWrite(): void
{
    IntegrationConnection::saving(function (IntegrationConnection $c) {
        if ($c->resource_id === 'the-replacement') {
            throw new RuntimeException('the write half failed');
        }
    });
}

afterEach(function () {
    IntegrationConnection::flushEventListeners();
});

it('leaves the incumbent connection in place when the replacement write throws (social / unlocked arm)', function () {
    $user = atomUser('atomsoc');
    atomConnection($user, 'instagram', 'instagram');
    breakTheWrite();

    $finding = [
        'category' => 'social',
        'apply' => [
            'remove' => ['instagram'],
            'write' => ['platform' => 'instagram', 'resourceId' => 'the-replacement', 'payload' => ['name' => 'New']],
        ],
    ];

    expect(fn () => app(InstagramAutoSync::class)->applyFinding((string) $user->id, $finding))
        ->toThrow(RuntimeException::class, 'the write half failed');

    // The whole point: the user still has the link they had. Before the fix the
    // delete had already committed and this row was gone (soft-deleted), with
    // nothing written in its place.
    $rows = IntegrationConnection::query()->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->resource_id)->toBe('instagram');
    expect($rows->first()->deleted_at)->toBeNull();
    // And no half-written replacement leaked through either.
    expect(IntegrationConnection::withTrashed()->where('resource_id', 'the-replacement')->count())->toBe(0);
});

it('leaves the incumbent connection in place when the replacement write throws (booking / XOR-locked arm)', function () {
    $user = atomUser('atombook');
    atomConnection($user, 'fresha', 'doc-cuts');
    breakTheWrite();

    // Same recipe shape the booking arm takes — the lock is held around this,
    // and the lock is NOT what makes it atomic.
    $finding = [
        'category' => 'booking',
        'apply' => [
            'remove' => ['fresha'],
            'write' => ['platform' => 'square', 'resourceId' => 'the-replacement', 'payload' => ['name' => 'New']],
        ],
    ];

    expect(fn () => app(GoogleBusinessAutoSync::class)->applyFinding((string) $user->id, $finding))
        ->toThrow(RuntimeException::class, 'the write half failed');

    $rows = IntegrationConnection::query()->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->platform)->toBe('fresha');
});

it('still performs the swap when the write succeeds', function () {
    $user = atomUser('atomok');
    atomConnection($user, 'instagram', 'instagram');

    $finding = [
        'category' => 'social',
        'apply' => [
            'remove' => ['instagram'],
            'write' => ['platform' => 'instagram', 'resourceId' => 'newhandle', 'payload' => ['name' => 'New']],
        ],
    ];

    expect(app(InstagramAutoSync::class)->applyFinding((string) $user->id, $finding))->toBeTrue();

    $rows = IntegrationConnection::query()->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->resource_id)->toBe('newhandle');
});

// ── the handled-hook branch: dispatchInstagram()'s three routine declines ────
// Each of them returns BEFORE the hook has removed anything, so the correct
// outcome is "nothing changed, finding not settled" — not "connection deleted,
// finding marked seeded", which is what discarding the bool used to produce.

it('does not remove the incumbent Instagram connection when the apply cannot dispatch — no Apify token', function () {
    $user = atomUser('atomhook');
    atomConnection($user, 'instagram', 'instagram');
    Bus::fake([InstagramConnectJob::class]);

    $finding = [
        'category' => 'social',
        'apply' => ['remove' => ['instagram'], 'instagram' => ['username' => 'doccuts']],
    ];

    config()->set('services.apify.token', null);

    // False, so SuggestionsController::acceptPayloadFinding answers 423 and
    // never settles the finding as 'seeded' — the user can apply it again.
    expect(app(GoogleBusinessAutoSync::class)->applyFinding((string) $user->id, $finding))->toBeFalse();

    Bus::assertNotDispatched(InstagramConnectJob::class);

    // And the link they already had is untouched: not soft-deleted, not
    // replaced by a pending placeholder.
    $rows = IntegrationConnection::query()->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->resource_id)->toBe('instagram');
    expect($rows->first()->payload['name'])->toBe('The incumbent');
    expect(IntegrationConnection::withTrashed()->where('user_id', $user->id)->whereNotNull('deleted_at')->count())->toBe(0);
});

it('does not remove the incumbent Instagram connection when the daily Apify budget denies the claim', function () {
    $user = atomUser('atombudget');
    atomConnection($user, 'instagram', 'instagram');
    Bus::fake([InstagramConnectJob::class]);

    // Budget denial is a NORMAL operating state, not an error — a cap of 0
    // makes ApifyBudget::tryClaim('instagram') refuse without touching the
    // money path itself.
    config()->set('services.apify.token', 'apify-token');
    config()->set('partna.limits.apify.actors.instagram', 0);

    $finding = [
        'category' => 'social',
        'apply' => ['remove' => ['instagram'], 'instagram' => ['username' => 'doccuts']],
    ];

    expect(app(GoogleBusinessAutoSync::class)->applyFinding((string) $user->id, $finding))->toBeFalse();

    Bus::assertNotDispatched(InstagramConnectJob::class);

    $rows = IntegrationConnection::query()->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->resource_id)->toBe('instagram');
    expect($rows->first()->payload['name'])->toBe('The incumbent');
    expect(IntegrationConnection::withTrashed()->where('user_id', $user->id)->whereNotNull('deleted_at')->count())->toBe(0);
});

it('removes the incumbent and leaves a pending placeholder when the scrape IS dispatched', function () {
    $user = atomUser('atomhookok');
    atomConnection($user, 'instagram', 'instagram');
    Bus::fake([InstagramConnectJob::class]);

    config()->set('services.apify.token', 'apify-token');

    $finding = [
        'category' => 'social',
        'apply' => ['remove' => ['instagram'], 'instagram' => ['username' => 'doccuts']],
    ];

    expect(app(GoogleBusinessAutoSync::class)->applyFinding((string) $user->id, $finding))->toBeTrue();

    Bus::assertDispatched(InstagramConnectJob::class, fn ($job) => $job->username === 'doccuts');

    // The incumbent is gone and something stands in its place — the pair the
    // decline path above refuses to half-perform.
    $rows = IntegrationConnection::query()->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->last_refresh_status)->toBe('pending');
    expect($rows->first()->payload['source'])->toBe('google-business');
    expect(IntegrationConnection::withTrashed()->where('user_id', $user->id)->whereNotNull('deleted_at')->count())->toBe(1);
});

it('canaries a social recipe that carries BOTH instagram and write — the hook claims it and the write is silently dropped', function () {
    Exceptions::fake();
    $user = atomUser('atomhybrid');
    Bus::fake([InstagramConnectJob::class]);
    config()->set('services.apify.token', 'apify-token');

    // applyFinding()'s own canary only fires for booking/reservations-slot
    // recipes; a `social` one reaches runApply() unremarked, and its `write`
    // half has always been swallowed by the claiming hook.
    $finding = [
        'category' => 'social',
        'apply' => [
            'remove' => ['instagram'],
            'instagram' => ['username' => 'doccuts'],
            'write' => ['platform' => 'square', 'resourceId' => 'sq', 'payload' => ['name' => 'New']],
        ],
    ];

    expect(app(GoogleBusinessAutoSync::class)->applyFinding((string) $user->id, $finding))->toBeTrue();

    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'BOTH `instagram` and `write`'));
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'square')->exists())->toBeFalse();
});
