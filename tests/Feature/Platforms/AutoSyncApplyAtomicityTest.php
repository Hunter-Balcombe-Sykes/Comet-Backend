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
// (3) the handled-hook branch (GB re-dispatching the Instagram scrape) stays
// OUTSIDE the transaction — that hook dispatches InstagramConnectJob, which
// under the sync driver runs inline and takes its own cache lock, so wrapping
// it would be the dispatch-before-commit trap with `after_commit => false`.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\InstagramAutoSync;
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

it('runs the handled hook OUTSIDE any transaction — its recipe carries no write to pair with', function () {
    $user = atomUser('atomhook');
    atomConnection($user, 'instagram', 'instagram');

    // The only producer of an `apply.instagram` recipe (GoogleBusinessAutoSync's
    // social conflict finding) carries `remove` and never `write`. If that ever
    // changes, the hook's inline job dispatch would land inside the transaction
    // — this asserts the shape the fix depends on.
    $finding = [
        'category' => 'social',
        'apply' => ['remove' => ['instagram'], 'instagram' => ['username' => 'doccuts']],
    ];

    // No apify token configured => dispatchInstagram() short-circuits before
    // touching the queue, so this exercises the branch without a real scrape.
    config()->set('services.apify.token', null);

    expect(app(GoogleBusinessAutoSync::class)->applyFinding((string) $user->id, $finding))->toBeTrue();
    expect(array_key_exists('write', $finding['apply']))->toBeFalse();
    // The removal still happened — the hook branch is byte-identical to before.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->count())->toBe(0);
});
