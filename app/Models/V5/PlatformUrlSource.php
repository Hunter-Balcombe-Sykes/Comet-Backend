<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformUrlSource extends BaseModel
{
    use HasUuids;

    protected $table = 'v5.platform_url_sources';

    protected $fillable = ['user_platform_id', 'last_routed_at'];

    protected function casts(): array
    {
        return ['last_routed_at' => 'datetime'];
    }

    public function userPlatform(): BelongsTo
    {
        return $this->belongsTo(UserPlatform::class, 'user_platform_id');
    }
}
