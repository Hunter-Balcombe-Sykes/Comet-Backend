<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\EventsPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 271-DINT-4: an event that drops out of a connection's payload must give its
// item_slugs row back. Pre-fix, IntegrationConnectionObserver only ever MINTED
// slugs — nothing ever freed one, so a finished event squatted its slug forever
// (item_slugs_unique_slug is NOT partial, so even a retired row blocks reuse)
// and next year's identically-named event landed permanently on `-2`.
//
// The retirement is deliberately conservative. site.item_slugs has no
// connection column, so the (user_id, item_type='event') scope spans EVERY
// event connection a user owns, and EventsPayload::id() is sha1(link)-derived —
// the SAME event legitimately yields the SAME id under two connections. So an
// id is only freed when it left THIS connection's payload AND no other live
// event connection of the user still claims it, active or not.

beforeEach(function () {
    setupIntegrationConnectionsTable();
    setupItemSlugsTable();

    // A real core.users row: the SQLite mirror has no item_slugs.user_id FK to
    // core.users, so a synthetic id would pass here and violate the FK on
    // production Postgres.
    $this->pro = createTenant('eventslugpro');
});

/** @param list<array<string,mixed>> $events */
function esrAccountRow(User $user, string $platform, array $events, bool $isActive = true): IntegrationConnection
{
    $url = "https://www.{$platform}.com/o/org-".Str::random(6);

    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => 'acct-'.substr(sha1($url), 0, 16),
        'payload' => EventsPayload::accountPayload($url, 'My Org', $events),
        'is_active' => $isActive,
    ]);
}

function esrEventRow(User $user, string $platform, string $id, string $name, bool $isActive = true): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => 'event-'.$id,
        'resource_kind' => 'event',
        'payload' => ['kind' => 'event', 'id' => $id, 'name' => $name],
        'is_active' => $isActive,
    ]);
}

/** Rebuild an account payload carrying exactly $events (keeps `url`/`organiser`). */
function esrRefresh(IntegrationConnection $row, array $events): void
{
    $row->update([
        'payload' => EventsPayload::accountPayload($row->payload['url'], $row->payload['organiser'], $events),
    ]);
}

function esrSlug(User $user, string $eventId): ?string
{
    return DB::connection('pgsql')->table('site.item_slugs')
        ->where('user_id', $user->id)->where('item_type', 'event')
        ->where('item_key', $eventId)->where('is_current', 1)->value('slug');
}

/** Every row for the event, current AND retired — retirement must hard-delete. */
function esrSlugRowCount(User $user, string $eventId): int
{
    return DB::connection('pgsql')->table('site.item_slugs')
        ->where('user_id', $user->id)->where('item_type', 'event')
        ->where('item_key', $eventId)->count();
}

it('retires the slug of an event that dropped out of a refreshed payload', function () {
    $row = esrAccountRow($this->pro, 'eventbrite', [
        ['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1'],
        ['id' => 'e2hexbbbbbbbbbbb', 'name' => 'Comedy Gala', 'link' => 'https://x/2'],
    ]);
    expect(esrSlug($this->pro, 'e1hexaaaaaaaaaaa'))->toBe('trivia-night');
    expect(esrSlug($this->pro, 'e2hexbbbbbbbbbbb'))->toBe('comedy-gala');

    esrRefresh($row, [['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1']]);

    expect(esrSlug($this->pro, 'e1hexaaaaaaaaaaa'))->toBe('trivia-night');
    expect(esrSlugRowCount($this->pro, 'e2hexbbbbbbbbbbb'))->toBe(0);
});

// The highest-value guard in this file. item_slugs is scoped per PROFILE, not
// per connection, so a naive "retire everything not in this payload" diff would
// wipe the sibling connections' slugs on every organiser refresh.
it('keeps a vanished event slug when another connection still claims that event id', function () {
    $account = esrAccountRow($this->pro, 'eventbrite', [
        ['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1'],
        ['id' => 'e2hexbbbbbbbbbbb', 'name' => 'Comedy Gala', 'link' => 'https://x/2'],
    ]);
    // The SAME event, also pasted by hand as a standalone events-custom row.
    // EventsPayload::id() is sha1(link)-derived, so the id genuinely matches.
    esrEventRow($this->pro, 'events-custom', 'e2hexbbbbbbbbbbb', 'Comedy Gala');

    esrRefresh($account, [['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1']]);

    expect(esrSlug($this->pro, 'e2hexbbbbbbbbbbb'))->toBe('comedy-gala');
    expect(esrSlug($this->pro, 'e1hexaaaaaaaaaaa'))->toBe('trivia-night');
});

