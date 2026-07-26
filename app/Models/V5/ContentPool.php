<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ContentPool extends BaseModel
{
    use HasUuids;

    protected $table = 'v5.content_pools';

    protected $fillable = ['name', 'allowed_types'];

    protected function casts(): array
    {
        return ['allowed_types' => 'array'];
    }
}
