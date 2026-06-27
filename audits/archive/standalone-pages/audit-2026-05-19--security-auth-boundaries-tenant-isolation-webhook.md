Now I have enough to produce the final audit. The codebase is well-implemented overall; let me compile the confirmed findings.

# Security Audit — 2026-05-19

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, webhooks, secrets, injection, SSRF, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Middleware/Auth/VerifyHydrogenApiKey.php`
- `app/Http/Middleware/Auth/VerifyShopifySessionToken.php`
- `app/Http/Middleware/Auth/VerifySupabaseJwt.php`
- `app/Http/Middleware/Auth/RequireAal2.php`
- `app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php`
- `app/Http/Controllers/Api/Internal/EmbeddedSetupController.php`
- `app/Http/Controllers/Api/Internal/HydrogenDeploymentController.php`
- `app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php`
- `app/Http/Controllers/Api/Internal/EnvCheckController.php`
- `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`
- `app/Providers/AppServiceProvider.php`
- `tests/Feature/Security/PolicyCoverageTest.php`
- `app/Models/Analytics/SectionView.php`
- `app/Models/Commerce/CommissionClawback.php`
- `config/supabase.php`
- `routes/api.php`

> **Note on DeepSeek draft:** DeepSeek was handed `PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md` — an architectural planning document, not source code. It correctly declined to produce findings. All findings below are adjudicator-sourced from direct inspection of the production source tree.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#SEC-1** · P2 — PolicyCoverageTest stale POLICY_EXEMPT entry breaks CI coverage; two additional models are unexempted
    - **Where:** `tests/Feature/Security/PolicyCoverageTest.php:38`, `app/Providers/AppServiceProvider.php` (no registration for `CommissionClawback`, `SectionView`)
    - **Affects:** CI security gate. The test that enforces "every tenant-owned model has a registered policy" has two failures: a stale exempt entry causes the second assertion to fail, and two models with `professional_id` columns are neither registered nor exempted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Fix the stale POLICY_EXEMPT reference on line 38: replace `\App\Models\Retail\CommissionPayoutItem::class` with `\App\Models\Commerce\CommissionPayoutItem::class`.
        - For `App\Models\Analytics\SectionView`: it matches the public-ingestion pattern already used for `CartEvent`, `LinkClick`, `SiteVisit` — add it to POLICY_EXEMPT with the same justification ("Public ingestion — write-only via public site endpoints; reads gated by parent Site policy").
        - For `App\Models\Commerce\CommissionClawback`: it's an audit record nested under `CommissionPayout`, access flows through `CommissionPolicy` — add it to POLICY_EXEMPT with that justification (matches `OrderEvent` and `CommissionPayoutItem` pattern).
    - **Technical:** The test `it('every POLICY_EXEMPT entry resolves to a real model class')` calls `class_exists()` on each entry. `App\Models\Retail\CommissionPayoutItem` does not exist — the namespace was `App\Models\Commerce` when the model was written. This causes the second assertion to fail. Independently, the first assertion sweeps every PHP file under `app/Models/`, resolves its class name, and checks against `Gate::getPolicyFor()` and `POLICY_EXEMPT`. Neither `SectionView` nor `CommissionClawback` is in either list. Both tests are currently failing in CI, which undermines the coverage gate's integrity. The fix is purely in test configuration — no runtime policy logic changes needed.
    - **Plain English:** Your automated test that checks "every data model is either protected by an access-control rule or specifically acknowledged as not needing one" is currently broken in two ways: it references a data type that doesn't exist under that name anymore, and it's missing two data types that were added without being registered. Until this is fixed, the test can't be trusted to catch future gaps — it's like a smoke detector with a dead battery. The fix is a few lines in the test file.
    - **Evidence:**
        ```php
        // tests/Feature/Security/PolicyCoverageTest.php:38
        \App\Models\Retail\CommissionPayoutItem::class,  // ← wrong namespace; class does not exist

        // Actual model:
        // app/Models/Commerce/CommissionPayoutItem.php → namespace App\Models\Commerce;

        // app/Models/Analytics/SectionView.php — has professional_id FK, not in POLICY_EXEMPT
        class SectionView extends BaseModel
        {
            protected $table = 'analytics.section_views';
            // ...
            public function professional(): BelongsTo
            {
                return $this->belongsTo(Professional::class, 'professional_id');
            }
        }
        ```

- [ ] **#SEC-2** · P2 — `SUPABASE_JWKS_FAIL_CLOSED` defaults to `false` with no production boot guard; JWKS outage silently drops MFA level claims
    - **Where:** `config/supabase.php:18`, `app/Http/Middleware/Auth/VerifySupabaseJwt.php:89–102`, `app/Providers/AppServiceProvider.php:119–130`
    - **Affects:** Any route protected by `require.aal2` (currently staff routes only). During a JWKS outage the Auth-Server fallback authenticates requests but hard-codes `supabase_aal = 'aal1'`, which blocks all MFA-gated staff routes. Additionally, any policy method that calls `$this->requiresFreshAal2()` would see the wrong level for authenticated non-staff users whose real JWT claims are `aal2`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a production boot guard to `AppServiceProvider::boot()` that throws if `SUPABASE_JWKS_FAIL_CLOSED` is false, mirroring the existing `PARTNA_THROTTLE_ENABLED` guard. At minimum, emit a `Log::critical` warning so the first JWKS outage surfaces in Nightwatch.
        - Set `SUPABASE_JWKS_FAIL_CLOSED=true` in the production environment. The config comment already says "Recommended for production."
        - If a hard boot guard is too aggressive (e.g., local dev would fail), scope it to `app()->isProduction()`.
    - **Technical:** `VerifySupabaseJwt` falls back to calling Supabase's `/auth/v1/user` endpoint when JWKS verification throws for any reason. The fallback path calls `setSupabaseContext($request, $uid)` with `$claims = null`, which unconditionally sets `supabase_aal` to `'aal1'`. `RequireAal2::handle()` reads this attribute and rejects anyone whose aal is not `'aal2'`. Net effect: JWKS outage is fail-closed for staff (correct), but the behavior is undocumented to operators and silent — no alert fires when the fallback path is entered. `AppServiceProvider::boot()` already enforces `PARTNA_THROTTLE_ENABLED` and `PARTNA_PUBLIC_DOMAIN` at startup; the same pattern for `SUPABASE_JWKS_FAIL_CLOSED` would make the production posture explicit.
    - **Plain English:** Your system has a backup authentication method it uses when the primary one (JWKS) goes offline. This backup is slightly weaker — it can't confirm whether a user completed two-factor authentication. The system handles this safely for staff logins (staff get locked out during an outage rather than let through without MFA), but the backup mode runs silently with no alarm. Your configuration file already says "turn this off in production" — you just haven't added the startup check that enforces it. Think of it like a fire door that stays closed in a crisis but doesn't trigger the alarm: the safety holds, but you don't know there was a problem.
    - **Evidence:**
        ```php
        // config/supabase.php:18
        // When true, a JWKS outage returns 503 instead of falling back to Auth-Server.
        // Recommended for production once JWKS is stable.
        'jwks_fail_closed' => (bool) env('SUPABASE_JWKS_FAIL_CLOSED', false),

        // app/Http/Middleware/Auth/VerifySupabaseJwt.php:88-101
        if (config('supabase.jwks_fail_closed', false)) {
            return response()->json(['message' => 'Service unavailable'], 503);
        }

        // Fallback path — sets aal1 unconditionally regardless of JWT claims:
        // app/Http/Middleware/Auth/VerifySupabaseJwt.php:139-143
        } else {
            // Auth-Server fallback path: no claims available. Default to aal1
            // so downstream policies fail safe (treat as not-MFA-verified).
            $request->attributes->set('supabase_aal', 'aal1');
            $request->attributes->set('supabase_amr', []);
        }

        // AppServiceProvider enforces throttle but not jwks_fail_closed:
        // app/Providers/AppServiceProvider.php:119-122
        if (app()->isProduction() && ! (bool) config('partna.throttle.enabled', true)) {
            throw new \RuntimeException('PARTNA_THROTTLE_ENABLED must not be false in production.');
        }
        ```

## P3 — Nice to have

- [ ] **#SEC-3** · P3 — JWKS key warming caches sibling kid entries with the requesting JWT's algorithm rather than the key's own JWK algorithm
    - **Where:** `app/Http/Middleware/Auth/VerifySupabaseJwt.php:231–240`
    - **Affects:** Theoretical correctness only. During a JWKS key rotation period where Supabase serves multiple keys with different algorithms (e.g., one RS256 and one ES256 key), a cold cache hit for one kid warms APCu for all kids using the requesting JWT's `alg` claim. A subsequent request presenting a different kid whose true algorithm differs from the cached one would fail signature verification and fall back to JWKS re-fetch, causing a latency spike rather than a security bypass.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - When warming APCu for sibling kids in `resolveSigningKey`, derive each kid's algorithm from the JWK's own `alg` field rather than from the requesting JWT's header.
        - Extract the `alg` from `$parsedKey` (the `Key` object) rather than passing the outer `$alg` variable into the loop.
    - **Technical:** `JWK::parseKeySet()` returns `Key` objects. The `Key` class exposes its algorithm via `$key->getAlgorithm()`. The current loop uses the outer `$alg` variable — which is the algorithm declared in the requesting JWT header — for all sibling kids. In practice, Supabase issues tokens with one consistent algorithm at a time, so the bug is dormant. But during key rotation (RS256 → ES256 or vice versa), the JWKS transiently contains both key types, and the mismatch would cause spurious verification failures on warm-cache requests.
    - **Plain English:** When your server loads the public keys used to verify login tokens, it stores them with a label saying which cryptographic method each key uses. It's currently copying the same label from the current user's token onto all the keys it caches, even keys for other users. This is harmless today since all keys use the same method, but during a planned security upgrade (rotating key types) it could briefly cause incorrect labels, forcing extra lookups. Easy to fix by reading each key's own label instead.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifySupabaseJwt.php:231-240
        foreach ($parsed as $parsedKid => $parsedKey) {
            $pem = $this->extractPemFromKey($parsedKey);
            if ($pem !== null) {
                $this->apcuStore(
                    self::APCU_KEY_PREFIX.$parsedKid,
                    ['pem' => $pem, 'alg' => $alg],  // $alg is from the requesting JWT header, not from $parsedKey
                );
            }
            self::$keysByKid[$parsedKid] = $parsedKey;
        }
        ```

`★ Insight ─────────────────────────────────────`
The Partna auth middleware stack is notably well-implemented — `VerifyHydrogenApiKey` explicitly fails-closed in production on empty config (rare in Laravel codebases), `VerifyShopifySessionToken` uses Redis Lua atomics for JTI replay protection instead of the common two-step NX+INCR race, and `ShopifyAppOAuthController` correctly strips the `email auto-match` path (a common Shopify OAuth IDOR vector noted in the comments as `SEC-B#3 / SEC-F#1`). The two P2 findings are configuration/test hygiene rather than architectural gaps.
`─────────────────────────────────────────────────`
