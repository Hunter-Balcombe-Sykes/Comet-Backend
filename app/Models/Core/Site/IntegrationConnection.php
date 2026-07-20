<?php

namespace App\Models\Core\Site;

use App\Exceptions\Platforms\TenantAnchorImmutableException;
use App\Exceptions\Platforms\UnregisteredPlatformException;
use App\Models\BaseModel;
use App\Models\Core\User\User;
use App\Services\Platforms\Registry\PlatformRegistry;
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
 * @property string $platform Validated against PlatformRegistry on write (see booted() below) — the registry, not a PHP enum, is the source of truth for valid values.
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
 * @property Carbon|null $created_at Nullable in Postgres (no NOT NULL constraint, only a DEFAULT now()) — unlike Site/PreAccountBuild's created_at.
 * @property Carbon|null $updated_at Nullable in Postgres, same as created_at above.
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

    protected $fillable = [
        'user_id',
        'platform',
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
        'consecutive_failures' => 'integer',
        'last_visited_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * App-level replacement for the dropped `platform_connections_platform_check`
     * DB constraint (see commit c3ead5f1). The PlatformRegistry is the gate.
     *
     * Only fires when `platform` is being set or changed — so innocent status-only
     * updates to existing rows (e.g. the refresh cron writing `last_refresh_status`)
     * never re-validate. This is a DATA-INTEGRITY write invariant, not resource
     * authorization, so it correctly lives here rather than in a Policy.
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

            if (! $connection->isDirty('platform')) {
                return;
            }

            $platform = $connection->platform;

            if (! is_string($platform) || ! app(PlatformRegistry::class)->has($platform)) {
                // report() before throwing so Nightwatch sees this. ValidationException
                // is in Laravel's $internalDontReport, making guard-trips in queued
                // jobs invisible without this explicit report call.
                report(new UnregisteredPlatformException(
                    platform: is_string($platform) ? $platform : '(non-string)',
                    userId: $connection->user_id,
                ));

                throw ValidationException::withMessages([
                    'platform' => 'The selected platform is not a supported platform.',
                ]);
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
     * Connections DUE for a refresh: active, refreshed longer ago than $cutoff (or
     * never), and below the consecutive-failure circuit breaker. $cutoff is computed
     * in PHP (per-platform TTL) and bound as a param so the query is identical on
     * Postgres and the SQLite test DB. Soft-deleted rows are already excluded by the
     * model's SoftDeletes global scope.
     */
    public function scopeDueForRefresh($query, \DateTimeInterface $cutoff, int $maxFailures)
    {
        return $query->active()
            ->where('consecutive_failures', '<', $maxFailures)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_refreshed_at')
                    ->orWhere('last_refreshed_at', '<', $cutoff);
            });
    }
}
