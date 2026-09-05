<?php

use App\Jobs\Platforms\LinkInBioScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramAutoSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// BE2: mirrors GoogleBusinessAutoSync::seed()'s shape (only-if-empty seeding,
// conflict findings carrying an `apply` swap recipe, findings returned for the
// caller to persist as syncFindings) but for Instagram bio links, classified
// via WebsiteLinkHarvester::classify() rather than a Google Apify enrichment.
//
// Booking changed shape on 2026-09-05 (owner policy: a harvest never auto-adds
// a platform, only ever suggests one). A fresha/square bio link is no longer a
// finding or a connection — it is a proposed routing.source_intents row, the
// same Engine-1 bridge every other harvested surface takes. Only a staff/
// ManyChat build ($autoConnectBooking) keeps the immediate connect, and that
// shape is pinned in BookingConflictSlotTest, not here.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    // A Fresha bio link now auto-dispatches ConnectFetchJob, and QUEUE_CONNECTION
    // =sync runs it INLINE — without this the seed below scrapes fresha.com for real.
    Http::fake();
});

// Defaults to a Business Partna because the social-seed assertions below cover
// the capability-gated path — social auto-sync requires google_business_full_sync,
// the SAME capability GB's socials tier gates on (mirrors gbApifyUser's default).
// Pass 'partna' to exercise the standard-account fall-through: social links
// route to `unmatched` (offered as custom links); booking is proposed either way.
function igAutoSyncUser(string $h, string $accountType = 'business'): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => $accountType,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** The booking intents a harvest proposed for $user, oldest first. */
function igBookingIntents(User $user): Collection
{
    return DB::table('routing.source_intents')
        ->where('user_id', $user->id)->where('routing_class', 'booking')
        ->orderBy('first_seen_at')->get();
}

// ── social: seed only-if-empty ────────────────────────────────────────────────

it('seeds a facebook, tiktok, x and linkedin connection from bio links when none exist', function () {
    $user = igAutoSyncUser('igas1');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.facebook.com/docpizzabar',
        'https://www.tiktok.com/@docpizza',
        'https://x.com/docpizza',
        'https://www.linkedin.com/in/docpizza',
    ]);

    expect($result['unmatched'])->toBe([]);
    expect(collect($result['findings'])->pluck('outcome')->unique()->all())->toBe(['seeded']);
    expect(collect($result['findings'])->pluck('platform')->all())->toBe(['facebook', 'tiktok', 'x', 'linkedin']);
    expect(collect($result['findings'])->pluck('category')->unique()->all())->toBe(['social']);

    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['url'])->toBe('https://www.facebook.com/docpizzabar');
    expect($fb['username'])->toBe('docpizzabar');
    expect($fb['source'])->toBe('instagram');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'tiktok')->exists())->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'x')->exists())->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'linkedin')->exists())->toBeTrue();
});

it('seeds a legacy /pages/ Facebook bio link with the extracted Page name, not "pages" (G4-4)', function () {
    // Regression: this service used to run its OWN standalone regex (a
    // byte-for-byte copy of GoogleBusinessAutoSync's, sharing its bug) with no
    // concept of reserved path segments — a bio link to a legacy Facebook Page
    // stored "pages" itself as the username. Now delegates to FacebookNormalizer.
    $user = igAutoSyncUser('igasfbpages');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.facebook.com/pages/DOC-Pizza-Carlton/12345',
    ]);

    expect(collect($result['findings'])->pluck('outcome')->all())->toBe(['seeded']);
    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['username'])->toBe('DOC-Pizza-Carlton');
    expect($fb['url'])->toBe('https://www.facebook.com/pages/DOC-Pizza-Carlton/12345');
});

it('never overwrites a social connection the user already set with the same url (only-if-empty is silent, not a finding)', function () {
    $user = igAutoSyncUser('igas2');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.facebook.com/docpizzabar']);

    expect($result['findings'])->toBe([]);
    expect($result['unmatched'])->toBe([]);
    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['source'])->toBe('manual'); // untouched
});

it('marks conflict when a social platform exists with a DIFFERENT url, leaving the existing row untouched', function () {
    $user = igAutoSyncUser('igas3');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.facebook.com/docpizzabar']);

    expect($result['findings'])->toHaveCount(1);
    expect($result['findings'][0]['platform'])->toBe('facebook');
    expect($result['findings'][0]['category'])->toBe('social');
    expect($result['findings'][0]['outcome'])->toBe('conflict');
    expect($result['findings'][0]['foundUrl'])->toBe('https://www.facebook.com/docpizzabar');
    expect($result['findings'][0]['apply']['remove'])->toBe(['facebook']);
    expect($result['findings'][0]['apply']['write']['payload']['url'])->toBe('https://www.facebook.com/docpizzabar');

    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['source'])->toBe('manual'); // untouched — conflict does not overwrite
    expect($fb['url'])->toBe('https://facebook.com/mine');
});

