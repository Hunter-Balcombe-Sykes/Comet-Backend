<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Site\Sections\SectionRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One section of a page (plan §7, migration 20260727150000).
 *
 * A section decides its own membership from a bounded rule plus the user's
 * pins and excludes. Nothing carries an `is_selected` column, because
 * selection is a section's opinion rather than a property of an item.
 *
 * @property string $id
 * @property string $page_id FK → site.pages.id.
 * @property string $site_id FK → site.sites.id (denormalised; ownership key).
 * @property string|null $key Unique per site where set — preset lineage + support handles.
 * @property string|null $label
 * @property string $slot body|header_actions|footer
 * @property string $kind collection|richtext|contact_form|newsletter|map|document|policy
 * @property int $sort_order
 * @property array<string, mixed> $rule Bounded DSL — see SectionRule.
 * @property string $mode hand_picked|automatic|mixed
 * @property string $order_by
 * @property int|null $limit_n
 * @property string|null $group_by category|source|month|letter
 * @property string $render cards|rows|tiles|buttons|carousel
 * @property string|null $density
 * @property string|null $price_mode
 * @property int $min_items
 * @property string $on_empty hide|show_message|show_anyway
 * @property string $stale_display inherit|show|hide
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Site|null $site
 * @property-read Page|null $page
 * @property-read Collection<int, SectionItem> $curation
 * @property-read Collection<int, SectionGroup> $groups
 */
class Section extends BaseModel
{
    use HasUuids;

    protected $table = 'site.sections';

    public $incrementing = false;

    protected $keyType = 'string';

    // page_id/site_id assigned directly, not mass-assigned — same reason as
    // Page::$fillable: the policy resolves ownership off site_id.
    protected $fillable = [
        'key',
        'label',
        'slot',
        'kind',
        'sort_order',
        'rule',
        'mode',
        'order_by',
        'limit_n',
        'group_by',
        'render',
        'density',
        'price_mode',
        'min_items',
        'on_empty',
        'stale_display',
    ];

    protected $casts = [
        'rule' => 'array',
        'sort_order' => 'integer',
        'limit_n' => 'integer',
        'min_items' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** The rule as the value object that knows how to validate and narrate it. */
    public function ruleObject(): SectionRule
    {
        return SectionRule::fromArray((array) ($this->rule ?? []));
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /** @return HasMany<SectionItem, $this> */
    public function curation(): HasMany
    {
        return $this->hasMany(SectionItem::class, 'section_id');
    }

    /** @return HasMany<SectionGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(SectionGroup::class, 'section_id');
    }
}
