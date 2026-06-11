<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use Database\Factories\Moderation\EvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evidence extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.evidence';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'captured_at' => 'datetime',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'case_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(CaseSignal::class, 'signal_id');
    }

    protected static function newFactory(): EvidenceFactory
    {
        return EvidenceFactory::new();
    }
}
