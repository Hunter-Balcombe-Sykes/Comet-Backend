<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use Database\Factories\Moderation\CsamQuarantineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a quarantined media asset that matched the CSAM hash database.
 *
 * One row per flagged media item. The R2 binary is retained under
 * r2_quarantine_key until preservation_expires_at, then deleted. NCMEC
 * submissions are tracked via the submissions() relation.
 */
class CsamQuarantine extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.csam_quarantine';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = ['id'];

    protected $casts = [
        'cloudflare_match_payload' => 'array',
        'r2_binary_deleted'        => 'boolean',
        'r2_binary_deleted_at'     => 'datetime',
        'preservation_expires_at'  => 'datetime',
        'created_at'               => 'datetime',
        'updated_at'               => 'datetime',
    ];

    /** The moderation case this quarantine record belongs to. */
    public function case(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'case_id');
    }

    /** All NCMEC tip submissions for this quarantine record. */
    public function submissions(): HasMany
    {
        return $this->hasMany(NcmecSubmission::class, 'csam_quarantine_id');
    }

    protected static function newFactory(): CsamQuarantineFactory
    {
        return CsamQuarantineFactory::new();
    }
}
