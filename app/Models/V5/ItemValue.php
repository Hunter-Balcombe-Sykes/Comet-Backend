<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemValue extends BaseModel
{
    use HasUuids;

    protected $table = 'v5.item_values';

    protected $fillable = [
        'item_id', 'item_source_id', 'field_name', 'value',
        'format', 'is_manually_set',
    ];

    protected function casts(): array
    {
        return ['is_manually_set' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function itemSource(): BelongsTo
    {
        return $this->belongsTo(ItemSource::class, 'item_source_id');
    }
}
