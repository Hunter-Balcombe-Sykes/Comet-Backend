<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserColumnSource extends BaseModel
{
    use HasUuids;

    protected $table = 'v5.user_column_sources';

    protected $fillable = [
        'user_platform_id', 'target_column',
        'sync_platform_side', 'sync_enabled_column_side',
    ];

    protected function casts(): array
    {
        return [
            'sync_platform_side' => 'boolean',
            'sync_enabled_column_side' => 'boolean',
        ];
    }

    public function userPlatform(): BelongsTo
    {
        return $this->belongsTo(UserPlatform::class, 'user_platform_id');
    }
}
