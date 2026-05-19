<?php

namespace App\Services\Professional\Brand;

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\BrandSignupCodeAuditEntry;
use App\Models\Core\Professional\Professional;

/**
 * Manages brand signup codes — per-brand shareable codes for en-masse partner onboarding.
 *
 * Each brand has exactly one active code at a time. Codes are 16-char lowercase hex strings
 * (opaque, non-guessable). All lifecycle transitions are written to `brand.signup_code_audit`.
 */
class BrandSignupCodeService
{
    /**
     * Generate a fresh opaque signup code.
     * Single source of truth — called from BrandProfile::creating hook and the backfill command.
     *
     * @return string 16-char lowercase hex string
     */
    public function generate(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Look up the brand profile matching the given code, only if the code is currently active.
     * Returns null when no match or when the code has been deactivated.
     */
    public function resolveCode(string $code): ?BrandProfile
    {
        if ($code === '') {
            return null;
        }

        return BrandProfile::query()
            ->where('signup_code', $code)
            ->where('signup_code_active', true)
            ->first();
    }

    /**
     * Rotate the brand's signup code — generates a new code, immediately invalidating the old one.
     * Records a 'rotated' audit entry.
     *
     * @return string The new signup code.
     */
    public function rotate(BrandProfile $brand): string
    {
        $newCode = $this->generate();

        $brand->signup_code = $newCode;
        $brand->signup_code_active = true;
        $brand->signup_code_rotated_at = now();
        $brand->save();

        BrandSignupCodeAuditEntry::query()->create([
            'brand_profile_id' => $brand->id,
            'event' => 'rotated',
            'actor_type' => 'brand',
            'actor_professional_id' => $brand->professional_id,
        ]);

        return $newCode;
    }

    /**
     * Deactivate the brand's current code without rotating it.
     * The code remains in the column for audit history; resolveCode() will no longer match it.
     */
    public function deactivate(BrandProfile $brand): void
    {
        $brand->signup_code_active = false;
        $brand->save();

        BrandSignupCodeAuditEntry::query()->create([
            'brand_profile_id' => $brand->id,
            'event' => 'deactivated',
            'actor_type' => 'brand',
            'actor_professional_id' => $brand->professional_id,
        ]);
    }

    /**
     * Re-activate a previously deactivated code.
     */
    public function reactivate(BrandProfile $brand): void
    {
        $brand->signup_code_active = true;
        $brand->save();

        BrandSignupCodeAuditEntry::query()->create([
            'brand_profile_id' => $brand->id,
            'event' => 'reactivated',
            'actor_type' => 'brand',
            'actor_professional_id' => $brand->professional_id,
        ]);
    }

    /**
     * Record a successful code claim — called after the BrandPartnerLink is created.
     *
     * @param  string|null  $sourceIp  Prefer CF-Connecting-IP over request()->ip().
     */
    public function recordClaim(BrandProfile $brand, Professional $joinedPro, ?string $sourceIp = null): void
    {
        BrandSignupCodeAuditEntry::query()->create([
            'brand_profile_id' => $brand->id,
            'event' => 'claimed',
            'actor_type' => 'public',
            'source_ip' => $sourceIp,
            // Hash of first 4 chars — forensic correlation without storing the live code.
            'code_prefix_hash' => hash('sha256', mb_substr((string) $brand->signup_code, 0, 4)),
            'joined_professional_id' => $joinedPro->id,
        ]);
    }

    /**
     * Record a failed code attempt — called when no matching active code was found.
     *
     * Looks up the brand even when the code is inactive/rotated (for forensics when someone
     * retries a stale code). When the code is completely unknown, the audit row is silently
     * skipped — brand_profile_id is NOT NULL in the schema and we have no valid FK to write.
     *
     * @param  string|null  $sourceIp  Prefer CF-Connecting-IP over request()->ip().
     */
    public function recordFailedClaim(string $attemptedCode, ?string $sourceIp): void
    {
        // Search without the active filter — captures attempts against rotated/deactivated codes.
        $brand = BrandProfile::query()
            ->where('signup_code', $attemptedCode)
            ->first();

        if ($brand === null) {
            // Completely unknown code — cannot write a valid FK row. Skip silently; the
            // rate-limiter already protects against enumeration at the HTTP layer.
            return;
        }

        BrandSignupCodeAuditEntry::query()->create([
            'brand_profile_id' => $brand->id,
            'event' => 'failed_claim',
            'actor_type' => 'public',
            'source_ip' => $sourceIp,
            'code_prefix_hash' => $attemptedCode !== ''
                ? hash('sha256', mb_substr($attemptedCode, 0, 4))
                : null,
        ]);
    }
}
