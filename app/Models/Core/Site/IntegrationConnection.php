<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

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
    ];

    protected $casts = [
        'payload' => 'array',
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
            if (! $connection->isDirty('platform')) {
                return;
            }

            $platform = $connection->platform;

            if (! is_string($platform) || ! app(PlatformRegistry::class)->has($platform)) {
                throw ValidationException::withMessages([
                    'platform' => 'The selected platform is not a supported platform.',
                ]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
