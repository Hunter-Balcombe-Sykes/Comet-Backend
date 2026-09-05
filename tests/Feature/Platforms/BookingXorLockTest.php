<?php

// LIFE-105 / LIFE-106: GoogleBusinessAutoSync::seedBooking and
// InstagramAutoSync::seed's booking branch both take a shared cross-platform
// lock (BuildsAutoSyncFindings::withBookingXorLock) before touching a booking
// slot (fresha/square/booking), because "at most one live booking connection
// per user" spans three platforms and can't be enforced by a per-platform
// lock or the per-platform DB unique index. See the trait's docblock for the
// full argument.
//
// These tests prove: (1) the lock is actually HELD across the check-then-write
// span, not just acquired-and-released around one half of it; (2) GB and IG
// share the exact same lock key (proving one lock, not two independent ones);
// (3) contention causes a skip, not a double-write; (4) the SLOP-101 trait
// extraction didn't change applyFinding()'s GB-vs-IG instagram-hook behaviour.

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\InstagramAutoSync;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // 2026-09-06: every booking brand now routes through LinkRoutingService/
    // SourceReconciler (routeBooking()), which reads/writes routing.source_intents.
    setupRoutingTables();
    // A Fresha bio link now auto-dispatches ConnectFetchJob, and QUEUE_CONNECTION
    // =sync runs it INLINE — without this the seed below scrapes fresha.com for real.
    Http::fake();
});

/** partna account_type — can_use_booking is universal (2026-07-15 sector gating never restricts partna), google_business_full_sync is false so GB::seed() runs ONLY seedBooking (no reservation/ordering/socials queries to muddy the query-listen probes below). */
function bxUser(string $h): User
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

/** A GB enrichment carrying only a Fresha booking link — the shape resolveBookingWrite() reads. */
function bxEnrichment(): array
{
    return ['booking' => ['https://www.fresha.com/a/doc-cuts']];
}

it('holds the booking-XOR lock across BOTH the has()-check and the route during GoogleBusinessAutoSync::seedBooking', function () {
    $user = bxUser('bxgb1');
    $key = "platforms:booking-xor:lock:{$user->id}"; // hard-coded, not read from the trait

    // Probe A: fires on the has()-then-route check span (the group-conflict
    // exists() queries in seedBooking). If the outer lock is held, THIS probe
    // lock must fail to acquire.
    $checkLocked = false;
    DB::listen(function ($query) use (&$checkLocked, $key) {
        if (! str_contains($query->sql, 'select exists(') || ! str_contains($query->sql, 'platform_connections')) {
            return;
        }
        $probe = Cache::lock($key, 5);
        $heldNow = ! $probe->get();
        if (! $heldNow) {
            $probe->release(); // MANDATORY — an ArrayLock left held would deadlock the write this same test observes.
        }
        $checkLocked = $checkLocked || $heldNow;
    });

    // Probe B (2026-09-06: every booking brand now routes through the
    // suggestion pipeline, so a discovery never creates a live connection —
    // it proposes a routing.source_intents row instead. Fires on THAT insert,
    // the write half's new shape).
    $writeLocked = false;
    DB::listen(function ($query) use (&$writeLocked, $key) {
        if (! str_starts_with($query->sql, 'insert or ignore into "routing"."source_intents"')) {
            return;
        }
        $probe = Cache::lock($key, 5);
        $heldNow = ! $probe->get();
        if (! $heldNow) {
            $probe->release();
        }
        $writeLocked = $writeLocked || $heldNow;
    });

    app(GoogleBusinessAutoSync::class)->seed((string) $user->id, bxEnrichment(), 'Doc Cuts');

    expect($checkLocked)->toBeTrue();
    expect($writeLocked)->toBeTrue();

    // Data assertion blocks a degenerate "acquire a lock then skip the seed":
    // a proposed (band:auto, pre-ticked) intent, no live connection — the
    // 2026-09-05/06 "a harvest never auto-connects a booking platform" policy.
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
    $intent = DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'fresha.book')->firstOrFail();
    expect($intent->state)->toBe('proposed');
    expect($intent->band)->toBe('auto');
});

