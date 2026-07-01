<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A single credential entry for a professional's bio — title, issuer, year.
// Promoted from core.users.about->'credentials' JSONB (FOUND-5).
// Ordered by sort_order (0-indexed, sequential from sync).
// `year` is stored as text and cast to int at read time by User::aboutPayload().
class UserCredential extends BaseModel
{
    use HasUuids;

    protected $table = 'core.user_credentials';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'title',
        'issuer',
        'year',
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
