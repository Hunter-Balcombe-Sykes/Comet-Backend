<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One menu category (e.g. "Mains", "Sides") under a site.menus row. Categories
// are rebuilt wholesale on every scrape — no soft delete — so the menu always
// mirrors the live store. `source_platform` records which platform's structure
// this group came from (the content source).
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

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'category_id')->orderBy('position');
    }
}