it('InstagramAutoSync::seed takes the SAME booking-XOR lock key as GoogleBusinessAutoSync — proving one shared lock, not two independent ones', function () {
    // Booking changed shape 2026-09-05 (owner policy: a passive harvest never
    // auto-connects booking, only ever suggests) — IG's DEFAULT seed() no
    // longer writes a connection directly for booking, so the direct-write
    // race this lock guards against can't happen on that path any more.
    // The lock still matters for the one lane exempted from the policy: a
    // staff/ManyChat-assisted build (autoConnectBooking:true), which is what
    // this test now exercises to keep proving the shared key.
    $user = bxUser('bxig1');
    // Hard-coded string (not CacheKeyGenerator, not the trait) — the whole
    // point of this assertion is that both services derive the identical key.
    $key = "platforms:booking-xor:lock:{$user->id}";

    $writeLocked = false;
    IntegrationConnection::created(function (IntegrationConnection $conn) use (&$writeLocked, $key) {
        if ($conn->platform !== 'fresha') {
            return;
        }
        $probe = Cache::lock($key, 5);
        $writeLocked = ! $probe->get();
        if (! $writeLocked) {
            $probe->release();
        }
    });

    app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.fresha.com/a/doc-cuts'], autoConnectBooking: true);

    expect($writeLocked)->toBeTrue();
    $fresha = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail();
    expect($fresha->payload['source'])->toBe('instagram');
});

it('a concurrent holder of the booking-XOR lock makes GoogleBusinessAutoSync::seedBooking skip (not double-write) and log a timeout warning', function () {
    Log::spy();
    $user = bxUser('bxcontend');
    $key = "platforms:booking-xor:lock:{$user->id}";

    // Pre-acquire and DELIBERATELY hold — simulates a concurrent seed already
    // mid check-then-write for this user (e.g. InstagramAutoSync racing
    // GoogleBusinessAutoSync on the same connect).
    $held = Cache::lock($key, 10);
    expect($held->get())->toBeTrue();

    try {
        // Blocks up to BOOKING_LOCK_BLOCK (3s) before giving up — this test is
        // slow by design, not flaky; do not "fix" it by shortening the wait.
        app(GoogleBusinessAutoSync::class)->seed((string) $user->id, bxEnrichment(), 'Doc Cuts');
    } finally {
        $held->release();
    }

    // Today the row is created unconditionally (no lock, no contention path).
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.auto_sync.booking_lock_timeout'
            && ($context['user_id'] ?? null) === (string) $user->id);
});

// ── SLOP-101 regression guard: the trait's applyFindingHandled() hook must
// still discriminate GB (claims `apply.instagram`, never writes) from IG (has
// no override, always falls through to `apply.write`) exactly as each class's
// pre-extraction applyFinding() did. Both halves PASS today by design — this
// is a guard against a future edit to the trait accidentally merging the two
// behaviours, not a bug being fixed now. ─────────────────────────────────────

it('GoogleBusinessAutoSync::applyFinding claims an apply.instagram recipe via the hook — dispatches the scrape and does NOT run the generic write', function () {
    // #FU-8: this finding is hybrid (carries BOTH `instagram` and `write`),
    // which trips BuildsAutoSyncFindings::runApply()'s canary report() call
    // (see its docblock) — fake Exceptions so that canary reports through the
    // test double instead of the real handler on every run.
    Exceptions::fake();
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    $user = bxUser('bxslop1');

    $finding = [
        'platform' => 'instagram', 'resourceId' => 'instagram', 'category' => 'social', 'label' => 'Instagram',
        'foundUrl' => 'https://instagram.com/docpizza', 'outcome' => 'conflict',
        'apply' => [
            'remove' => ['instagram'],
            'instagram' => ['username' => 'docpizza'],
            // Same-shaped finding ALSO carries a `write` recipe — GB must not run it.
            'write' => ['platform' => 'square', 'resourceId' => 'square', 'payload' => ['url' => 'https://acme.square.site', 'source' => 'google-business']],
        ],
    ];

    app(GoogleBusinessAutoSync::class)->applyFinding((string) $user->id, $finding);

    Bus::assertDispatched(InstagramConnectJob::class, fn ($job) => $job->username === 'docpizza' && $job->userId === (string) $user->id);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'square')->exists())->toBeFalse();
});

it('InstagramAutoSync::applyFinding has no instagram hook — the SAME-shaped apply recipe falls through to the generic write', function () {
    Bus::fake([InstagramConnectJob::class]);
    $user = bxUser('bxslop2');

    $finding = [
        'platform' => 'instagram', 'resourceId' => 'instagram', 'category' => 'social', 'label' => 'Instagram',
        'foundUrl' => 'https://instagram.com/docpizza', 'outcome' => 'conflict',
        'apply' => [
            'remove' => ['instagram'],
            'instagram' => ['username' => 'docpizza'], // IG has no applyFindingHandled override — this key is inert.
            'write' => ['platform' => 'square', 'resourceId' => 'square', 'payload' => ['url' => 'https://acme.square.site', 'source' => 'google-business']],
        ],
    ];

    app(InstagramAutoSync::class)->applyFinding((string) $user->id, $finding);

    Bus::assertNotDispatched(InstagramConnectJob::class);
    $square = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'square')->firstOrFail();
    expect($square->payload['url'])->toBe('https://acme.square.site');
});
