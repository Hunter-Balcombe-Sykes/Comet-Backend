<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PlatformCategory extends BaseModel
{
    use HasUuids;

    protected $table = 'v5.platform_categories';

    protected $fillable = [
        'name', 'default_refresh_interval', 'default_source_method',
    ];
}
