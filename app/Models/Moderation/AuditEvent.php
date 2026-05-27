<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use Database\Factories\Moderation\AuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends BaseModel
{
    use HasFactory;

    protected $table = 'audit.moderation_events';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['id'];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'actor_staff_id');
    }

    protected static function newFactory(): AuditEventFactory
    {
        return AuditEventFactory::new();
    }
}
