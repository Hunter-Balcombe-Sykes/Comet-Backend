<?php

namespace App\Models\Content;

use App\Content\Values\Override;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A typed PER-COLUMN override — the C2-compliant lock (plan §6).
 *
 * This is what replaces `is_manual` flags: editing one field freezes THAT
 * field and not its siblings, reverting is deleting the row, and a NULL
 * `value` is an explicit CLEAR rather than "unset". That last distinction is
 * why {@see Override} is an object: ValueResolver reads "no row" and "a row
 * holding null" as two different answers.
 *
 * Ownership resolves through the parent Item (MenuItem precedent).
 *
 * @property string $id
 * @property string $item_id FK → content.items.id.
 * @property string $facet Facet table name — see FacetRegistry.
 * @property string $column_name Column within that facet.
 * @property mixed $value NULL = explicit clear, not "no override".
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Item|null $item
 */
class ManualOverride extends BaseModel
{
    use HasUuids;

    protected $table = 'content.manual_overrides';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'facet',
        'column_name',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The value object ValueResolver expects.
     *
     * A row that exists always produces an Override, including when its value
     * is SQL NULL — that is the explicit clear. "No override" is the absence
     * of the row, which is why this method cannot return null.
     */
    public function toOverride(): Override
    {
        return new Override($this->value);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
