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

    // Mass-assignment posture (SEC-1): explicit allowlist replaces the
    // permissive `$guarded = ['id']`. `id` stays out (DB gen_random_uuid()
    // default, PK). captured_at IS a business column (not Eloquent-managed —
    // $timestamps=false, no created_at/updated_at on this table) so it stays fillable.
    protected $fillable = [
        'case_id',
        'signal_id',
        'evidence_type',
        'payload',
        'content_hash',
        'captured_at',
    ];

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
