<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use Database\Factories\Moderation\DecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Decision extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.decisions';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['id'];

    protected $casts = [
        'decided_by_system'         => 'boolean',
        'auto_actioned'             => 'boolean',
        'decided_at'                => 'datetime',
        'second_staff_approved_at'  => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'case_id');
    }

    public function decidedByStaff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'decided_by_staff_id');
    }

    public function secondStaffApproval(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'second_staff_approval_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_decision_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ActionLogEntry::class, 'decision_id');
    }

    protected static function newFactory(): DecisionFactory
    {
        return DecisionFactory::new();
    }
}
