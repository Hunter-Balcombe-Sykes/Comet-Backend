<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

// V2: Per-professional UI preference to skip confirmation dialogs for specific actions (e.g., "don't ask me again" toggles).
/**
 * @property string $id
 * @property string $user_id
 * @property string $action_key
 * @property bool $skip_confirmation
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserConfirmationPreference extends BaseModel
{
    use HasUuids;

    protected $table = 'core.user_confirmation_preferences';

    public $incrementing = false;

    protected $keyType = 'string';

    // SEC-1: user_id is server-managed — excluded from mass-assignment.
    // ConfirmationPreferenceService sets it via firstOrNew()->user_id + save().
    protected $fillable = [
        'action_key',
        'skip_confirmation',
    ];

    protected $casts = [
        'skip_confirmation' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
