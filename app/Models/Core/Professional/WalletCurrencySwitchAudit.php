<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Audit trail for wallet currency denomination switches. A switch only occurs
// when the existing balance is zero, making it a low-frequency but high-impact
// event — changing denomination silently would obscure settlement history.
class WalletCurrencySwitchAudit extends BaseModel
{
    use HasUuids;

    protected $table = 'core.wallet_currency_switch_audit';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // append-only; only created_at

    public const ACTOR_TYPE_SYSTEM = 'system';

    public const ACTOR_TYPE_PROFESSIONAL = 'professional';

    public const ACTOR_TYPE_STAFF_ADMIN = 'staff_admin';

    protected $fillable = [
        'professional_id',
        'previous_currency',
        'new_currency',
        'actor_type',
        'actor_id',
        'topup_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (! $entry->created_at) {
                $entry->created_at = now();
            }
        });
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
