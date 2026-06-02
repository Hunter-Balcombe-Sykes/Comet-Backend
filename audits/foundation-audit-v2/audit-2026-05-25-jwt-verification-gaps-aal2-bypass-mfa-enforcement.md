All verification complete. Here's my adjudication summary before writing the final markdown:

**JWT-1:** Evidence is verbatim and confirmed. However, the claimed boot-guard exists (`AppServiceProvider.php:113-114` — `app()->isProduction() && !jwks_fail_closed → throw RuntimeException`). The attack vector (forge JWT with `aal2`) fails because `verifyWithAuthServer` passes the token to Supabase's `/auth/v1/user`, which performs signature verification server-side before returning a UID. Claims extracted *after* that call are from a signature-verified token. **Downgrade P2 → P3.** The real fix is narrow: force `aal1`/empty-amr on the fallback path regardless of payload claims, which is one-liner hardening rather than an architectural gap.

**JWT-2:** **False positive — drop.** `config/supabase.php:38` defines `supabase.admin.base_url` with default `rtrim(env('SUPABASE_URL'), '/').'/auth/v1/admin'`. The path appended in `unenrollMfaFactor` (`/users/{id}/factors/{id}`) produces the correct full GoTrue Admin API endpoint. The prior adjudication (`audits/foundation-audit-v1/`) already caught this for the same reason.

`★ Insight ─────────────────────────────────────`
- The P2→P3 downgrade on JWT-1 hinges on understanding **who actually verifies the signature**: `verifyWithAuthServer` delegates to Supabase's GoTrue, which checks the signature before responding. "Unverified" in the middleware's own logic ≠ unverified end-to-end.
- The hardening fix (override `aal` and `amr` to safe values on fallback path) is a **defense-in-depth** pattern: make each layer independently safe so a downstream infrastructure bug in Auth-Server can't escalate to an AAL bypass.
- JWT-2's false-positive pattern — two different config keys that produce the same effective value via a default expression — is a classic cross-file invariant that `Grep` across `config/*.php` instantly resolves, but isolated file review misses entirely.
`─────────────────────────────────────────────────`

---

# JWT Verification / AAL2 / MFA Enforcement Audit — 2026-05-25

**Branch:** development
**Lens:** JWT verification gaps, AAL2 bypass, MFA enforcement holes, claim trust, token replay
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Auth/RequireAal2.php
- app/Http/Middleware/Auth/RequireEmailVerified.php
- app/Http/Middleware/Auth/EnsurePartnaStaff.php
- app/Http/Middleware/Auth/EnsurePartnaAdmin.php
- app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php
- app/Services/Auth/SupabaseAdminService.php
- app/Services/Auth/SupabaseAuthHookService.php
- app/Services/Auth/TokenRevocationService.php
- app/Services/Auth/AuthFactorEventRepository.php
- app/Exceptions/Auth/JwksUnavailableException.php
- app/Providers/AppServiceProvider.php (lines 100–139)
- config/supabase.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

