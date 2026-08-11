<?php

namespace App\Models\Core\Site;

use App\Catalog\LegacyPlatformMap;
use App\Exceptions\Platforms\TenantAnchorImmutableException;
use App\Exceptions\Platforms\UnregisteredPlatformException;
use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property string $id
 * @property string $user_id
 * @property string $platform GENERATED legacy alias of surface_key (read-only at the DB; the mutator translates legacy writes — see setPlatformAttribute).
 * @property string $surface_key Catalog surface key ({brand}.{product}) — the identity column since 20260727110000; validated against LegacyPlatformMap on write (booted() below).
 * @property string $routing_class Catalog routing class (social|content|events|shop|booking|reservations|ordering|link|ignore) — travels with surface_key.
 * @property bool $is_primary The sitepage CTA choice for this user+routing_class (unique per class where true).
 * @property string|null $created_by_detector Detector id that auto-created this row (P2 router provenance).
 * @property string|null $created_by_catalog_digest Catalog artefact digest at auto-create time.
 * @property string $resource_id
 * @property string|null $canonical_key Normalized identity key for account-row dedupe (FOUND-14); NULL for event- and link- prefixed resource rows.
 * @property string|null $resource_kind One of 'event'|'link', or NULL for account rows (platform_connections_resource_kind_check).
 * @property array<string, mixed> $payload User-curated selection + last-fetched upstream snapshot; shape varies per platform archetype — see the typed read boundaries in App\Services\Platforms\Payloads (FeedPayload, SelectionPayload, CardPayload, etc.), each a DIFFERENT subset/union of keys. NOT NULL in Postgres (default '{}'), unlike the nullable SQLite test mirror.
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $last_visited_at
 * @property Carbon|null $last_refreshed_at
 * @property string|null $last_refresh_status One of 'ok'|'unavailable'|'error'|'pending' (platform_connections_last_refresh_status_check).
 * @property string|null $last_refresh_error
 * @property int $consecutive_failures
 * @property string|null $apify_status One of 'pending'|'ok'|'unavailable' — Google Business async enrichment state, a separate state machine from last_refresh_status (platform_connections_apify_status_check).
 * @property string|null $place_id Indexed mirror of the Google Place ID — the canonical value stays in payload.placeId (FOUND-18).
 * @property string|null $refresh_etag Raw HTTP ETag from the last conditional fetch (ConditionalContext) — kept verbatim, not parsed.
 * @property string|null $refresh_last_modified Raw HTTP Last-Modified header from the last conditional fetch — kept verbatim, not a Carbon.
 * @property array<string, mixed>|null $display_settings Sparse toggle-key => bool map (absent/null key = toggle default ON); toggle sets declared per-platform on PlatformDescriptor::displayToggles.
 * @property Carbon $created_at NOT NULL in Postgres since chk_platform_connections_timestamps_not_null (supabase/migrations/20260729150016-18, DINT-8) — was nullable (DEFAULT now() with no NOT NULL) before that.
 * @property Carbon $updated_at NOT NULL in Postgres, same migration as created_at above.
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Collection<int, ShopBrand> $shopBrands
 */
