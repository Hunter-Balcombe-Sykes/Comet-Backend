<?php

namespace App\Models\Content;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A possible duplicate the resolver refuses to decide alone (plan §5).
 *
 * The resolver merges on joining keys and, cross-source, on unambiguous
 * corroborating keys. Everything weaker lands here instead of being merged:
 * false-split over false-merge, with this queue as the recovery path.
 *
 * @property string $id
 * @property string $user_id FK → core.users.id.
 * @property string $left_item_id FK → content.items.id.
 * @property string $right_item_id FK → content.items.id.
 * @property int $score
 * @property array<string, mixed> $evidence Why the resolver thought these might be one thing.
 * @property Carbon|null $dismissed_at Permanent — re-asking an answered question is how a queue becomes noise.
 * @property Carbon $created_at
 * @property-read User|null $user
 * @property-read Item|null $leftItem
 * @property-read Item|null $rightItem
 */
class IdentityCandidate extends BaseModel
{
    use HasUuids;

    protected $table = 'content.identity_candidates';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'left_item_id',
        'right_item_id',
        'score',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
        'score' => 'integer',
        'dismissed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function leftItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'left_item_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function rightItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'right_item_id');
    }
}
