<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Database\Factories\Moderation\CaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModerationCase extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.cases';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

    protected $casts = [
        'severity' => 'integer',
        'signal_count' => 'integer',
        'priority' => 'integer',
        'auto_actioned' => 'boolean',
        'sla_due_at' => 'datetime',
        'triaged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reportable_owner_user_id');
    }

    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'triaged_by_staff_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(CaseSignal::class, 'case_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class, 'case_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class, 'case_id');
    }

    protected static function newFactory(): CaseFactory
    {
        return CaseFactory::new();
    }
}
