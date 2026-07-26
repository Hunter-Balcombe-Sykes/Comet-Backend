<?php

namespace App\Models\V5;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Global platform catalog entry — shared across all users.
 *
 * @property string $id
 * @property string $name
 * @property string|null $logo
 * @property string|null $url
 * @property string|null $user_type
 * @property string|null $platform_colour
 * @property string|null $url_format
 * @property bool $is_source
 * @property bool $is_url_source
 * @property string|null $identifier_name_type
 * @property string|null $scrape_method_id
 */
class PlatformDefinition extends BaseModel
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'v5.platform_definitions';

    protected $appends = ['slug'];

    protected $fillable = [
        'name', 'logo', 'url', 'user_type', 'platform_colour',
        'url_format', 'is_source', 'is_url_source',
        'identifier_name_type', 'scrape_method_id',
        'profile_pic_url', 'follower_count', 'follower_label', 'display_name', 'bio',
    ];

    protected function casts(): array
    {
        return [
            'is_source' => 'boolean',
            'is_url_source' => 'boolean',
            'follower_count' => 'integer',
        ];
    }

    public function getSlugAttribute(): string
    {
        return strtolower(str_replace([' ', '-'], ['-', '-'], $this->name));
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformCategory::class,
            'v5.platform_category',
            'platform_definition_id',
            'platform_category_id',
        );
    }

    public function scrapeMethod(): BelongsTo
    {
        return $this->belongsTo(ScrapeMethod::class, 'scrape_method_id');
    }

    public function urlTemplates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ItemUrlTemplate::class, 'platform_definition_id');
    }

    public function sourceRules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlatformSourceRule::class, 'platform_definition_id');
    }
}
