<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A single experience / work-history entry for a professional's bio.
// Promoted from core.users.about->'experience' JSONB (FOUND-5).
// `organisation` stores what the legacy payload called "place".
// `start_year` / `end_year` store Y-m or Y strings (e.g. "2020", "2023-06").
// Ordered by sort_order (0-indexed, sequential from sync).
class UserExperience extends BaseModel
{
    use HasUuids;

    protected $table = 'core.user_experience';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'role',
        'organisation',
        'start_year',
        'end_year',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