// ── booking: fresha / square ───────────────────────────────────────────────────

it('proposes BOTH booking platforms from a bio listing fresha and square — the first pre-ticked, the second unticked, never a live row (2026-09-05)', function () {
    $user = igAutoSyncUser('igas4');

    // A bio listing both is the exact scenario the booking XOR guards against:
    // one booking slot. Before 2026-09-05 the first-processed link (fresha)
    // connected on the spot and square filed a conflict Swap. A harvest now
    // proposes both, and the reconciler pre-ticks only ONE candidate of an
    // exclusive class, so a Continue can never try to accept two into it.
    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.fresha.com/a/doc-cuts',
        'https://acme.square.site',
    ]);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->whereIn('platform', ['fresha', 'square'])->exists())->toBeFalse();
    expect($result['findings'])->toBe([]);
    expect($result['unmatched'])->toBe([]);

    $bands = igBookingIntents($user)->pluck('band', 'surface_key');
    expect($bands)->toHaveCount(2)
        ->and($bands['fresha.book'])->toBe('auto')
        ->and($bands->except('fresha.book')->first())->toBe('suggest');
});

it('proposes, never overwrites, when the SAME booking platform exists with a different url', function () {
    $user = igAutoSyncUser('igas5');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/mine', 'selection' => null, 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.fresha.com/a/doc-cuts']);

    // No finding, no card: the link is carried by an intent — and unticked,
    // because the slot already holds a live connection.
    expect($result['findings'])->toBe([]);
    expect($result['unmatched'])->toBe([]);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->count())->toBe(1);
    $fresha = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->payload;
    expect($fresha['url'])->toBe('https://www.fresha.com/a/mine'); // untouched

    $intent = igBookingIntents($user)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->surface_key)->toBe('fresha.book')
        ->and($intent->band)->toBe('suggest');
});

// ── booking XOR: Fresha and Square share one slot (FreshaController /
// SquareController::hasConflictingConnection() both 409 the other way). A
// harvest never contends for it any more — it proposes, unticked. ──────────

it('never writes a second live booking provider — an existing Square connection leaves a Fresha bio link an unticked suggestion', function () {
    $user = igAutoSyncUser('igas14');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'square', 'resource_id' => 'square',
        'payload' => ['url' => 'https://acme.square.site', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.fresha.com/a/doc-cuts']);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'square')->exists())->toBeTrue();
    expect($result['findings'])->toBe([]);
    expect($result['unmatched'])->toBe([]);

    $intent = igBookingIntents($user)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->surface_key)->toBe('fresha.book')
        ->and($intent->band)->toBe('suggest');

    $square = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'square')->firstOrFail()->payload;
    expect($square['url'])->toBe('https://acme.square.site'); // untouched
});

it('proposes a fresha booking link as a pre-ticked suggestion when no booking provider is connected yet', function () {
    $user = igAutoSyncUser('igas15');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.fresha.com/a/doc-cuts']);

    expect($result['findings'])->toBe([]);
    expect($result['unmatched'])->toBe([]);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();

    $intent = igBookingIntents($user)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->surface_key)->toBe('fresha.book')
        ->and($intent->state)->toBe('proposed')
        ->and($intent->band)->toBe('auto')
        ->and($intent->canonical_url)->toBe('https://www.fresha.com/a/doc-cuts');
});

