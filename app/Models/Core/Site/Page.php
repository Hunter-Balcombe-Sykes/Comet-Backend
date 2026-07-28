<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A page on a sitepage (plan §7, migration 20260727150000).
 *
 * Pages — not sections — are the navigation unit: a menu with five groups is
 * still one "Menu" nav row. `capability` comes from a closed registry and is
 * checked at WRITE time, because the read-time capability filter died with
 * SitepageId: a page the account may not have must fail to be created rather
 * than be quietly hidden at render.
 *
 * @property string $id
 * @property string $site_id FK → site.sites.id.
 * @property string $key Slug, unique per site; the public URL segment.
 * @property string $label
 * @property int $sort_order
 * @property string $order_mode manual|auto
 * @property bool $is_hidden
 * @property string|null $capability Closed registry — see PageCapabilities.
 * @property string|null $preset_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Site|null $site
 * @property-read Collection<int, Section> $sections
 */
class Page extends BaseModel
{
    use HasUuids;

    protected $table = 'site.pages';

    public $incrementing = false;

    protected $keyType = 'string';

    // site_id is deliberately not fillable: SectionPolicy reads it off the raw
    // attribute array to resolve ownership, so a silent mass-assignment drop
    // would 404 every create. Writers assign it directly.
    protected $fillable = [
        'key',
        'label',
        'sort_order',
        'order_mode',
        'is_hidden',
        'capability',
        'preset_key',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_hidden' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** @return HasMany<Section, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'page_id');
    }
}