- [ ] **#JWT-1** · P3 — Auth-Server fallback path should force safe AAL/AMR defaults rather than trusting payload claims
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php:143–157 (fallback block)
    - **Affects:** Dev/staging environments where `SUPABASE_JWKS_FAIL_CLOSED=false` is set. Not reachable in production — the boot guard in `AppServiceProvider::boot()` (line 113) throws a `RuntimeException` on startup if this flag is false in a production environment.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After `verifyWithAuthServer()` confirms the UID, strip `aal` and `amr` from `$fallbackClaims` before passing them to `setSupabaseContext`, or explicitly pin them to safe values: `$fallbackClaims['aal'] = 'aal1'; $fallbackClaims['amr'] = [];`. Keep `session_id` in the claims so revocation tracking still works.
        - Update the inline comment to note that AAL/AMR are pinned to safe values on this path, and why — so the next reader doesn't "fix" the override thinking it's a bug.
        - No change to the boot guard or `verifyWithAuthServer` needed — both are already correct.
    - **Technical:** In the JWKS-primary path, `JWT::decode()` verifies the RS256/ES256 signature and returns the full decoded payload; `supabase_aal` and `supabase_amr` are set from those cryptographically verified claims. In the Auth-Server fallback path (`SUPABASE_JWKS_FAIL_CLOSED=false`), `verifyWithAuthServer()` calls Supabase's `GET /auth/v1/user` with the JWT as a Bearer token — Supabase's GoTrue verifies the signature server-side and returns the user object, so the token's authenticity is confirmed. The middleware then calls `extractJwtPayloadClaims($token)` (a base64-decode, no local signature check) and feeds the decoded `aal` and `amr` values directly to `setSupabaseContext`. Since the token has already been signature-verified by GoTrue, the payload is authentic and the practical attack vector described by DeepSeek (forging `aal2` in the payload to bypass `RequireAal2`) does not work — a forged JWT would be rejected at the Auth-Server step. However, the fallback path is explicitly documented as a "reduced-security" opt-in, and its security chain now depends on GoTrue never having a signature-bypass regression. Pinning `aal`/`amr` to safe values on this path makes the middleware independently safe regardless of GoTrue's internal behaviour — the `setSupabaseContext` null-claims branch already has this logic written; it just isn't called. The production boot guard (confirmed live at `AppServiceProvider.php:113`) means this path never executes in production; the fix closes the residual window in dev/staging where a JWKS blip could trigger the fallback. Note: DeepSeek's proposed fix (add a boot-time assertion) is already present and verified — that part of the recommendation is stale and should not be applied.
    - **Plain English:** Think of the fallback path as a backup entrance to the building. Normally, the main door scanner cryptographically confirms every badge before anyone enters. The backup entrance lets a third-party security guard (Supabase's own servers) confirm a badge is real — which is still genuine security, not a gap. But the backup entrance also copies whatever the badge *says* about the visitor's clearance level and passes it along unchecked. If that guard ever had a bad day and waved through a manipulated badge, the copied clearance level could let someone into a restricted floor. The fix is simple: regardless of what the backup badge says about clearance, always note the visitor as "standard access only" when they came through the backup entrance. Legitimate high-clearance staff would have their clearance re-verified on the primary door next time they visit. The main door is locked and the building can't even open with the backup-entrance-only setting in production — so this is a belt-and-suspenders tightening for the dev/staging building, not the live one.
    - **Evidence:**
        ```php
        // VerifySupabaseJwt.php — Auth-Server fallback block (lines ~141–157)
        // extractJwtPayloadClaims does NOT verify the signature — Auth-Server
        // already confirmed the token is valid above.
        $fallbackClaims = $this->extractJwtPayloadClaims($token);
        $fallbackSessionId = isset($fallbackClaims['session_id']) ? (string) $fallbackClaims['session_id'] : '';
        if ($fallbackSessionId !== '' && $this->revocation->isRevoked($fallbackSessionId)) {
            return response()->json([
                'message' => 'Session was terminated. Please log in again.',
                'code' => 'session_revoked',
            ], 401);
        }

        try {
            $this->setSupabaseContext($request, $uid, $fallbackClaims);
        ```
        ```php
        // setSupabaseContext — trusts $claims['aal'] and $claims['amr'] from the passed array
        $request->attributes->set('supabase_aal', $claims['aal'] ?? 'aal1');
        $request->attributes->set('supabase_amr', $claims['amr'] ?? []);
        ```
        ```php
        // setSupabaseContext null-claims branch — already has the safe-default logic,
        // just not called on the fallback path:
        } else {
            // Auth-Server fallback path: no claims available. Default to aal1
            // so downstream policies fail safe (treat as not-MFA-verified).
            $request->attributes->set('supabase_aal', 'aal1');
            $request->attributes->set('supabase_amr', []);
        ```