// A user's connection to an external platform — a Shopify store, an Apple
// artist, an Instagram username, a Fresha salon, and so on. The per-user store
// behind the pilot platform feature (promoted from the single-tenant test-mode
// cache).
//
// Additive, self-contained feature (product decision): platforms is
// independent and does not combine with or override other site content.
//
// `payload` holds BOTH the user-curated selection (which products/albums/
// videos they feature) AND the last fetched upstream snapshot. Its shape
// varies per platform — it mirrors the blob each platform controller cached
// in test mode.
class IntegrationConnection extends BaseModel
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'site.platform_connections';

    public $incrementing = false;

    protected $keyType = 'string';

    // Mass-assignment posture (SEC-1): `user_id` is KEPT fillable on purpose —
    // mirrors the User.handle precedent. The updateOrCreate() idiom in
    // ManagesIntegrationConnection::writeConnection() (and the analogous calls
    // in GoogleBusinessAutoSync/CustomLinkSeeder/EventsCatalog/InstagramController)
    // passes `user_id` in the lookup-attributes array, which Eloquent
    // mass-assigns through create() on the not-found path — removing it from
    // $fillable would silently null out the tenant on every new connection.
    // The saving() guard below (TenantAnchorImmutableException) already blocks
    // reassignment on existing rows, so the defence-in-depth gap SEC-1 flags
    // elsewhere doesn't apply here.
    protected $fillable = [
        'user_id',
        'platform',
        'surface_key',
        'routing_class',
        'is_primary',
        'resource_id',
        'canonical_key',
        'resource_kind',
        'payload',
        'sort_order',
        'is_active',
        'last_visited_at',
        'last_refreshed_at',
        'last_refresh_status',
        'last_refresh_error',
        'consecutive_failures',
        'apify_status',
        'place_id',
        'refresh_etag',
        'refresh_last_modified',
        'display_settings',
    ];

    protected $casts = [
        'payload' => 'array',
        'display_settings' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
        'consecutive_failures' => 'integer',
        'last_visited_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * App-level replacement for the dropped `platform_connections_platform_check`
     * DB constraint (see commit c3ead5f1). Since 20260727110000 the gate is the
     * catalog surface vocabulary (LegacyPlatformMap at P1; the compiled artefact
     * once P2 consumers land) — `platform` itself is DB-generated.
     *
     * Only fires when the surface is being set or changed — so innocent
     * status-only updates to existing rows (e.g. the refresh cron writing
     * `last_refresh_status`) never re-validate. This is a DATA-INTEGRITY write
     * invariant, not resource authorization, so it correctly lives here rather
     * than in a Policy.
     */
    protected static function booted(): void
    {
        static::saving(function (self $connection) {
            // Tenant-anchor immutability guard (SEC-1): once a row is persisted,
            // its user_id must never change. On create, $exists is false so
            // mass-assigning user_id (the normal create/updateOrCreate path) is
            // unaffected. A RuntimeException subclass is NOT in Laravel's
            // $internalDontReport, so it surfaces to Nightwatch automatically on
            // throw — no explicit report() needed here (contrast with the
            // ValidationException path below that does need one).
            if ($connection->exists && $connection->isDirty('user_id')) {
                throw new TenantAnchorImmutableException(
                    connectionId: $connection->id,
                    originalUserId: $connection->getOriginal('user_id'),
                    attemptedUserId: $connection->user_id,
                );
            }

            if (! $connection->isDirty('surface_key')) {
                return;
            }

            $surfaceKey = $connection->getAttributes()['surface_key'] ?? null;

            if (! is_string($surfaceKey) || ! LegacyPlatformMap::isKnownSurface($surfaceKey)) {
                // report() before throwing so Nightwatch sees this. ValidationException
                // is in Laravel's $internalDontReport, making guard-trips in queued
                // jobs invisible without this explicit report call.
                report(new UnregisteredPlatformException(
                    platform: is_string($surfaceKey) ? $surfaceKey : '(non-string)',
                    userId: $connection->user_id,
                ));

                throw ValidationException::withMessages([
                    'platform' => 'The selected platform is not a supported platform.',
                ]);
            }

            // routing_class always travels with the surface — a caller that set
            // surface_key directly without it gets the map's answer.
            if (($connection->getAttributes()['routing_class'] ?? null) === null) {
                $connection->setAttribute('routing_class', LegacyPlatformMap::routingClassFor($surfaceKey));
            }
        });
    }

    /**
     * Legacy write path: callers still assign the old platform slug; the
     * surface key is what actually persists (the DB `platform` column is
     * GENERATED from it — writes to it would error). Accepts a surface key
     * transparently so call sites can migrate incrementally.
     */
    public function setPlatformAttribute(mixed $value): void
    {
        $value = is_string($value) ? $value : '';
        $surface = LegacyPlatformMap::surfaceFor($value)
            ?? (LegacyPlatformMap::isKnownSurface($value) ? $value : $value);

        $this->attributes['surface_key'] = $surface;
        $routing = LegacyPlatformMap::routingClassFor($surface);
        if ($routing !== null) {
            $this->attributes['routing_class'] = $routing;
        }
        // Never set attributes['platform'] — the column is generated.
        unset($this->attributes['platform']);
    }

    /**
     * Reads keep working everywhere: fresh DB rows carry the generated column;
     * unsaved in-memory models derive the legacy slug from the surface key.
     */
    public function getPlatformAttribute(?string $value): ?string
    {
        if ($value !== null) {
            return $value;
        }
        $surface = $this->attributes['surface_key'] ?? null;

        return is_string($surface) && $surface !== ''
            ? LegacyPlatformMap::legacyFor($surface)
            : null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Pre-claim predicate for the connection's owner — the gate every PRIV-1/PRIV-2
     * minimisation on a connection payload hangs off.
     *
     * A LIVE scalar read, never `$this->user` or a hydrated instance handed in from
     * a caller: these run inside multi-second scrape jobs, and a model loaded before
     * the network work can disagree with the row by the time the write lands (a claim
     * arriving mid-scrape must count as claimed). No full User hydrate for one column.
     *
     * What actually makes a null read unreachable is the FK: platform_connections
     * .user_id is ON DELETE CASCADE, so a hard-deleted owner takes its connections
     * with it and there is no orphan row to mis-read as claimed. withTrashed() closes
     * the remaining SOFT-deleted source, where the relation's scope would otherwise
     * return null and fall through to the claimed branch. Cheap insurance rather than
     * a live bug — builds:prune-expired hard-deletes unclaimed users, so a
     * soft-deleted-and-still-unclaimed owner is not a state that occurs. Note it also
     * makes PRIV-1's strip apply to a soft-deleted owner where it previously did not:
     * strictly more private, and deliberate.
     */
    public function ownerIsUnclaimed(): bool
    {
        return $this->user()->withTrashed()->value('status') === 'unclaimed';
    }

    /**
     * FOUND-25: the shop connection's brands (child table, formerly the payload map).
     *
     * @return HasMany<ShopBrand, $this>
     */
    public function shopBrands(): HasMany
    {
        return $this->hasMany(ShopBrand::class, 'connection_id')
            ->orderBy('position')->orderBy('brand_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * NULL-safe "not currently pending" predicate: excludes rows a
     * refresh/connect job owns mid-flight (last_refresh_status = 'pending'),
     * without also dropping the legitimate NULL-status rows every
     * pre-deferred-connect row still carries (no NOT NULL/DEFAULT on the
     * column — see the migration). Spelled whereNull-OR-!= rather than a bare
     * `!=` because Postgres' three-valued logic evaluates `NULL != 'pending'`
     * to NULL, not true, which would silently filter those rows out — see the
     * "never refreshed" case in DueForRefreshScopeTest.
     *
     * R1: this is the ONE query-builder spelling of "is this row mid-flight?",
     * shared by scopeDueForRefresh() below and RefreshController::refresh()'s
     * row-selection query (previously duplicated verbatim in both places).
     * It is deliberately NOT reused by SkoolController::selection() (which no
     * longer asks a status question at all — R4 moved it onto payload
     * renderability, so there is nothing there to share) or
     * FreshaController::connectStatus()'s connectPendingAt check (detects a
     * payload a refresh silently replaced, which no status predicate alone can
     * see).
     */
    public function scopeExcludingPending($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_refresh_status')
                ->orWhere('last_refresh_status', '!=', 'pending');
        });
    }

    /**
     * Connections DUE for a refresh: active, refreshed longer ago than $cutoff (or
     * never), and below the consecutive-failure circuit breaker. $cutoff is computed
     * in PHP (per-platform TTL) and bound as a param so the query is identical on
     * Postgres and the SQLite test DB. Soft-deleted rows are already excluded by the
     * model's SoftDeletes global scope.
     *
     * E-5: a 'pending' row belongs to an in-flight (or stranded) ConnectFetchJob —
     * last_refreshed_at is NULL on that row, which would otherwise match the
     * "never refreshed" arm below and let this hourly cron race the connect job,
     * potentially recording a vendor 304 as a bogus 'ok' over an empty/partial
     * payload. See scopeExcludingPending() above for the NULL-safety reasoning.
     */
    public function scopeDueForRefresh($query, \DateTimeInterface $cutoff, int $maxFailures)
    {
        return $query->active()
            ->where('consecutive_failures', '<', $maxFailures)
            ->excludingPending()
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_refreshed_at')
                    ->orWhere('last_refreshed_at', '<', $cutoff);
            });
    }

    /**
     * CA-SM review fix (E-5 follow-up): scopeDueForRefresh() above now excludes
     * EVERY 'pending' row from the cron's own selection — correct, a fresh
     * pending row is a healthy in-flight refresh/connect, not a fault. But that
     * exclusion also means a row stranded 'pending' by a dead worker (one that
     * never wrote a terminal status) silently disappears from every query built
     * on that scope, including the backlog alarm — with nothing left to notice
     * it. This is a SEPARATE, visibility-only query: it does not feed back into
     * dueForRefresh() or make the cron touch these rows (see
     * CheckPlatformRefreshBacklogCommand for why remediation is deliberately
     * out of scope here). $cutoff distinguishes "still in flight" from
     * "abandoned".
     *
     * This carried a whereNotNull('updated_at') until 2026-07-30, on the
     * reasoning that a NULL updated_at can't be proven stale. It never did
     * anything: `updated_at < $cutoff` is NULL — not TRUE — for a NULL row, so
     * SQL's three-valued logic already excluded it in both Postgres and the
     * SQLite mirror. The column is additionally NOT NULL since DINT-8
     * (20260729150016-18), so the state is unreachable as well as unselectable.
     * Do not add the guard back; it reads as protection that isn't there.
     *
     * CA-SM review fix: filtered to ->active(), matching scopeDueForRefresh().
     * A row deactivated while still 'pending' (e.g. ReconcilePlatformTakedownJob)
     * is never touched again by anything, so without this filter it would trip
     * this alarm forever — a permanent false positive, not a transient one.
     *
     * Whole-branch review, finding 2: 'custom' (link-card) rows are excluded —
     * for that platform 'pending' is an intended RESTING state, not a fault.
     * CustomLinkSeeder resets an existing row to 'pending' without dispatching
     * EnrichLinkCardJob at all on a re-seed (only $isNew does), and
     * EnrichLinkCardJob itself deliberately leaves a row 'pending' on lock
     * contention rather than force a terminal write. Without this exclusion
     * those legitimate rows accumulate into $overdue permanently and erode the
     * one alarm that now also has to catch finding 1's stranded refresh rows.
     */
    public function scopeStrandedPending($query, \DateTimeInterface $cutoff)
    {
        return $query->active()
            ->where('platform', '!=', 'custom')
            ->where('last_refresh_status', 'pending')
            ->where('updated_at', '<', $cutoff);
    }
}
