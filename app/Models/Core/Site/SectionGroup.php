<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-section group label/order overrides (plan §7).
 *
 * These exist because a `group_by` other than `category` has no natural row to
 * hang a label on: grouping by month or first letter produces keys, not
 * records. Collections remain the cross-source category DATA; this table is
 * only the section's opinion about how those groups are presented.
 *
 * Ownership resolves through the parent Section (MenuItem precedent).
 *
 * @property string $id
 * @property string $section_id FK → site.sections.id.
 * @property string $group_key
 * @property string|null $label
 * @property int $sort_order
 * @property bool $is_hidden
 * @property-read Section|null $section
 */
class SectionGroup extends BaseModel
{
    use HasUuids;

    protected $table = 'site.section_groups';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'group_key',
        'label',
        'sort_order',
        'is_hidden',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_hidden' => 'boolean',
    ];

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }
}
