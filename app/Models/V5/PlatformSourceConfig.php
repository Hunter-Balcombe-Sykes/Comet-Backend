<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSourceConfig extends BaseModel
{
    use HasUuids;

    protected $table = 'v5.platform_source_configs';

    protected $fillable = [
        'user_platform_id', 'item_type', 'destination_pool', 'format',
    ];

    public function userPlatform(): BelongsTo
    {
        return $this->belongsTo(UserPlatform::class, 'user_platform_id');
    }
}