it('keeps a vanished event slug when the sibling connection claiming it is INACTIVE', function () {
    $account = esrAccountRow($this->pro, 'eventbrite', [
        ['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1'],
        ['id' => 'e2hexbbbbbbbbbbb', 'name' => 'Comedy Gala', 'link' => 'https://x/2'],
    ]);
    // Hidden from the sitepage, but NOT gone — re-activating it must not
    // resurrect a dead URL, so its slugs have to survive.
    esrEventRow($this->pro, 'events-custom', 'e2hexbbbbbbbbbbb', 'Comedy Gala', isActive: false);

    esrRefresh($account, [['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1']]);

    expect(esrSlug($this->pro, 'e2hexbbbbbbbbbbb'))->toBe('comedy-gala');
});

it('retires an event slug when the last event leaves the payload', function () {
    // Accepted design decision: an organiser scrape that legitimately returns
    // zero events writes `upcoming: []`, so an empty new list retires
    // everything. A genuinely FAILED fetch throws FetchUnavailableException and
    // writes nothing, so it can never reach here.
    $row = esrAccountRow($this->pro, 'humanitix', [
        ['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1'],
    ]);
    expect(esrSlug($this->pro, 'e1hexaaaaaaaaaaa'))->toBe('trivia-night');

    esrRefresh($row, []);

    expect(esrSlugRowCount($this->pro, 'e1hexaaaaaaaaaaa'))->toBe(0);
});

// Reviewer D1: mint-then-retire ordering left the new occurrence permanently
// stuck on a `-2` suffix when a single payload write both drops one event and
// adds a DIFFERENT, identically-named event — the shape of a recurring
// weekly/monthly event, where each occurrence has a distinct link (hence a
// distinct sha1-derived id) but the same display name. Retiring the vanished
// event's slug BEFORE syncing the new one frees the base first, so the new
// occurrence mints clean.
it("mints the clean base slug when a same-write drop-and-add shares the vanished event's name", function () {
    $row = esrAccountRow($this->pro, 'eventbrite', [
        ['id' => 'e1hexfridaytriva', 'name' => 'Friday Trivia', 'link' => 'https://x/1'],
    ]);
    expect(esrSlug($this->pro, 'e1hexfridaytriva'))->toBe('friday-trivia');

    // Same write: drops this week's occurrence, adds next week's under a new id.
    esrRefresh($row, [
        ['id' => 'e2hexfridaytrivb', 'name' => 'Friday Trivia', 'link' => 'https://x/2'],
    ]);

    expect(esrSlugRowCount($this->pro, 'e1hexfridaytriva'))->toBe(0);
    expect(esrSlug($this->pro, 'e2hexfridaytrivb'))->toBe('friday-trivia');
});

it('mints but never retires on a fresh connection create', function () {
    // syncChanges() only runs in performUpdate(), so wasChanged('payload') is
    // false on a true create and the retirement gate is never entered — which is
    // why the gate can safely omit wasRecentlyCreated (that flag is sticky per
    // instance, so gating on it would skip retirement forever once reused).
    esrAccountRow($this->pro, 'eventbrite', [
        ['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1'],
        ['id' => 'e2hexbbbbbbbbbbb', 'name' => 'Comedy Gala', 'link' => 'https://x/2'],
    ]);

    expect(esrSlug($this->pro, 'e1hexaaaaaaaaaaa'))->toBe('trivia-night');
    expect(esrSlug($this->pro, 'e2hexbbbbbbbbbbb'))->toBe('comedy-gala');
});

it('retires every event slug when the connection is disconnected', function () {
    $row = esrAccountRow($this->pro, 'eventbrite', [
        ['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1'],
        ['id' => 'e2hexbbbbbbbbbbb', 'name' => 'Comedy Gala', 'link' => 'https://x/2'],
    ]);

    $row->delete();

    expect(esrSlugRowCount($this->pro, 'e1hexaaaaaaaaaaa'))->toBe(0);
    expect(esrSlugRowCount($this->pro, 'e2hexbbbbbbbbbbb'))->toBe(0);
});

it('keeps the ids a surviving sibling still claims when a connection is disconnected', function () {
    $account = esrAccountRow($this->pro, 'eventbrite', [
        ['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1'],
        ['id' => 'e2hexbbbbbbbbbbb', 'name' => 'Comedy Gala', 'link' => 'https://x/2'],
    ]);
    esrEventRow($this->pro, 'events-custom', 'e2hexbbbbbbbbbbb', 'Comedy Gala');

    $account->delete();

    expect(esrSlugRowCount($this->pro, 'e1hexaaaaaaaaaaa'))->toBe(0);
    expect(esrSlug($this->pro, 'e2hexbbbbbbbbbbb'))->toBe('comedy-gala');
});

