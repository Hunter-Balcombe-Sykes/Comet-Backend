<?php

namespace App\Services\Site;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for "can this subdomain be claimed?".
 *
 * Consumed by UpdateSiteRequest (write-path validation) and the read-only
 * GET /site/subdomain-availability endpoint that powers the live check in
 * the dashboard's URL-change flow. One code path guarantees the live
 * "Available" indicator can never disagree with what the save would do.
 */
class SubdomainAvailabilityService
{
    public const REASON_INVALID = 'invalid';

    public const REASON_OWN_CURRENT = 'own_current';

    public const REASON_OWN_ALIAS = 'own_alias';

    public const REASON_RESERVED = 'reserved';

    public const REASON_TAKEN = 'taken';

    /** Recently released by a rename and still inside its protection window. */
    public const REASON_HELD = 'held';

    /**
     * Name-only availability check (no cooldown context).
     *
     * @return array{subdomain: string, available: bool, reason: ?string}
     */
    public function check(
        string $candidate,
        ?string $excludeSiteId = null,
        ?string $userId = null,
        ?string $currentSubdomain = null,
        ?string $ownSiteId = null,
    ): array {
        $value = strtolower(trim($candidate));
        $result = static fn (bool $available, ?string $reason): array => [
            'subdomain' => $value,
            'available' => $available,
            'reason' => $reason,
        ];

        // Mirrors the min/max/regex rules on UpdateSiteRequest.
        if (strlen($value) < 3 || strlen($value) > 63
            || ! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $value)) {
            return $result(false, self::REASON_INVALID);
        }

        if ($currentSubdomain !== null && strtolower($currentSubdomain) === $value) {
            return $result(false, self::REASON_OWN_CURRENT);
        }

        $reserved = array_map('strtolower', config('partna.reserved_subdomains', []));
        if (in_array($value, $reserved, true)) {
            return $result(false, self::REASON_RESERVED);
        }

        $exists = Site::whereRaw('lower(subdomain) = ?', [$value])
            ->when($excludeSiteId, static fn ($q) => $q->where('id', '!=', $excludeSiteId))
            ->exists();
        if ($exists) {
            return $result(false, self::REASON_TAKEN);
        }

        // Only ACTIVE aliases reserve a subdomain — expired rows have released
        // the name back to the pool. The user's OWN alias reports own_alias so
        // the UI can point at the reclaim flow instead of a dead "taken".
        $aliasRow = DB::connection('pgsql')
            ->table('site.site_subdomain_aliases')
            ->whereRaw('lower(subdomain) = ?', [$value])
            ->where(static function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first(['site_id']);
        if ($aliasRow !== null) {
            $ownAlias = $ownSiteId !== null && (string) $aliasRow->site_id === $ownSiteId;

            return $result(false, $ownAlias ? self::REASON_OWN_ALIAS : self::REASON_HELD);
        }

        // Handles held by another professional's old-handle alias (redirect/SEO
        // protection). Fail-open on query errors, mirroring UpdateSiteRequest.
        try {
            $handleHeld = DB::connection('pgsql')
                ->table('core.user_handle_aliases')
                ->whereRaw('LOWER(handle) = LOWER(?)', [$value])
                ->when($userId, static fn ($q) => $q->where('user_id', '!=', $userId))
                ->where(static function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->exists();
        } catch (QueryException $e) {
            report($e);
            Log::warning('Subdomain availability: handle alias check failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'operation' => 'subdomain_alias_check',
            ]);
            $handleHeld = false;
        }
        if ($handleHeld) {
            return $result(false, self::REASON_HELD);
        }

        return $result(true, null);
    }

    /**
     * Availability + change-window context for the authenticated professional.
     * Adds the cooldown fields the URL-change UI needs to render its locked
     * state, matching UpdateSiteAction's 30-day rule.
     *
     * @return array{subdomain: string, available: bool, reason: ?string, can_change: bool, next_change_available_at: ?string}
     */
    public function checkForUser(User $professional, string $candidate): array
    {
        $professional->loadMissing('site');
        $site = $professional->site;

        $check = $this->check(
            $candidate,
            $site?->id,
            $professional->id,
            $site?->subdomain,
            $site?->id,
        );

        $canChange = true;
        $nextChangeAvailableAt = null;

        $changedAt = $site?->subdomain_changed_at;
        if ($changedAt !== null) {
            $cooldownDays = (int) config('partna.handle.subdomain_cooldown_days', 30);
            $nextAllowed = Carbon::parse($changedAt)->addDays($cooldownDays);
            if ($nextAllowed->isFuture()) {
                $canChange = false;
                $nextChangeAvailableAt = $nextAllowed->toIso8601String();
            }
        }

        return $check + [
            'can_change' => $canChange,
            'next_change_available_at' => $nextChangeAvailableAt,
        ];
    }
}
