<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

// V2: Visual theme definition for sites. Stores a JSON config blob with styling tokens. One theme can be shared across many sites.
class Theme extends BaseModel
{
    use HasFactory, HasUuids;

    protected $table = 'site.themes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'name',
        'description',
        'config',
        'is_default',
    ];

    protected $casts = [
        'config' => 'array',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'theme_id');
    }
}
