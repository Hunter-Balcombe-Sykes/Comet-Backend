<?php

namespace App\Ingest;

use App\Ingest\Connectors\FacebookEventsConnector;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The satellite half of the provisioning seam (Item 11a, 2026-09-01): ONE
 * facebook connection carries TWO sources — the 'facebook' media source
 * SourceProvisioner derives from the brand half of the surface key, and this
 * class's 'facebook_events' events source riding the same page.
 *
 * Deliberately a sibling of SourceProvisioner rather than a change to it:
 * sync() there is derivational (brand → the one registered connector), and
 * this wave has several units in flight against that file. The call site is
 * one line in IntegrationConnectionObserver::syncIngestSource(), AFTER the
 * primary sync — ordering is load-bearing, see identifier below. The
 * observer's maybeRunEagerly() consumes this class's result shape unchanged,
 * which is what gives the paid connector its one connect-time trigger.
 *
 * The identifier is READ FROM THE PARENT ROW, never re-derived: the
 * 'facebook' source's identifier is the canonical page URL after every
 * profile.php/pages-path/bare-handle rule SourceProvisioner::facebookPageUrl
 * fought for, and duplicating that grammar here would fork it. No parent row
 * ⇒ no satellite (the page identity is unresolved either way), and a parent
 * identifier refresh re-lands here on the same observer call that updated it.
 *
 * Capability gate (account-capability-audit): the same
 * can_autosync_scraped_connections read the ticketing seed lane
 * (EventsSeeder) consulted — a capability, never an account_type branch.
 * Both account types hold it today; the gate is the seam a consent decision
 * would flip, not a business-only fence (AU venues are the value case, but
 * a partna DJ's page events are just as real).
 */
class FacebookEventsSourceProvisioner
{
    private const SOURCE_KEY = 'facebook_events';

    private const PARENT_SOURCE_KEY = 'facebook';

    /** Mirrors SourceProvisioner::MAX_INTERVAL_FLOOR_SECS — same band maths. */
    private const MAX_INTERVAL_FLOOR_SECS = 604800;

    /**
     * Create or update the facebook_events ingest.sources row for one
     * facebook connection. Same return contract as SourceProvisioner::sync()
     * so maybeRunEagerly() can gate on 'created' without knowing which
     * provisioner answered.
     *
     * @return array{status: string, source_key?: string, reason?: string}
     */
    public function sync(IntegrationConnection $connection): array
    {
        if (strstr((string) $connection->getAttributes()['surface_key'], '.', true) !== self::PARENT_SOURCE_KEY) {
            return ['status' => 'skipped', 'reason' => 'not_facebook'];
        }

        if ($connection->resource_kind !== null) {
            // event-/link-grade rows are routing artefacts, not accounts —
            // there is nothing to poll behind them.
            return ['status' => 'skipped', 'reason' => 'resource_row'];
        }

        if ($connection->trashed() || $connection->isForceDeleting()) {
            // Same retire-not-delete rule as the primary sync: the row owns
            // its landed history, and a force delete's FK cascade removes it.
            $this->setAutoSync($connection->id, false);

            return ['status' => 'retired', 'source_key' => self::SOURCE_KEY];
        }

        if (! $connection->is_active) {
            $this->setAutoSync($connection->id, false);

            return ['status' => 'deactivated', 'source_key' => self::SOURCE_KEY];
        }

        // By query, not `$connection->user`: this hook also runs on the
        // later-save paths where the relation is not loaded, and
        // Model::preventLazyLoading is armed everywhere except production
        // (the observer's deleted() carries the war story).
        $user = User::query()->find((string) $connection->user_id);
        if ($user === null) {
            return ['status' => 'skipped', 'reason' => 'no_user', 'source_key' => self::SOURCE_KEY];
        }
        if (! AccountCapabilities::for($user)->can_autosync_scraped_connections) {
            // An EXISTING row parks rather than lingers schedulable — the
            // same retire shape the no-identifier path below takes.
            if ($this->existingRow($connection->id) !== null) {
                $this->setAutoSync($connection->id, false);

                return ['status' => 'retired', 'reason' => 'capability', 'source_key' => self::SOURCE_KEY];
            }

            return ['status' => 'skipped', 'reason' => 'capability', 'source_key' => self::SOURCE_KEY];
        }

        $identifier = $this->parentIdentifier($connection->id);
        if ($identifier === null) {
            if ($this->existingRow($connection->id) !== null) {
                $this->setAutoSync($connection->id, false);

                return ['status' => 'retired', 'reason' => 'no_parent_source', 'source_key' => self::SOURCE_KEY];
            }

            return ['status' => 'skipped', 'reason' => 'no_parent_source', 'source_key' => self::SOURCE_KEY];
        }

        $manifest = FacebookEventsConnector::manifest();
        $existing = $this->existingRow($connection->id);

        if ($existing === null) {
            // #LIFE-14 verbatim: insertOrIgnore so a concurrent duplicate
            // save's loser falls through to the update path instead of
            // raising on sources_unique_per_connection — and never reports
            // 'created' for a row it did not insert (maybeRunEagerly's gate).
            $inserted = DB::table('ingest.sources')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $connection->user_id,
                'connection_id' => $connection->id,
                'source_key' => self::SOURCE_KEY,
                'surface_key' => (string) $connection->getAttributes()['surface_key'],
                'identifier' => $identifier,
                'cost_units' => $manifest->cost->budgetWeight(),
                'min_interval_secs' => $manifest->defaultIntervalSeconds,
                'max_interval_secs' => max($manifest->defaultIntervalSeconds, self::MAX_INTERVAL_FLOOR_SECS),
                'next_attempt_at' => now(),
                'auto_sync' => self::schedulable(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted > 0) {
                return ['status' => 'created', 'source_key' => self::SOURCE_KEY];
            }

            $existing = $this->existingRow($connection->id);
            if ($existing === null) {
                return ['status' => 'skipped', 'reason' => 'insert_lost', 'source_key' => self::SOURCE_KEY];
            }
        }

        // Update ONLY identity + activation — scheduling state belongs to the
        // scheduler, same rule as the primary sync.
        $update = ['updated_at' => now()];
        if ((string) $existing->identifier !== $identifier) {
            $update['identifier'] = $identifier;
            // A different identifier is a different remote page: fetch soon.
            $update['next_attempt_at'] = now();
        }
        if (! $existing->auto_sync && self::schedulable()) {
            $update['auto_sync'] = true;
        }
        if ($existing->auto_sync && ! self::schedulable()) {
            $update['auto_sync'] = false;
        }
        DB::table('ingest.sources')->where('id', $existing->id)->update($update);

        return ['status' => count($update) > 1 ? 'updated' : 'unchanged', 'source_key' => self::SOURCE_KEY];
    }

    /**
     * The 'facebook' source's identifier for this connection — the canonical
     * page URL the primary sync already derived and keeps fresh.
     */
    private function parentIdentifier(string $connectionId): ?string
    {
        $identifier = DB::table('ingest.sources')
            ->where('connection_id', $connectionId)
            ->where('source_key', self::PARENT_SOURCE_KEY)
            ->value('identifier');

        return is_string($identifier) && $identifier !== '' ? $identifier : null;
    }

    private function existingRow(string $connectionId): ?object
    {
        return DB::table('ingest.sources')
            ->where('connection_id', $connectionId)
            ->where('source_key', self::SOURCE_KEY)
            ->first(['id', 'identifier', 'auto_sync']);
    }

    /**
     * Paid connectors run on the scheduler only by explicit owner listing —
     * the same config seam SourceProvisioner::schedulable() reads (owner
     * ruling R8). Until 'facebook_events' is listed, the eager connect run
     * (and a needs_eager_run backfill stamp) are the only triggers.
     */
    private static function schedulable(): bool
    {
        return in_array(self::SOURCE_KEY, (array) config('partna.ingest_scheduled_paid_sources', []), true);
    }

    private function setAutoSync(string $connectionId, bool $on): void
    {
        DB::table('ingest.sources')
            ->where('connection_id', $connectionId)
            ->where('source_key', self::SOURCE_KEY)
            ->update(['auto_sync' => $on, 'updated_at' => now()]);
    }
}
