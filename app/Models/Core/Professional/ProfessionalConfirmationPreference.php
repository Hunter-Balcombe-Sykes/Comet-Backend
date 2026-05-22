<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Per-professional UI preference to skip confirmation dialogs for specific actions (e.g., "don't ask me again" toggles).
class ProfessionalConfirmationPreference extends BaseModel
{
    use HasUuids;

    protected $table = 'core.professional_confirmation_preferences';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'action_key',
        'skip_confirmation',
    ];

    protected $casts = [
        'skip_confirmation' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }
}