it('routes a booking bio link to unmatched for a FOOD-sector business (can_use_booking false) — no connection written', function () {
    $user = igAutoSyncUser('igasfood');
    $user->forceFill(['sector' => 'restaurant', 'sector_source' => 'manual'])->save();

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.fresha.com/a/doc-cuts']);

    expect($result['findings'])->toBeEmpty();
    expect($result['unmatched'])->toHaveCount(1);
    expect($result['unmatched'][0]['url'])->toBe('https://www.fresha.com/a/doc-cuts');
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('still routes booking for a NON-food business (barbershop keeps can_use_booking) — as a suggestion, not a card', function () {
    $user = igAutoSyncUser('igasbarber');
    $user->forceFill(['sector' => 'barber', 'sector_source' => 'manual'])->save();

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.fresha.com/a/doc-cuts']);

    // The gate let it through (contrast the food case above, where it lands
    // in unmatched); the proposed intent is what carries it.
    expect($result['unmatched'])->toBe([]);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
    expect(igBookingIntents($user))->toHaveCount(1);
});

// ── unmatched: unclassified + classified-but-not-actionable ──────────────────

it('routes a genuinely unclassified link to unmatched with a host-derived label', function () {
    $user = igAutoSyncUser('igas6');

    // NOT one of the 4 curated link-in-bio hosts (that's its own path — see
    // "detects and dispatches a scan" below) — a plain, genuinely unclassified link.
    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://someblog.example/docpizza']);

    expect($result['findings'])->toBe([]);
    expect($result['unmatched'])->toBe([['url' => 'https://someblog.example/docpizza', 'label' => 'someblog.example']]);
});

// ── A3.3/A3.4: curated link-in-bio hosts are detected and dispatched, not classified ──

it('detects a curated link-in-bio host and dispatches a scan instead of routing it to unmatched', function () {
    Queue::fake();
    $user = igAutoSyncUser('igas6b');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://linktr.ee/docpizza']);

    expect($result['findings'])->toBe([]);
    expect($result['unmatched'])->toBe([]); // not routed to unmatched — it's being scanned instead
    Queue::assertPushed(LinkInBioScanJob::class, fn ($job) => $job->userId === (string) $user->id && $job->bioPageUrl === 'https://linktr.ee/docpizza');
});

it('detects each of the 4 curated link-in-bio hosts from a bio link', function (string $url) {
    Queue::fake();
    $user = igAutoSyncUser('igas6c');

    app(InstagramAutoSync::class)->seed((string) $user->id, [$url]);

    Queue::assertPushed(LinkInBioScanJob::class, fn ($job) => $job->bioPageUrl === $url);
})->with([
    'https://linktr.ee/venue',
    'https://msha.ke/venue',
    'https://beacons.ai/venue',
    'https://stan.store/venue',
]);

// Rewritten 2026-07-25 (link classification consolidation). The old ACTIONABLE
// allowlist was 6 platforms wide, so YouTube — classified, registered, and
// perfectly seedable from a bare URL — fell to unmatched for everyone. That was
// the headline complaint this refactor set out to fix. OpenTable still lands as a
// custom link here, but for a DIFFERENT reason now: the reservations routing gate
// is business-food-only, and this is a business account with no sector.
it('seeds a YouTube bio link now that routing is not limited to 6 platforms, and gates OpenTable to a custom link', function () {
    $user = igAutoSyncUser('igas7');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.youtube.com/@docpizza',
        'https://www.opentable.com.au/r/doc-pizza',
    ]);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'youtube')->exists())->toBeTrue();
    expect(collect($result['findings'])->pluck('platform')->all())->toBe(['youtube']);

    // Reservations gate denied (not business-food) → still surfaced, never dropped.
    expect($result['unmatched'])->toBe([['url' => 'https://www.opentable.com.au/r/doc-pizza', 'label' => 'OpenTable']]);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'opentable')->exists())->toBeFalse();
});

// ── de-dupe / edge cases ───────────────────────────────────────────────────────

it('first bio link per platform wins when two links classify to the same platform', function () {
    $user = igAutoSyncUser('igas8');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.facebook.com/first',
        'https://www.facebook.com/second',
    ]);

    expect($result['findings'])->toHaveCount(1);
    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['url'])->toBe('https://www.facebook.com/first');
});

it('returns empty findings and unmatched for an empty bio-links list', function () {
    $user = igAutoSyncUser('igas9');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, []);

    expect($result)->toBe(['findings' => [], 'unmatched' => [], 'scans' => 0]);
});

it('skips malformed bio-link entries without throwing', function () {
    $user = igAutoSyncUser('igas10');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['', '   ', 123, null]);

    expect($result)->toBe(['findings' => [], 'unmatched' => [], 'scans' => 0]);
});

// ── RULING 1 is REPEALED (Decision 8, 2026-07-25) ────────────────────────────
// Social auto-sync used to be a Business-Partna convenience, gated on
// AccountCapabilities::google_business_full_sync — so a standard (partna)
// account's scraped socials fell to unmatched. LinkRouter's social gate is
// unconditional now: every account type gets them. This is a deliberate product
// decision, not a test accommodation.
//
// GoogleBusinessAutoSync::seedSocials KEEPS its own google_business_full_sync
// gate and is out of scope, so the two social paths now gate differently on
// purpose — Google Business socials stay business-only, Instagram and pasted
// socials go to everyone.

