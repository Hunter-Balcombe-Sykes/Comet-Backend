<?php

namespace App\Models\Core\Gdpr;

use App\Models\BaseModel;
use App\Models\Core\Professional\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Audit row for Shopify GDPR webhooks. payload_hash unique index provides
// idempotency against Shopify retries — duplicate deliveries fail insert and
// the controller treats that as "already handled".
class GdprRequest extends BaseModel
{
    use HasUuids;

    public const TOPIC_CUSTOMERS_DATA_REQUEST = 'customers/data_request';

    public const TOPIC_CUSTOMERS_REDACT = 'customers/redact';

    public const TOPIC_SHOP_REDACT = 'shop/redact';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'core.gdpr_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'topic',
        'shop_domain',
        'shopify_shop_id',
        'payload_hash',
        'payload',
        'professional_id',
        'status',
        'error',
        'received_at',
        'completed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'shopify_shop_id' => 'integer',
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function markSkipped(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_SKIPPED,
            'error' => $reason,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => mb_substr($error, 0, 2000),
        ]);
    }
}
