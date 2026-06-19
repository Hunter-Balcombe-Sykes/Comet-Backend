<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// A user's fetched food-ordering menu — the single source of truth for menu
// CONTENT, now relational: this row holds store-level display + provenance,
// while the dishes live in site.menu_categories → site.menu_items.
//
// `content_source` records which platform the canonical structure came from
// (Uber Eats preferred, DoorDash fallback). `pickup_platform` / `delivery_platform`
// record which platform priced each mode (from the Google-harvested type on the
// user's online-ordering links) — every item then carries a pickup_price and a
// delivery_price from those platforms. The per-platform *_store_url / *_synced_at
// / *_status columns track each scrape independently. Per-item order LINKS are
// still computed at read time from the live ordering entries (MenuSource), never
// stored. Dashboard-only — never exposed on the public sitepage.
class Menu extends BaseModel
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'site.menus';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'content_source',
        'store_name',
        'logo_url',
        'rating',
        'review_count',
        'currency',
        'pickup_platform',
        'delivery_platform',
        'uber_eats_store_url',
        'uber_eats_synced_at',
        'uber_eats_status',
        'doordash_store_url',
        'doordash_synced_at',
        'doordash_status',
        'fetch_status',
        'last_fetched_at',
    ];

    protected $casts = [
        'rating' => 'float',
        'review_count' => 'integer',
        'uber_eats_synced_at' => 'datetime',
        'doordash_synced_at' => 'datetime',
        'last_fetched_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Ordered categories for this menu. */
    public function categories(): HasMany
    {
        return $this->hasMany(MenuCategory::class, 'menu_id')->orderBy('position');
    }

    /** All dishes across every category (denormalized menu_id for direct access). */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'menu_id');
    }
}
