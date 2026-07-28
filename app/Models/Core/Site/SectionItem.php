<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-section curation (plan §7). `pinned` and `excluded` are the ONLY two
 * verbs, and an ingest run may never write either (C4).
 *
 * `sort_key` is fractional so dragging an item between two neighbours never
 * rewrites the rest of the list.
 *
 * Ownership is resolved through the parent Section, never per row — the same
 * precedent as MenuItem (see POLICY_EXEMPT in PolicyCoverageTest).
 *
 * @property string $id
 * @property string $section_id FK → site.sections.id.
 * @property string $item_id content.items.id (no FK: content lives in another schema).
 * @property string $state pinned|excluded
 * @property float|null $sort_key
 * @property Carbon $created_at
 * @property-read Section|null $section
 */
class SectionItem extends BaseModel
{
    use HasUuids;

    public const STATE_PINNED = 'pinned';

    public const STATE_EXCLUDED = 'excluded';

    protected $table = 'site.section_items';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'state',
        'sort_key',
    ];

    protected $casts = [
        'sort_key' => 'float',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }
}
