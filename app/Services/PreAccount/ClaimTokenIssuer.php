<?php

namespace App\Services\PreAccount;

use App\Models\Core\User\PreAccountBuild;

// Mints and verifies the per-build claim capability that proves invitation
// (spec §4). The plaintext exists only in the request that minted it — we
// persist SHA-256 so a DB read yields no working capability.
class ClaimTokenIssuer
{
    // 32 bytes, base64url. Deliberately NOT a UUIDv7: the build's own id is
    // UUIDv7 and leaks creation time in its prefix. A capability should not.
    public function issue(PreAccountBuild $build): string
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $build->forceFill([
            'claim_token_hash' => hash('sha256', $plain),
            'claim_token_issued_at' => now(),
        ])->save();

        return $plain;
    }

    // Expiry lives here, not at the call sites, so every caller inherits it —
    // builds:prune-expired deletes the row eventually, but a not-yet-pruned
    // expired build must not be claimable in the meantime.
    public function matches(PreAccountBuild $build, ?string $presented): bool
    {
        $hash = (string) $build->claim_token_hash;
        $presented = (string) $presented;

        if ($hash === '' || $presented === '') {
            return false;
        }

        if ($build->expires_at !== null && now()->gte($build->expires_at)) {
            return false;
        }

        return hash_equals($hash, hash('sha256', $presented));
    }

    /**
     * The attribute fragment that spends the token, for the caller to FOLD
     * into its own write rather than issuing a second UPDATE.
     *
     * Returning a fragment instead of saving is the point: ClaimSiteService
     * merges this into the final claimed_at write, so the burn lands strictly
     * after every throw in the claim path. That makes "a failed claim does not
     * consume the lead's link" structural, not dependent on rollback.
     *
     * @return array{claim_token_hash: null}
     */
    public function burn(): array
    {
        return ['claim_token_hash' => null];
    }
}
