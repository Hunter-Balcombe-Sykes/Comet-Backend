<?php

namespace App\Models\Core\Site;

use App\Models\Analytics\LinkClick;
use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id FK → core.users.id.
 * @property string $site_id FK → site.sites.id.
 * @property string $block_type TYPE_LINK ('link', the sole type in the 'links' group) or one of the section types in config('partna.block_types.sections'); paired with block_group by the blocks_group_type_check DB CHECK.
 * @property string $block_group One of GROUP_LINKS|GROUP_SECTIONS.
 * @property string|null $title
 * @property string|null $url
 * @property string|null $icon_key
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_enabled
 * @property bool $live_check_enabled
 * @property array<string, mixed> $settings Free-form bag, shape varies by block_type/block_group. Known keys in use: note, notification_channels, notification_email, is_live, highlight, handle.
 * @property string|null $category
 * @property string|null $platform
 * @property string|null $handle
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Site|null $site
 * @property-read Collection<int, LinkClick> $clicks
 */
// V2: A single content block (link, section, etc.) on a site. Typed by block_type and block_group, sortable, with a JSON settings bag.
class Block extends BaseModel
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'site.blocks';

    public $incrementing = false;

    protected $keyType = 'string';

    // user_id/site_id (S4 Tier 2b) removed — both NOT NULL FKs; SitePolicy's
    // create()/ownerMatches() reads user_id off the raw attribute array, so a
    // silent drop here doesn't just orphan a row, it 403s every Block-create
    // endpoint. Trusted writers set both via direct property assignment.
    protected $fillable = [
        'block_type',
        'block_group',
        'title',
        'url',
        'icon_key',
        'sort_order',
        'is_active',
        'is_enabled',
        'settings',
        'live_check_enabled',
        'category',
        'platform',
        'handle',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_enabled' => 'boolean',
        'live_check_enabled' => 'boolean',
        'settings' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * block_group values. Mirror config('partna.block_types') keys and the
     * blocks_group_type_check DB CHECK. There are exactly two groups.
     */
    public const GROUP_LINKS = 'links';

    public const GROUP_SECTIONS = 'sections';

    /** The sole block_type permitted in the 'links' group. */
    public const TYPE_LINK = 'link';

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
}