it('seeds classified social links for a standard partna account — RULING 1 repealed', function () {
    $user = igAutoSyncUser('igascap1', 'partna');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.facebook.com/docpizzabar',
        'https://www.tiktok.com/@docpizza',
    ]);

    expect($result['unmatched'])->toBe([]);
    expect(collect($result['findings'])->pluck('outcome')->unique()->all())->toBe(['seeded']);
    foreach (['facebook', 'tiktok'] as $p) {
        expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', $p)->exists())
            ->toBeTrue("expected a {$p} row for a partna account after Decision 8");
    }
});

it('seeds social and proposes booking for a partna account', function () {
    $user = igAutoSyncUser('igascap2', 'partna');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.fresha.com/a/doc-cuts',
        'https://www.facebook.com/docpizzabar',
    ]);

    expect($result['unmatched'])->toBe([]);
    expect(collect($result['findings'])->pluck('platform')->all())->toBe(['facebook']);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeTrue();
    expect(igBookingIntents($user))->toHaveCount(1);
});

// ── DISC-7: consent gate — unclaimed (provisional) subjects have not
// consented to auto-created platform connections from a scraped bio ─────────

it('DOES auto-create a social connection, and proposes booking, from a scraped bio for an UNCLAIMED subject (gate removed 2026-07-25)', function () {
    $user = igAutoSyncUser('discunclaimed1', 'business');
    $user->forceFill(['status' => 'unclaimed'])->save();

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.facebook.com/docpizzabar',
        'https://www.fresha.com/a/doc-cuts',
    ]);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
    expect(collect($result['findings'])->pluck('outcome')->unique()->all())->toBe(['seeded']);
    expect(igBookingIntents($user))->toHaveCount(1);
});

it('DOES auto-create a social connection, and proposes booking, for a CLAIMED subject with the same capabilities (gate is not a blanket disable)', function () {
    $user = igAutoSyncUser('discclaimed1', 'business');
    $user->forceFill(['status' => 'active'])->save();

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.facebook.com/docpizzabar',
        'https://www.fresha.com/a/doc-cuts',
    ]);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
    expect(collect($result['findings'])->pluck('outcome')->unique()->all())->toBe(['seeded']);
    expect(igBookingIntents($user))->toHaveCount(1);
});

it('seeds social links for a business account (google_business_full_sync present)', function () {
    $user = igAutoSyncUser('igascap3', 'business');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.facebook.com/docpizzabar']);

    expect($result['findings'])->toHaveCount(1);
    expect($result['findings'][0]['platform'])->toBe('facebook');
    expect($result['findings'][0]['outcome'])->toBe('seeded');
    expect($result['unmatched'])->toBe([]);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeTrue();
});

// ── soft-deleted connections: a tombstone must not be silently resurrected ────
// IntegrationConnection uses SoftDeletes, and forgetConnection() soft-deletes
// on an explicit user disconnect. The default Eloquent scope excludes trashed
// rows, so a naive "no live row" check can't tell "never connected" apart
// from "the user removed this on purpose" — respect the tombstone.

it('does not resurrect a soft-deleted connection the user explicitly disconnected — routes it to unmatched instead', function () {
    $user = igAutoSyncUser('igas13');
    $trashed = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'old', 'url' => 'https://facebook.com/old', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $trashed->delete();

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.facebook.com/docpizzabar']);

    expect($result['findings'])->toBe([]);
    expect($result['unmatched'])->toBe([['url' => 'https://www.facebook.com/docpizzabar', 'label' => 'Facebook']]);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeFalse();
    // Still just the one trashed row — no resurrection, no second write.
    expect(IntegrationConnection::withTrashed()->where('user_id', $user->id)->where('platform', 'facebook')->count())->toBe(1);
});

it('still seeds normally when the platform was never connected before (no trashed row)', function () {
    $user = igAutoSyncUser('igas13b');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.facebook.com/docpizzabar']);

    expect($result['unmatched'])->toBe([]);
    expect($result['findings'][0]['outcome'])->toBe('seeded');
    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['url'])->toBe('https://www.facebook.com/docpizzabar');
});

// ── seenPlatforms: a throw must not consume the platform slot ─────────────────

