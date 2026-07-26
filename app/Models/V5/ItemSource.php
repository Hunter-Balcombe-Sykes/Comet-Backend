<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemSource extends BaseModel
{
    use HasUuids;

    protected $table = 'v5.item_sources';

    protected $fillable = ['item_id', 'user_platform_id', 'is_enabled'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function userPlatform(): BelongsTo
    {
        return $this->belongsTo(UserPlatform::class, 'user_platform_id');
    }
}
