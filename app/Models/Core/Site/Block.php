<?php

namespace App\Models\Core\Site;

use App\Models\Analytics\LinkClick;
use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// V2: A single content block (link, section, etc.) on a site. Typed by block_type and block_group, sortable, with a JSON settings bag.
class Block extends BaseModel
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'site.blocks';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'site_id',
        'block_type',
        'block_group',
        'title',
        'url',
        'icon_key',
        'sort_order',
        'is_active',
        'is_enabled',
        'settings',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_enabled' => 'boolean',
        'settings' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(LinkClick::class, 'link_block_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeVisible($query)
    {
        return $query->where('is_enabled', true)->where('is_active', true);
    }
}
