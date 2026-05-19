<?php

namespace App\Models\Commerce;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row for a single async commission export request.
 *
 * One row per request; tracks chunk-cursor progress, final file metadata,
 * and retention deadline. State machine: queued → processing → completed/failed/cancelled.
 *
 * @property string $id
 * @property string|null $professional_id  Nullable — ON DELETE SET NULL
 * @property string $role
 * @property string $format
 * @property array $filters
 * @property string $status
 * @property string $recipient_email
 * @property int $payouts_total
 * @property int $payouts_processed
 * @property int $chunk_size
 * @property int $chunks_total
 * @property int $chunks_completed
 * @property string|null $last_processed_payout_id  Stable cursor for chunk pagination
 * @property int $next_chunk_index
 * @property string|null $file_path
 * @property int|null $file_size_bytes
 * @property string|null $file_sha256
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $processing_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class CommissionExportAudit extends BaseModel
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'commerce.commission_export_audit';

    // No auto-increment; UUID primary key
    public $incrementing = false;
    protected $keyType = 'string';

    // All columns writable; callers use forceFill() for status transitions
    protected $guarded = [];

    // recipient_email is PII — hide from array/JSON serialisation
    protected $hidden = ['recipient_email'];

    protected $casts = [
        'filters' => 'array',
        'payouts_total' => 'integer',
        'payouts_processed' => 'integer',
        'chunk_size' => 'integer',
        'chunks_total' => 'integer',
        'chunks_completed' => 'integer',
        'next_chunk_index' => 'integer',
        'file_size_bytes' => 'integer',
        'created_at' => 'immutable_datetime',
        'processing_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    // -------------------------------------------------------------------------
    // State transitions — all writes go through forceFill() + save()
    // -------------------------------------------------------------------------

    /** Advance from queued → processing. Sets processing_at on first call only. */
    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSING,
            // Preserve the original processing_at if already set (idempotent retry-safe)
            'processing_at' => $this->processing_at ?? now(),
        ])->save();
    }

    /**
     * Record progress after one chunk completes.
     *
     * @param  int  $payoutsInChunk  Number processed in this chunk
     * @param  string|null  $lastPayoutId  Stable cursor for the next chunk
     * @param  int  $nextIndex  Zero-based index of the next chunk to dispatch
     */
    public function markChunkCompleted(int $payoutsInChunk, ?string $lastPayoutId, int $nextIndex): void
    {
        $this->forceFill([
            'payouts_processed' => $this->payouts_processed + $payoutsInChunk,
            'last_processed_payout_id' => $lastPayoutId,
            'next_chunk_index' => $nextIndex,
            'chunks_completed' => $this->chunks_completed + 1,
        ])->save();
    }

    /** Advance to completed and record the generated file's location and hash. */
    public function markCompleted(string $filePath, int $size, string $sha256): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'file_path' => $filePath,
            'file_size_bytes' => $size,
            'file_sha256' => $sha256,
            'completed_at' => now(),
        ])->save();
    }

    /** Advance to failed and record the error message (truncated to 2 000 chars). */
    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => mb_substr($error, 0, 2000),
            'completed_at' => now(),
        ])->save();
    }

    // -------------------------------------------------------------------------
    // Derived state
    // -------------------------------------------------------------------------

    /** Returns true when the row has reached a status that will not change again. */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    // -------------------------------------------------------------------------
    // Scoped finders
    // -------------------------------------------------------------------------

    /**
     * Return the most-recent in-flight row for this professional/role/format
     * combination, or null if none exists within $windowMinutes.
     *
     * Used by the submission endpoint to reject duplicate export requests.
     */
    public static function findRecentInFlight(
        string $professionalId,
        string $role,
        string $format,
        int $windowMinutes,
    ): ?self {
        return self::query()
            ->where('professional_id', $professionalId)
            ->where('role', $role)
            ->where('format', $format)
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_PROCESSING])
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Query builder for rows stuck in processing.
     *
     * Used by the watchdog command to detect and fail stalled exports.
     */
    public static function stuckInProcessing(int $olderThanMinutes): Builder
    {
        return self::query()
            ->where('status', self::STATUS_PROCESSING)
            ->where('processing_at', '<', now()->subMinutes($olderThanMinutes));
    }

    /**
     * Query builder for rows whose file retention deadline has passed.
     *
     * Used by the daily prune sweep to remove R2 objects and null out file_path.
     */
    public static function dueForRetention(): Builder
    {
        return self::query()
            ->whereNotNull('file_path')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
}
