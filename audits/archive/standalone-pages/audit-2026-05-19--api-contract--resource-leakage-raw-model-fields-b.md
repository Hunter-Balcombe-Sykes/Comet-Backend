# API Contract & Resource Leakage Audit — 2026-05-19

**Branch:** development
**Lens:** API Contract & Resource Leakage: raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Resources/ProfessionalDashboardResource.php
- app/Http/Resources/ProfessionalResource.php
- app/Http/Resources/ProfessionalPublicResource.php
- app/Http/Resources/ProfessionalStaffResource.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 2 complete

---

## P3 — Nice to have

- [ ] **API-1** · P3 — `stripe_connect_status` unconditionally present in professional-facing Resources, no capability guard for future individual account type
    - **Where:** app/Http/Resources/ProfessionalDashboardResource.php:37, app/Http/Resources/ProfessionalResource.php:35
    - **Affects:** Authenticated professional API surface. No current audience is harmed — every existing professional is either a brand or partner, both of whom have a meaningful `stripe_connect_status`. The gap becomes a confusing / misleading field the moment `individual` account type is deployed, since individuals have no Stripe Connect lifecycle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `stripe_connect_status` in `$this->when(AccountCapabilities::for($this->resource)->requires_stripe_connect, ...)` in both Resources once `AccountCapabilities` (§28.3) lands.
        - Do this as part of the §28.11 Resource capability-gating pass, not as a standalone change — the context will be there.
        - Similarly gate `onboarding_step` if its value is meaningless for individuals.
    - **Technical:** Both Resources currently emit `'stripe_connect_status' => $this->stripe_connect_status` unconditionally. When the `account_type` migration (§28.1) lands and BootstrapController begins creating `individual` professionals, their dashboard payload will include `stripe_connect_status: null` (or `not_connected`) — a field that has no business meaning for them and will clutter the shape the frontend's `account-capabilities.ts` must interpret. The fix is mechanical: one `$this->when(...)` call per Resource, keyed on the `requires_stripe_connect` capability flag. `ProfessionalPublicResource` is already correct — it does not emit this field.
    - **Plain English:** Right now every Partna user either runs a brand or is a brand affiliate, and both types have a Stripe payment account status that makes sense to show them. When individual (profile-only) users arrive, they'll have no Stripe account — but the API will still send them a Stripe status field anyway. It's harmless today and mildly confusing tomorrow. The fix is to only send the field to users it applies to.
    - **Evidence:**
        ```php
        // ProfessionalDashboardResource.php:37
        'stripe_connect_status' => $this->stripe_connect_status,

        // ProfessionalResource.php:35
        'stripe_connect_status' => $this->stripe_connect_status,

        // ProfessionalPublicResource.php — correctly omitted (no stripe field present)
        ```

- [ ] **API-2** · P3 — `ProfessionalDashboardResource` returns legacy `professional_type` but not the incoming `account_type` field required by the frontend capability module
    - **Where:** app/Http/Resources/ProfessionalDashboardResource.php:17
    - **Affects:** Authenticated professional dashboard. The architecture plan (§46 Track A, cross-track coordination point) explicitly identifies `account_type` as a required field in this Resource — Track B's `account-capabilities.ts` must read it to drive three-state routing (brand / partner / individual). Without it, the frontend falls back to two-state logic and routes individual users incorrectly.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'account_type' => $this->account_type?->value` to `ProfessionalDashboardResource::toArray()` once the `account_type` column migration (§28.1) is applied.
        - Keep `professional_type` in parallel during the dual-write window (§8) so existing frontend code keeps working — remove it only when Track B has fully migrated `account-capabilities.ts` off the legacy field.
        - Track this as part of the §28.11 / §28.13 Bootstrap flow update, not a standalone PR.
    - **Technical:** The `account_type` column doesn't exist in the DB yet — it's gated on the §28.1 migration. But once that migration lands, this Resource will silently omit the canonical routing field while continuing to return the legacy `professional_type`. The consequence is that the frontend capability module will have no data to upgrade its routing logic, defeating the purpose of the Track A/B coordination. The fix is one line, but the timing dependency on the migration means it should be part of the same PR that adds the column — not an afterthought.
    - **Plain English:** The dashboard's first API response tells the frontend what kind of user just logged in. Right now it sends a legacy field (`professional_type`) that only knows about two user types. When the platform gains a third type (individual), the frontend will need a new field (`account_type`) to know which kind of user it's dealing with. The Resource needs to be updated to send both fields during the transition so old and new frontend code both work correctly.
    - **Evidence:**
        ```php
        // ProfessionalDashboardResource.php:17 — legacy field present, account_type absent
        'professional_type' => $this->professional_type,
        // ... 'account_type' is not returned anywhere in this Resource's toArray()
        ```
