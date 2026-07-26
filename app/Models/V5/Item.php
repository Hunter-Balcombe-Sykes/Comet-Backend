<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends BaseModel
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'v5.items';

    protected $fillable = [
        'user_id', 'identifier', 'name', 'item_type', 'is_selected',
    ];

    protected function casts(): array
    {
        return ['is_selected' => 'boolean'];
    }

    public function pools(): BelongsToMany
    {
        return $this->belongsToMany(
            ContentPool::class, 'v5.item_pool', 'item_id', 'content_pool_id',
        );
    }

    public function values(): HasMany
    {
        return $this->hasMany(ItemValue::class, 'item_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ItemSource::class, 'item_id');
    }
}
