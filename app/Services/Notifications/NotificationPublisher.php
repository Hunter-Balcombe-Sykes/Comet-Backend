<?php

namespace App\Services\Notifications;

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Professional\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\CacheLockService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// V2: Core notification engine. Publishes with atomic dedup via dedupe_key column,
// optional email dispatch, retention policies, and per-professional category overrides.
class NotificationPublisher
{
    /**
     * Valid category keys — derived from the single source of truth in
     * config/sidest.php. FormRequests and controllers should prefer calling
     * this method directly over importing a constant.
     *
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return array_keys((array) config('partna.notifications.mailables', []));
    }

    public function publish(
        string $professionalId,
        string $frontendType,
        string $category,
        string $title,
        string $body,
        string $dedupeKey,
        ?string $ctaUrl = null,
        ?string $primaryActionLabel = 'View',
        ?string $secondaryActionLabel = 'Dismiss',
        ?string $secondaryActionUrl = null,
        ?string $retentionConfigKey = null,
    ): void {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            Log::warning('NotificationPublisher: dropped notification — empty professional_id', [
                'category' => $category,
                'frontend_type' => $frontendType,
            ]);

            return;
        }

        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            Log::warning('NotificationPublisher: dropped notification — empty title/body', [
                'category' => $category,
                'professional_id' => $professionalId,
                'empty_field' => $title === '' ? 'title' : 'body',
            ]);

            return;
        }

        $dedupeKey = trim($dedupeKey);
        if ($dedupeKey === '') {
            // Empty dedupe key is always a caller bug — alert via Nightwatch.
            report(new \UnexpectedValueException('NotificationPublisher: empty dedupeKey'));
            Log::warning('NotificationPublisher: dropped notification — empty dedupe_key', [
                'category' => $category,
                'professional_id' => $professionalId,
            ]);

            return;
        }

        // Defence-in-depth capability gate (audit #6). The downstream email
        // job already gates on the same map; checking here also stops the
        // in-app bell row from being inserted for incapable recipients. Any
        // caller that publishes directly (observers, services) automatically
        // inherits this gate without needing its own AccountCapabilities call.
        if (! self::passesCapabilityGate($category, $professionalId)) {
            Log::info('NotificationPublisher: dropped — capability gate', [
                'professional_id' => $professionalId,
                'category' => $category,
            ]);

            return;
        }

        $now = now();
        $type = Notification::normalizeFrontendType($frontendType);
        $retentionKey = $retentionConfigKey ?? 'default';
        $days = config("partna.notification_retention_days.{$retentionKey}")
            ?? config('partna.notification_retention_days.default', 30);

        $notificationId = (string) Str::uuid();

        // Atomic upsert: ON CONFLICT on (professional_id, dedupe_key) DO NOTHING.
        // If a notification with this dedupe_key already exists for this pro,
        // this is a no-op — no duplicate row, no race window.
        $inserted = DB::table('notifications.notifications')->insertOrIgnore([
            'id' => $notificationId,
            'professional_id' => $professionalId,
            'type' => $type,
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'cta_url' => $ctaUrl ?? '/account/overview',
            'primary_action_label' => $primaryActionLabel,
            'secondary_action_label' => $secondaryActionLabel,
            'secondary_action_url' => $secondaryActionUrl,
            'severity' => Notification::severityForFrontendType($type),
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays((int) $days),
            'dedupe_key' => $dedupeKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Only dispatch the email job for genuinely-new rows. insertOrIgnore()
        // returns the number of rows actually inserted (0 on conflict).
        if ($inserted > 0 && config('partna.notifications.email_enabled', false)) {
            SendTransactionalNotificationEmailJob::dispatch(
                $notificationId,
                $category,
                $professionalId,
            )->onQueue('mail');
        }
    }

    /**
     * Bulk variant of {@see publish()}. One insertOrIgnore + one select-back to
     * identify genuinely-new rows, then a per-row email dispatch only for those.
     * Use for fan-out — single-shot callers should keep using publish().
     *
     * Each item must supply the same keys as publish()'s named args (minus the
     * email-dispatch trigger). Items with empty professional_id, title, body,
     * or dedupe_key are silently skipped.
     *
     * @param  array<int, array{
     *     professionalId: string,
     *     frontendType: string,
     *     category: string,
     *     title: string,
     *     body: string,
     *     dedupeKey: string,
     *     ctaUrl?: string|null,
     *     primaryActionLabel?: string|null,
     *     secondaryActionLabel?: string|null,
     *     secondaryActionUrl?: string|null,
     *     retentionConfigKey?: string|null,
     * }>  $items
     */
    public function publishMany(array $items): void
    {
        if ($items === []) {
            return;
        }

        $now = now();
        $rows = [];
        $idToCategoryAndPro = [];

        $skipped = 0;
        foreach ($items as $index => $item) {
            $professionalId = trim((string) ($item['professionalId'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $body = trim((string) ($item['body'] ?? ''));
            $dedupeKey = trim((string) ($item['dedupeKey'] ?? ''));
            if ($professionalId === '' || $title === '' || $body === '' || $dedupeKey === '') {
                Log::warning('NotificationPublisher::publishMany — skipped invalid item', [
                    'index' => $index,
                    'category' => (string) ($item['category'] ?? ''),
                    'missing' => array_keys(array_filter([
                        'professionalId' => $professionalId === '',
                        'title' => $title === '',
                        'body' => $body === '',
                        'dedupeKey' => $dedupeKey === '',
                    ])),
                ]);
                $skipped++;

                continue;
            }

            $category = (string) ($item['category'] ?? '');

            // Defence-in-depth capability gate (audit #6) — see publish().
            if (! self::passesCapabilityGate($category, $professionalId)) {
                Log::info('NotificationPublisher::publishMany — dropped item, capability gate', [
                    'index' => $index,
                    'category' => $category,
                    'professional_id' => $professionalId,
                ]);
                $skipped++;

                continue;
            }

            $type = Notification::normalizeFrontendType((string) ($item['frontendType'] ?? ''));
            $retentionKey = $item['retentionConfigKey'] ?? 'default';
            $days = config("partna.notification_retention_days.{$retentionKey}")
                ?? config('partna.notification_retention_days.default', 30);
            $notificationId = (string) Str::uuid();

            $rows[] = [
                'id' => $notificationId,
                'professional_id' => $professionalId,
                'type' => $type,
                'category' => $category,
                'title' => $title,
                'body' => $body,
                'cta_url' => $item['ctaUrl'] ?? '/account/overview',
                'primary_action_label' => $item['primaryActionLabel'] ?? 'View',
                'secondary_action_label' => $item['secondaryActionLabel'] ?? 'Dismiss',
                'secondary_action_url' => $item['secondaryActionUrl'] ?? null,
                'severity' => Notification::severityForFrontendType($type),
                'starts_at' => $now,
                'ends_at' => $now->copy()->addDays((int) $days),
                'dedupe_key' => $dedupeKey,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $idToCategoryAndPro[$notificationId] = [$category, $professionalId];
        }

        if ($rows === []) {
            Log::warning('NotificationPublisher::publishMany — all items invalid, nothing published', [
                'input_count' => count($items),
                'skipped' => $skipped,
            ]);

            return;
        }

        DB::table('notifications.notifications')->insertOrIgnore($rows);

        if (! config('partna.notifications.email_enabled', false)) {
            return;
        }

        // Select back the IDs that actually landed. Conflicting rows (same
        // professional_id + dedupe_key already present) were ignored, so their
        // UUIDs won't be in the table.
        $insertedIds = DB::table('notifications.notifications')
            ->whereIn('id', array_keys($idToCategoryAndPro))
            ->pluck('id')
            ->all();

        foreach ($insertedIds as $id) {
            [$category, $professionalId] = $idToCategoryAndPro[$id];
            SendTransactionalNotificationEmailJob::dispatch($id, $category, $professionalId)
                ->onQueue('mail');
        }
    }

    /**
     * Defence-in-depth capability gate for outbound notifications (audit #6).
     *
     * Reuses SendTransactionalNotificationEmailJob's CAPABILITY_GATE_MAP so the
     * publisher and the email job share one source of truth. Categories absent
     * from the map are unrestricted (analytics_weekly, profile_tasks, etc.).
     *
     * Fail-open on missing Professional: the email job already fail-closes at
     * send time. A missing pro row at the publisher layer typically means a
     * test scaffold or a deleted account with no auth context — dropping the
     * in-app notification silently would mask the underlying issue.
     */
    private static function passesCapabilityGate(string $category, string $professionalId): bool
    {
        $capabilityProperty = SendTransactionalNotificationEmailJob::capabilityGateMap()[$category] ?? null;
        if ($capabilityProperty === null) {
            return true;
        }

        try {
            $pro = User::find($professionalId);
        } catch (\Throwable $e) {
            // DB outage / test scaffold without core.professionals attached.
            // Fall open — email job will fail-close downstream if it matters.
            Log::warning('NotificationPublisher: capability gate Professional lookup failed', [
                'professional_id' => $professionalId,
                'category' => $category,
                'message' => $e->getMessage(),
            ]);

            return true;
        }

        if (! $pro) {
            Log::warning('NotificationPublisher: capability gate could not load Professional', [
                'professional_id' => $professionalId,
                'category' => $category,
            ]);

            return true;
        }

        return (bool) AccountCapabilities::for($pro)->{$capabilityProperty};
    }

    /**
     * Mandatory categories — see config('partna.notifications.mandatory_categories').
     * Whether a category is mandatory wins over every other rung in the
     * resolution chain (per-pro policy, global policy, user preference). The
     * controller surfaces this so the frontend renders these toggles disabled.
     *
     * @return array<int, string>
     */
    public static function mandatoryCategories(): array
    {
        return (array) config('partna.notifications.mandatory_categories', []);
    }

    public static function isMandatory(string $category): bool
    {
        return in_array($category, self::mandatoryCategories(), true);
    }

    /**
     * Cache TTL for the per-professional resolved-preferences map. Long enough
     * to absorb the burst pattern of fan-out sends; short enough that a missed
     * invalidation self-heals within an hour.
     */
    private const CACHE_TTL_SECONDS = 3600;

    private const GLOBAL_VERSION_KEY = 'notif_pref:global_v';

    public static function resolveEmailEnabled(string $professionalId, string $category): bool
    {
        // Mandatory check stays at the top — config-driven, no DB or cache hit.
        if (self::isMandatory($category)) {
            return true;
        }

        $map = self::loadResolvedMap($professionalId);

        // Categories registered after the cache was warmed will be missing
        // from the map. Refresh-and-retry rather than blindly returning the
        // default so a new category lights up immediately.
        if (! array_key_exists($category, $map)) {
            self::forget($professionalId);
            $map = self::loadResolvedMap($professionalId);
        }

        return $map[$category] ?? true;
    }

    /**
     * Per-professional {category → bool} map. Cache-first; on miss, computes
     * every category in three batched queries (per-pro policies, global
     * policies, user prefs) — same query count as a single uncached
     * resolveEmailEnabled() call, amortized across the whole registry.
     *
     * @return array<string, bool>
     */
    public static function loadResolvedMap(string $professionalId): array
    {
        $key = self::cacheKey($professionalId);

        try {
            // Single-flight via CacheLockService — under fan-out (50+ workers
            // hitting the same cold key) only one computes; the rest block on
            // the lock and read the warmed value. Adds jitter + SWR semantics
            // for free.
            $value = app(CacheLockService::class)->rememberLocked(
                $key,
                self::CACHE_TTL_SECONDS,
                fn () => self::computeResolvedMap($professionalId),
            );

            return is_array($value) ? $value : self::computeResolvedMap($professionalId);
        } catch (\Throwable $e) {
            // Cache outage must never break sends — fall back to uncached path.
            Log::warning('Notification preference cache write failed', [
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);

            return self::computeResolvedMap($professionalId);
        }
    }

    /**
     * Invalidate a single professional's preference cache. Called by both the
     * user PATCH endpoint and the staff per-professional policy writer.
     */
    public static function forget(string $professionalId): void
    {
        // Pinned to redis — falling through to a file/array driver would make
        // invalidation worker-local and silently leave other workers stale.
        $key = self::cacheKey($professionalId);
        self::store()->forget($key);
        self::store()->forget($key.':stale');
    }

    /**
     * Bump the global cache version, atomically invalidating every per-pro
     * entry without enumerating them. Called when staff edits a global policy.
     */
    public static function bumpGlobalVersion(): void
    {
        try {
            // Pinned to redis — a file-driver fallback would make the bump
            // worker-local; per-pro invalidation via the version key would
            // silently break across the fleet.
            self::store()->add(self::GLOBAL_VERSION_KEY, 1, null);
            self::store()->increment(self::GLOBAL_VERSION_KEY);
        } catch (\Throwable $e) {
            Log::warning('Notification preference cache version bump failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function cacheKey(string $professionalId): string
    {
        $version = (int) (self::store()->get(self::GLOBAL_VERSION_KEY) ?? 1);

        return "notif_pref:p:{$professionalId}:v{$version}";
    }

    /**
     * Pin every cache touch in this service to redis explicitly. The app's
     * caching architecture assumes a shared Redis store across all workers;
     * relying on the default driver leaves us exposed to a misconfigured env
     * (file/array) silently breaking cross-worker sharing and invalidation.
     */
    private static function store(): CacheRepository
    {
        // Tests use the configured default (array) — explicit pinning would
        // require booting a redis store that the test harness doesn't provide.
        if (app()->environment('testing')) {
            return Cache::store();
        }

        return Cache::store('redis');
    }

    /**
     * Three batched lookups → resolved bool per category. Mirrors the
     * precedence chain in resolveEmailEnabled's prior implementation:
     * mandatory > per-pro policy > global policy > user pref > default.
     *
     * @return array<string, bool>
     */
    private static function computeResolvedMap(string $professionalId): array
    {
        $categories = self::categories();
        $mandatory = self::mandatoryCategories();

        $perProPolicies = DB::table('notifications.notification_email_policies')
            ->where('professional_id', $professionalId)
            ->pluck('mode', 'category_key')
            ->all();

        $globalPolicies = DB::table('notifications.notification_email_policies')
            ->whereNull('professional_id')
            ->pluck('mode', 'category_key')
            ->all();

        $prefs = DB::table('notifications.notification_email_preferences')
            ->where('professional_id', $professionalId)
            ->pluck('enabled', 'category_key')
            ->all();

        $map = [];
        foreach ($categories as $category) {
            if (in_array($category, $mandatory, true)) {
                $map[$category] = true;

                continue;
            }

            $perPro = $perProPolicies[$category] ?? null;
            if ($perPro === 'force_on') {
                $map[$category] = true;

                continue;
            }
            if ($perPro === 'force_off') {
                $map[$category] = false;

                continue;
            }

            $global = $globalPolicies[$category] ?? null;
            if ($global === 'force_on') {
                $map[$category] = true;

                continue;
            }
            if ($global === 'force_off') {
                $map[$category] = false;

                continue;
            }

            if (array_key_exists($category, $prefs)) {
                $map[$category] = (bool) $prefs[$category];

                continue;
            }

            $map[$category] = true; // default enabled
        }

        return $map;
    }
}
