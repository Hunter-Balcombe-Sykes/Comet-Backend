<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One delivery platform's sync state for a menu — replaces the per-platform
// *_store_url / *_synced_at / *_status columns that used to live on site.menus.
// One row per (menu, platform). `status` is the last per-platform scrape outcome
// ('pending' | 'ok' | 'unavailable'), independent of the overall merge result.
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
