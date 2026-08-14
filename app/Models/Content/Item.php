<?php

namespace App\Models\Content;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The content spine (plan §6, migration 20260727140000). Deliberately thin:
 * no pool, no is_selected, no sort_order, no identifier column — placement is
 * a section's business, and identity is COMPUTED by the resolver.
 *
 * `removed_at` is the ONE user-delete mechanism and is never cleared by
 * reappearance; nothing else here means "hidden".
 *
 * @property string $id
 * @property string $user_id FK → core.users.id.
 * @property string $kind One of the 13 closed kinds — see KindRegistry. (The
 *                        DB CHECK still permits 14; the registry is narrower
 *                        on purpose — see KindRegistry's docblock.)
 * @property string|null $headline_cache Dashboard filtering only; serving joins live tables.
 * @property list<string> $facets_cache
 * @property list<string> $eligible_cache
 * @property Carbon|null $removed_at
 * @property string|null $review_flag
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 * @property-read Collection<int, ManualOverride> $overrides
 */
class Item extends BaseModel
{
    use HasUuids;

    protected $table = 'content.items';

    public $incrementing = false;

    protected $keyType = 'string';

    // user_id assigned directly — ContentItemPolicy resolves ownership off the
    // raw attribute array, so a mass-assignment drop would 404 every write.
    protected $fillable = [
        'kind',
        'headline_cache',
        'review_flag',
    ];

    protected $casts = [
        'facets_cache' => 'array',
        'eligible_cache' => 'array',
        'removed_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<ManualOverride, $this> */
    public function overrides(): HasMany
    {
        return $this->hasMany(ManualOverride::class, 'item_id');
    }
}
