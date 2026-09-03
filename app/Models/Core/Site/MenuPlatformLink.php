<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One delivery platform's sync state for a menu — replaces the per-platform
 * *_store_url / *_synced_at / *_status columns that used to live on site.menus.
 * One row per (menu, platform). `status` is the last per-platform scrape outcome,
 * independent of the overall merge result:
 *   'pending'     — queued, not yet scraped.
 *   'ok'          — menu scraped and written.
 *   'blocked'     — the Apify actor run was not successful (bot-blocked / errored).
 *   'not_found'   — the actor ran fine but returned no dataset item for the store URL.
 *   'empty_menu'  — the store mapped fine but its menu had zero categories.
 *   'unavailable' — a failure outside the Apify lane with no specific reason
 *                   recorded (mocked scraper, budget exhaustion, the transport=http
 *                   driver lane). See MenuApifyScraper::lastFailureReasons() and
 *                   MenuFetchJob::writePlatformSyncStatus().
 *
 * Survives slice 7's teardown: the store link is menu-level bookkeeping, not a
 * dish. MenuFetchJob mirrors each row into a `content.storefronts` sidecar on
 * its `order_platform` collection, which is what re-pairs a dish's deep link
 * with its platform (ManualMenuItems::platforms).
 *
 * @property string $id
 * @property string $menu_id
 * @property string $platform registry slug — 'uber-eats' | 'doordash'
 * @property string|null $store_url
 * @property Carbon|null $synced_at
 * @property string|null $status pending|ok|blocked|not_found|empty_menu|unavailable
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Menu|null $menu
 */
class MenuPlatformLink extends BaseModel
{
    use HasUuids;

    protected $table = 'site.menu_platform_links';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'menu_id',
        'platform',
        'store_url',
        'synced_at',
        'status',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