it('a throw on the first attempt does not consume the platform slot — a later same-platform link still seeds', function () {
    $user = igAutoSyncUser('igasthrow1');

    // Force the FIRST facebook write to blow up (transient DB/observer failure);
    // the per-link try/catch reports it. The platform slot must stay open so
    // the run's second facebook link still gets its attempt.
    $threw = false;
    IntegrationConnection::creating(function ($model) use (&$threw) {
        if ($model->platform === 'facebook' && ! $threw) {
            $threw = true;
            throw new RuntimeException('transient write failure');
        }
    });

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.facebook.com/first',
        'https://www.facebook.com/second',
    ]);

    expect($result['findings'])->toHaveCount(1);
    expect($result['findings'][0]['platform'])->toBe('facebook');
    expect($result['findings'][0]['outcome'])->toBe('seeded');
    expect($result['findings'][0]['foundUrl'])->toBe('https://www.facebook.com/second');
    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['url'])->toBe('https://www.facebook.com/second');
});

// ── applyFinding() — "Change to" swap, mirrors GoogleBusinessAutoSync::applyFinding ──

it('applyFinding removes the existing connection and writes the found one', function () {
    $user = igAutoSyncUser('igas11');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.facebook.com/docpizzabar']);
    $finding = $result['findings'][0];
    expect($finding['outcome'])->toBe('conflict');

    app(InstagramAutoSync::class)->applyFinding((string) $user->id, $finding);

    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->get();
    expect($fb)->toHaveCount(1); // old one removed, new one written — not both
    expect($fb->first()->payload['url'])->toBe('https://www.facebook.com/docpizzabar');
    expect($fb->first()->payload['source'])->toBe('instagram');
});

it('applyFinding is a safe no-op for a malformed/seeded finding with no apply recipe', function () {
    $user = igAutoSyncUser('igas12');

    app(InstagramAutoSync::class)->applyFinding((string) $user->id, ['platform' => 'facebook', 'outcome' => 'seeded', 'apply' => null]);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

// ── The old A3.2 handleClassifiedLink() block lived here ──────────────────────
//
// Deleted 2026-07-25 with the method itself (Phase 7). Its six tests called
// InstagramAutoSync::handleClassifiedLink() directly and asserted the ACTIONABLE
// allowlist, the RULING 1 social capability gate, and a seenPlatforms contract
// that LinkRouter + RouteContext own now. Keeping them green would have meant
// keeping ~150 lines of superseded gating alive purely to satisfy them — and in
// fact the method still referenced the already-deleted self::ACTIONABLE, so it
// would have fataled on any real call. Equivalent coverage now lives in:
//   - the seed() tests above (routing, conflicts, tombstones, first-link-wins)
//   - CustomLinkSeederTest's gateway block (the four RouteResult outcomes)
//   - BookingXorConnectRaceTest (the XOR set and the trait-constant fatal)
//
// Direct-call tests are also the pattern this repo has been burned by before:
// they bypass the real entry point and can pass while the live path is broken.

it('seed() produces the same findings/unmatched split through LinkRouter as it did inline', function () {
    $user = igAutoSyncUser('igas18');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.instagram.com/docpizza', // bare profile, no path beyond handle
        'https://www.facebook.com/docpizzabar',
        'https://www.fresha.com/a/doc-cuts',
    ]);

    // The Instagram link is DROPPED, not suggested (owner, 2026-09-03). This
    // assertion used to expect it as a finding, on the reasoning that it was
    // "self-referential so it stays a suggestion" — but self-referential is
    // exactly why it must not be one. We are reading this bio because that
    // account is already connected, so another instagram.com URL in it is
    // someone else's, and offered against the existing primary it renders as a
    // "Change to" swap of the user's own account for their workplace's.
    //
    // Fresha is not a finding either (2026-09-05): a harvested booking link
    // is a proposed intent, not a seed.
    expect(collect($result['findings'])->pluck('platform')->all())->toBe(['facebook']);
    expect($result['unmatched'])->toBe([]);
    expect(igBookingIntents($user))->toHaveCount(1);
});

it('routes one page once when the bio carries scheme variants of the same URL (FI-12)', function () {
    // T6 live (livplumbarber): externalUrl said http://…square.site, the
    // bio-text regex yielded https://… — the second pass hit the
    // seenPlatforms slot and carded the page whose connection had just been
    // made. Since 2026-09-05 the page is proposed rather than connected, and
    // the same rule holds: ONE intent, no card.
    setupContentTables();
    $pro = createTenant('fi12-dedupe');
    Http::fake([
        '*square.site*' => Http::response('<html><head><title>Appointments | Tough Luck</title></head></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);
    Queue::fake();

    app(InstagramAutoSync::class)->seed((string) $pro->id, [
        'http://tough-luck-barbershop.square.site/',
        'https://tough-luck-barbershop.square.site',
    ]);

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0)
        ->and(igBookingIntents($pro))->toHaveCount(1)
        ->and(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->where('kind', 'link')->count())->toBe(0);
});
