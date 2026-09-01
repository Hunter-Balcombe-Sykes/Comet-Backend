<?php

use App\Ingest\FacebookEventsSourceProvisioner;
use App\Jobs\Ingest\RunSourceJob;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Item 11a (2026-09-01): the satellite provisioning seam — an EXISTING
// facebook connection carries a SECOND ingest source ('facebook_events')
// riding the page identifier the 'facebook' source already derived. The
// primary provisioning runs through the REAL observer (connections are
// created through the model). Since the observer's satellite hook WIRED
// (2026-09-01 spine pass), creating the fixture already provisions the
// satellite — so the fixture deletes that row to restore the population
// this seam exists for (connections that predate the hook, i.e. the
// backfill's), and each test drives sync()'s transitions explicitly.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    // The sync test queue would run the facebook connector's own eager
    // connect run inline — keep the paid-run machinery out of a seam test.
    Bus::fake([RunSourceJob::class]);
});

function fbEventsProvisionerUser(array $overrides = []): string
{
    return createTenant('fbev-'.Str::lower(Str::random(6)), $overrides)->id;
}

function fbEventsConnection(string $userId, array $attributes = []): IntegrationConnection
{
    $connection = IntegrationConnection::create($attributes + [
        'user_id' => $userId,
        'platform' => 'facebook',
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => ['url' => 'https://www.facebook.com/thetotehotel'],
        'is_active' => true,
    ]);

    // The wired observer hook just provisioned the satellite; drop it so the
    // fixture models a pre-hook connection (see header) and the explicit
    // sync() calls below own every transition.
    DB::table('ingest.sources')
        ->where('connection_id', $connection->id)
        ->where('source_key', 'facebook_events')
        ->delete();

    return $connection;
}

function fbEventsSourceRow(IntegrationConnection $connection): ?object
{
    return DB::table('ingest.sources')
        ->where('connection_id', $connection->id)
        ->where('source_key', 'facebook_events')
        ->first();
}

it('provisions the satellite off the parent facebook source identifier', function () {
    $connection = fbEventsConnection(fbEventsProvisionerUser());

    $result = app(FacebookEventsSourceProvisioner::class)->sync($connection);
    $row = fbEventsSourceRow($connection);

    expect($result['status'])->toBe('created')
        ->and($result['source_key'])->toBe('facebook_events')
        ->and($row)->not->toBeNull()
        // The identifier is READ from the parent row, never re-derived — one
        // page-URL grammar, owned by SourceProvisioner.
        ->and($row->identifier)->toBe('https://www.facebook.com/thetotehotel')
        ->and($row->surface_key)->toBe('facebook.profile')
        // Paid: off the scheduler by construction, eager connect run only.
        ->and((bool) $row->auto_sync)->toBeFalse()
        ->and((int) $row->cost_units)->toBe(50);

    // Idempotent — the observer re-fires on every meaningful save.
    expect(app(FacebookEventsSourceProvisioner::class)->sync($connection)['status'])->toBe('unchanged');
});

it('provisions for both account types — the gate is a capability, not a type branch', function () {
    $business = fbEventsConnection(fbEventsProvisionerUser(['account_type' => 'business']));
    $partna = fbEventsConnection(fbEventsProvisionerUser(['account_type' => 'partna']));

    expect(app(FacebookEventsSourceProvisioner::class)->sync($business)['status'])->toBe('created')
        ->and(app(FacebookEventsSourceProvisioner::class)->sync($partna)['status'])->toBe('created');
});

it('skips when no parent facebook source resolved an identifier', function () {
    // profile.php with no id resolves to nothing — the primary sync refuses
    // it, so there is no parent row and no satellite either.
    $connection = fbEventsConnection(fbEventsProvisionerUser(), [
        'payload' => ['url' => 'https://www.facebook.com/profile.php'],
    ]);

    $result = app(FacebookEventsSourceProvisioner::class)->sync($connection);

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('no_parent_source')
        ->and(fbEventsSourceRow($connection))->toBeNull();
});

it('ignores connections that are not facebook pages', function () {
    $connection = IntegrationConnection::create([
        'user_id' => fbEventsProvisionerUser(),
        'platform' => 'instagram',
        'resource_id' => 'acct-'.substr(sha1('ig'), 0, 16),
        'payload' => ['username' => 'thetotehotel'],
        'is_active' => true,
    ]);

    expect(app(FacebookEventsSourceProvisioner::class)->sync($connection)['status'])->toBe('skipped')
        ->and(fbEventsSourceRow($connection))->toBeNull();
});

it('parks the satellite when the connection deactivates and retires it on disconnect', function () {
    $provisioner = app(FacebookEventsSourceProvisioner::class);
    $connection = fbEventsConnection(fbEventsProvisionerUser());
    $provisioner->sync($connection);

    // Make a park observable: pretend the row had been scheduled.
    DB::table('ingest.sources')
        ->where('connection_id', $connection->id)->where('source_key', 'facebook_events')
        ->update(['auto_sync' => true]);

    $connection->is_active = false;
    $connection->save();

    expect($provisioner->sync($connection)['status'])->toBe('deactivated')
        ->and((bool) fbEventsSourceRow($connection)->auto_sync)->toBeFalse();

    $connection->is_active = true;
    $connection->save();
    $provisioner->sync($connection);

    $connection->delete();

    expect($provisioner->sync($connection)['status'])->toBe('retired')
        ->and((bool) fbEventsSourceRow($connection)->auto_sync)->toBeFalse();
});

it('follows a parent identifier refresh and re-dates the next attempt', function () {
    $provisioner = app(FacebookEventsSourceProvisioner::class);
    $connection = fbEventsConnection(fbEventsProvisionerUser());
    $provisioner->sync($connection);

    DB::table('ingest.sources')
        ->where('connection_id', $connection->id)->where('source_key', 'facebook_events')
        ->update(['next_attempt_at' => now()->addDays(3)->toDateTimeString()]);

    // The page moved: the primary sync re-derives the parent identifier on
    // the payload write, and the satellite follows on the same observer
    // call — the save() below IS the update (the wired hook), which is
    // exactly what this test predicted before the hook landed.
    $connection->payload = ['url' => 'https://www.facebook.com/cornerhotel'];
    $connection->save();

    $row = fbEventsSourceRow($connection);
    expect($row->identifier)->toBe('https://www.facebook.com/cornerhotel')
        // A different page is a different calendar: fetch soon, not in 3 days.
        ->and(strtotime((string) $row->next_attempt_at))->toBeLessThanOrEqual(time() + 5)
        // And a manual re-sync after the observer already followed is a no-op.
        ->and($provisioner->sync($connection)['status'])->toBe('unchanged');
});

it('schedules only when the owner lists facebook_events as a scheduled paid source', function () {
    config()->set('partna.ingest_scheduled_paid_sources', ['facebook_events']);

    $connection = fbEventsConnection(fbEventsProvisionerUser());
    app(FacebookEventsSourceProvisioner::class)->sync($connection);

    expect((bool) fbEventsSourceRow($connection)->auto_sync)->toBeTrue();
});