it('re-mints event slugs when a disconnected connection is restored', function () {
    // forget() HARD-deletes, so without restored() the connection would come
    // back slug-less until its next payload change.
    $row = esrAccountRow($this->pro, 'eventbrite', [
        ['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1'],
    ]);
    $row->delete();
    expect(esrSlugRowCount($this->pro, 'e1hexaaaaaaaaaaa'))->toBe(0);

    $row->restore();

    expect(esrSlug($this->pro, 'e1hexaaaaaaaaaaa'))->toBe('trivia-night');
});

// A queued job is the write context live work is moving platform refreshes
// into. Observers are model-level so this passes by construction — the test
// exists to PIN that, not to discover it.
final class EsrPayloadWriteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param list<array<string,mixed>> $events */
    public function __construct(
        public readonly string $connectionId,
        public readonly array $events,
        public readonly bool $inTransaction = false,
    ) {}

    public function handle(): void
    {
        $write = function () {
            $row = IntegrationConnection::query()->findOrFail($this->connectionId);
            esrRefresh($row, $this->events);
        };

        $this->inTransaction
            ? DB::connection('pgsql')->transaction($write)
            : $write();
    }
}

it('retires a vanished event slug when the payload write happens inside a queued job', function () {
    $row = esrAccountRow($this->pro, 'eventbrite', [
        ['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1'],
        ['id' => 'e2hexbbbbbbbbbbb', 'name' => 'Comedy Gala', 'link' => 'https://x/2'],
    ]);

    dispatch_sync(new EsrPayloadWriteJob(
        (string) $row->id,
        [['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1']],
    ));

    expect(esrSlug($this->pro, 'e1hexaaaaaaaaaaa'))->toBe('trivia-night');
    expect(esrSlugRowCount($this->pro, 'e2hexbbbbbbbbbbb'))->toBe(0);
});

// ⚠ KNOWN LIMITATION, pinned deliberately — 271-DINT-4 follow-up.
//
// This observer is $afterCommit = true, so a payload write wrapped in a DB
// transaction has its `saved` callback deferred until commit. By then
// Model::finishSave() has already run syncOriginal(), so getOriginal('payload')
// hands back the NEW payload and the vanished-id diff comes out empty — the
// retirement silently does not happen. It fails SAFE (a slug stays squatted; no
// live public URL ever dies), which is why this is pinned rather than fixed
// under the audit unit that introduced it.
//
// IntegrationConnectionObserver::updated() already documents the same
// dependency for its Instagram folder cleanup ("relies on getOriginal()
// reflecting the pre-update payload, which holds here because no platform write
// runs in a DB transaction"). Live work moving connect/refresh writes into
// queued jobs MUST keep that property, or resolve it for both hooks at once.
//
// This test asserts the CURRENT behaviour on purpose: it is a tripwire. If it
// starts failing, the deferral behaviour changed — re-read the diff rule, do
// not just update the expectation.
it('skips (fail-safe) the vanished-slug retirement when the payload write runs inside a DB transaction', function () {
    $row = esrAccountRow($this->pro, 'eventbrite', [
        ['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1'],
        ['id' => 'e2hexbbbbbbbbbbb', 'name' => 'Comedy Gala', 'link' => 'https://x/2'],
    ]);

    // Drops E2, adds E3 — the added event isolates the cause.
    dispatch_sync(new EsrPayloadWriteJob(
        (string) $row->id,
        [
            ['id' => 'e1hexaaaaaaaaaaa', 'name' => 'Trivia Night', 'link' => 'https://x/1'],
            ['id' => 'e3hexccccccccccc', 'name' => 'Open Mic', 'link' => 'https://x/3'],
        ],
        inTransaction: true,
    ));

    // The observer DID fire after the commit — the newly added event was minted.
    expect(esrSlug($this->pro, 'e3hexccccccccccc'))->toBe('open-mic');
    // Untouched events keep their slugs: nothing WRONG was ever retired.
    expect(esrSlug($this->pro, 'e1hexaaaaaaaaaaa'))->toBe('trivia-night');
    // ...but the dropped event's slug survives, because getOriginal('payload')
    // was already resynced to the new value by the time the callback ran.
    expect(esrSlug($this->pro, 'e2hexbbbbbbbbbbb'))->toBe('comedy-gala');
});
