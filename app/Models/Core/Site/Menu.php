<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// A user's fetched food-ordering menu — the single source of truth for menu
// CONTENT (categories → items → name/price/description/image) scraped from
// their connected online-ordering platform (Uber Eats preferred, DoorDash
// fallback). The per-platform links in IntegrationConnection stay just links
// and never duplicate these values.
//
// `categories` shape: [{ name, items: [{ name, description, price, prices?:
// [{label?, amount}], image, itemUrl? }] }]. Per-item order links
// (pickup/delivery) are NOT stored here — they are computed at read time from
// the user's live online-ordering entries. Dashboard-only — never exposed on
// the public sitepage.
class Menu extends BaseModel
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'site.menus';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'source',
        'store_url',
        'rating',
        'review_count',
        'currency',
        'categories',
        'fetch_status',
        'last_fetched_at',
    ];

    protected $casts = [
        'categories' => 'array',
        'rating' => 'float',
        'review_count' => 'integer',
        'last_fetched_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
