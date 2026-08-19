<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

// One menu category (e.g. "Mains", "Sides") under a site.menus row. Categories
// are rebuilt wholesale on every scrape — no soft delete — so the menu always
// mirrors the live store. `source_platform` records which platform's structure
// this group came from (the content source). Items attach via the
// site.menu_item_categories pivot — one dish can be listed under several
// categories, positioned independently in each.
/**
 * @property string $id
 * @property string $menu_id
 * @property string $name
 * @property int $position
 * @property string|null $source_platform
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MenuCategory extends BaseModel
{
    use HasUuids;

    protected $table = 'site.menu_categories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'menu_id',
        'name',
        'position',
        'source_platform',
    ];

    protected $casts = [
        'position' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
