<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

// V2: In-app notification with typed severity, optional time window, and CTA actions. Can be global (user_id null) or targeted to one professional.
/**
 * Column list mirrors notifications.notifications in the 2026-07-26 baseline.
 * user_id is nullable BY DESIGN — a null row is a global broadcast.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $type
 * @property string|null $category
 * @property string $title
 * @property string $body
 * @property string|null $cta_url
 * @property string|null $primary_action_label
 * @property string|null $secondary_action_label
 * @property string|null $secondary_action_url
 * @property string $severity
 * @property string|null $dedupe_key
 * @property bool $critical
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $email_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Notification extends BaseModel
{
    use HasUuids;

    public const FRONTEND_TYPES = [
        'Success',
        'Critical',
        'Warning',
        'Invitation',
        'To do',
        'Info',
    ];

    protected $table = 'notifications.notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'type',
        'category',
        'title',
        'body',
        'cta_url',
        'primary_action_label',
        'secondary_action_label',
        'secondary_action_url',
        'severity',
        'starts_at',
        'ends_at',
        // OV-A: delivery escalation — true routes through the email dispatcher
        // (OV-H) in addition to in-app. Independent of display severity.
        'critical',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'email_sent_at' => 'datetime',
        'critical' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(NotificationReceipt::class, 'notification_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $now = now();

        return $query
            ->where(function (Builder $q) use ($user): void {
                $q->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            })
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }

    public static function normalizeFrontendType(?string $value, ?string $severity = null): string
    {
        $normalized = mb_strtolower(trim((string) ($value ?? '')));

        if ($normalized === 'success') {
            return 'Success';
        }

        if ($normalized === 'critical' || $normalized === 'error') {
            return 'Critical';
        }

        if ($normalized === 'warning' || $normalized === 'warn') {
            return 'Warning';
        }

        if ($normalized === 'invitation' || $normalized === 'invite') {
            return 'Invitation';
        }

        if ($normalized === 'to do' || $normalized === 'todo' || $normalized === 'task') {
            return 'To do';
        }

        if ($normalized === 'info' || $normalized === '') {
            return 'Info';
        }

        $severityNormalized = mb_strtolower(trim((string) ($severity ?? '')));
        if ($severityNormalized === 'critical') {
            return 'Critical';
        }
        if ($severityNormalized === 'warning') {
            return 'Warning';
        }
        if ($severityNormalized === 'info') {
            return 'Info';
        }

        return 'Info';
    }

    public static function severityForFrontendType(?string $value): string
    {
        return match (self::normalizeFrontendType($value)) {
            'Critical' => 'critical',
            'Warning' => 'warning',
            'To do' => 'warning',
            'Success', 'Info', 'Invitation' => 'info',
            default => 'info',
        };
    }
}
