<?php

namespace App\Models\Moderation;

use App\Models\BaseModel;
use Database\Factories\Moderation\NcmecSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks a single NCMEC CyberTipline submission attempt for a quarantined asset.
 *
 * status lifecycle: pending → submitted (on success) | failed (on error).
 * ncmec_tip_id is populated once NCMEC acknowledges the report.
 * Multiple rows per CsamQuarantine are possible if initial submission fails
 * and a retry is dispatched as a new record.
 */
class NcmecSubmission extends BaseModel
{
    use HasFactory;

    protected $table = 'moderation.ncmec_submissions';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $guarded = ['id'];

    protected $casts = [
        'payload'                => 'array',
        'ncmec_response_payload' => 'array',
        'attempts'               => 'integer',
        'submitted_at'           => 'datetime',
        'response_received_at'   => 'datetime',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    /** The quarantined asset this submission report covers. */
    public function quarantine(): BelongsTo
    {
        return $this->belongsTo(CsamQuarantine::class, 'csam_quarantine_id');
    }

    protected static function newFactory(): NcmecSubmissionFactory
    {
        return NcmecSubmissionFactory::new();
    }
}
