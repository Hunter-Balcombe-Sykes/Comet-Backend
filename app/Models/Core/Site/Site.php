<?php

namespace App\Models\Core\Site;

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property mixed $id
 */
// V2: A professional's public-facing site. Owns blocks, media, theme reference, and publish state. One site per professional.
class Site extends BaseModel
{
    use HasUuids;

    protected $table = 'site.sites';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'subdomain',
        'theme_id',
        'is_published',
        'unpublished_at',
        'settings',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'settings' => 'array',
        'subdomain_changed_at' => 'datetime',
        'unpublished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class, 'theme_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'site_id')
            ->orderBy('sort_order');
    }

    public function linkBlocks(): HasMany
    {
        return $this->blocks()
            ->where('block_group', 'links')
            ->orderBy('sort_order');
    }

    public function sectionBlocks(): HasMany
    {
        return $this->blocks()
            ->where('block_group', 'sections')
            ->orderBy('sort_order');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(SiteVisit::class, 'site_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(LinkClick::class, 'site_id');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function siteMedia(): HasMany
    {
        return $this->hasMany(SiteMedia::class, 'site_id');
    }

    public function getPublishedAttribute(): bool
    {
        return (bool) ($this->attributes['is_published'] ?? false);
    }

    public function setPublishedAttribute($value): void
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $bool = $bool ?? (bool) $value;

        // Otherwise store in is_published
        $this->attributes['is_published'] = $bool;
    }
}
