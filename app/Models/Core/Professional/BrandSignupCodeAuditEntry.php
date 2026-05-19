<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only audit log for brand signup code lifecycle events.
// Never mutate existing rows — insert a new event row instead.
class BrandSignupCodeAuditEntry extends BaseModel
{
    use HasUuids;

    protected $table = 'brand.signup_code_audit';

    // No updated_at — append-only; only created_at set by DB default.
    public $timestamps = false;

    protected $fillable = [
        'brand_profile_id',
        'event',
        'actor_type',
        'actor_professional_id',
        'staff_user_id',
        'source_ip',
        'code_prefix_hash',
        'joined_professional_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function brandProfile(): BelongsTo
    {
        return $this->belongsTo(BrandProfile::class, 'brand_profile_id');
    }

    public function actorProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'actor_professional_id');
    }

    public function joinedProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'joined_professional_id');
    }
}
