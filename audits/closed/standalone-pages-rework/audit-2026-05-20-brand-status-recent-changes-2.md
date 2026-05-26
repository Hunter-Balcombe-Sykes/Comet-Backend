`★ Insight ─────────────────────────────────────`
The `rememberLockedNullable` variant in `CacheLockService` is a simpler lock+sentinel pattern — it does **not** write a `:stale` companion key, unlike `rememberLocked` which does. BRAND-2's core premise (that `brandPartnerStatus` has a SWR `:stale` copy) is therefore factually wrong and must be dropped.

The `BrandStatus` enum uses `'onboarding'` and `'ready_for_affiliates'` as actual values, but a legacy `fromLegacy()` mapping translates `'building'` → `Onboarding`. The dispatch path uses `->value` directly, so `'building'` is never the dispatched string — BRAND-1 is a real and confirmed bug.
`─────────────────────────────────────────────────`

# Brand Status Notifications Audit — 2026-05-20

**Branch:** development
**Lens:** brand status recent changes
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Enums/BrandStatus.php
- app/Observers/Core/BrandProfileObserver.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Notifications/SendBrandStatusNotificationJob.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Cache/CacheLockService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#BRAND-1** · P1 — SendBrandStatusNotificationJob match arms use legacy string `'building'` — Onboarding transitions send wrong notification
    - **Where:** app/Jobs/Notifications/SendBrandStatusNotificationJob.php:88-104
    - **Affects:** Every affiliate connected to a brand that transitions to `BrandStatus::Onboarding` — they receive "Brand program now active" instead of a paused/inactive message.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `'building'` match arm with `BrandStatus::Onboarding->value` (i.e. `'onboarding'`).
        - Replace the `default` arm with an explicit `BrandStatus::ReadyForAffiliates->value` (`'ready_for_affiliates'`) arm so the "live" notification is intentional rather than a catch-all.
        - Add a `default` arm that logs `Log::warning(...)` with `$this->brandStatus` and skips publishing — any future enum addition is then loud, not silently wrong.
        - Update the `$brandStatus` constructor docblock comment from `'live' | 'building' | 'systems_down'` to the actual enum values `'ready_for_affiliates' | 'onboarding' | 'systems_down'`.
    - **Technical:** `BrandStatus::Onboarding->value` resolves to `'onboarding'` and `BrandStatus::ReadyForAffiliates->value` resolves to `'ready_for_affiliates'` (confirmed in `app/Enums/BrandStatus.php`). The enum does carry a `fromLegacy()` helper where `'building'` maps back to `Onboarding`, but that helper is not used on the dispatch path — `BrandProfileObserver` fans out using raw `->value` strings from the enum. The `match` in `SendBrandStatusNotificationJob` therefore never matches `'building'`, causing every `Onboarding` transition to fall into the `default` arm and emit "Brand program now active." `SystemsDown` (`'systems_down'`) is the only arm that correctly matches. The constructor docblock perpetuates the `'building'` fiction and gives future developers false confidence the mapping is correct.
    - **Plain English:** The notification system works like a form-letter printer: you give it a status code and it picks the right template. When a brand pauses their affiliate program, the code sent is "onboarding" — but the template-picker is looking for an old spelling, "building", which is never sent. It falls through to the default template and tells every affiliate "the program is now live!" when the brand is actually stepping back. Affiliates see the opposite of reality, which erodes trust in the notification system and may cause them to keep trying to generate sales for a program that's on hold.
    - **Evidence:**
        ```php
        // BrandStatus enum — actual values:
        case Onboarding = 'onboarding';
        case ReadyForAffiliates = 'ready_for_affiliates';
        case SystemsDown = 'systems_down';

        // fromLegacy() — legacy mapping (NOT used by the dispatch path):
        'building' => self::Onboarding,
        'live'     => self::ReadyForAffiliates,

        // BrandProfileObserver — dispatches using ->value (raw enum string):
        if (! in_array($newStatus, [
            BrandStatus::ReadyForAffiliates->value,  // 'ready_for_affiliates'
            BrandStatus::Onboarding->value,           // 'onboarding'
            BrandStatus::SystemsDown->value,          // 'systems_down'
        ], true)) {
            return;
        }
        FanOutBrandStatusNotificationJob::dispatch($brandProfessionalId, $newStatus);

        // SendBrandStatusNotificationJob — match checks LEGACY strings, not enum values:
        match ($this->brandStatus) {
            'building'     => $publisher->publish(/* title: 'Brand program paused' */),
            'systems_down' => $publisher->publish(/* title: 'Brand program temporarily unavailable' */),
            default        => $publisher->publish(/* title: 'Brand program now active' — fires for 'onboarding' AND 'ready_for_affiliates' */),
        };
        ```

---

## P2 — Should fix

- [ ] **#BRAND-2** · P2 — SendBrandStatusNotificationJob default arm sends "now active" for unrecognized statuses with no observability
    - **Where:** app/Jobs/Notifications/SendBrandStatusNotificationJob.php:101-111
    - **Affects:** Operations observability — any future `BrandStatus` enum addition or typo silently sends the wrong notification with no log entry, no Nightwatch alert, and no failed-job signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After fixing BRAND-1, the only remaining `default` arm should log `Log::warning('SendBrandStatusNotificationJob: unrecognized brandStatus, skipping', ['brand_status' => $this->brandStatus, ...])` and return without publishing.
        - Do not silently publish any notification for an unrecognized status — fail loud, not silently wrong.
    - **Technical:** Once BRAND-1 is fixed and all three current statuses have explicit arms, the `default` arm becomes a safety net for future enum values. Right now it is also masking the BRAND-1 bug in production. A warning log here surfaces in Nightwatch immediately if a developer adds a fourth status (`suspended`, `archived`, etc.) and forgets to update this job — a change that would otherwise produce weeks of silent mis-delivery. The pattern is consistent with how other jobs in this codebase handle unexpected enum values.
    - **Plain English:** Right now the system's fallback is "when in doubt, tell everyone the brand is live." That's the worst possible default for a status notification — the safest fallback is "say nothing and flag it for the team to investigate." Once the known statuses each have their own template (BRAND-1), the fallback should just raise a flag rather than pick a template at random.
    - **Evidence:**
        ```php
        match ($this->brandStatus) {
            'building' => $publisher->publish(/* ... */),
            'systems_down' => $publisher->publish(/* ... */),
            default => $publisher->publish(
                // No log. No warning. Silently sends 'Brand program now active'
                // for any status that doesn't match the two arms above —
                // including the currently-dispatched 'onboarding' and
                // 'ready_for_affiliates' values.
                professionalId: $this->affiliateProfessionalId,
                frontendType: 'Info',
                category: 'brand_status',
                title: 'Brand program now active',
                body: "{$this->brandName}'s affiliate program is now active.",
                dedupeKey: "brand.live.{$this->brandProfessionalId}.{$this->yearWeek}",
                ctaUrl: '/account/store',
                retentionConfigKey: 'brand_status',
            ),
        };
        ```

---

## P3 — Nice to have

- [ ] **#BRAND-3** · P3 — SendBrandStatusNotificationJob doesn't guard against a brand being soft-deleted between fan-out and leaf execution
    - **Where:** app/Jobs/Notifications/SendBrandStatusNotificationJob.php:58-70
    - **Affects:** Affiliates in rare edge cases — a notification about a brand that no longer exists is sent using the brand name captured at fan-out time.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Professional::find($this->brandProfessionalId)` before the `match` block.
        - If `null`, log a debug entry and return — no notification needed for a deleted brand.
    - **Technical:** `FanOutBrandStatusNotificationJob` validates the brand exists and `isBrand()` before chunking affiliates. However, the brand could be soft-deleted in the window between fan-out dispatch and individual leaf job execution. `$this->brandName` was resolved and serialised into the job payload at fan-out time, so the notification sends with a potentially stale name for an account that no longer exists. The window is narrow (fan-out is fast) and the harm is low (an affiliate gets one extra notification about a brand that just disappeared), but the pattern is inconsistent with the affiliate-side guard that already exists on line 58. A two-line guard closes it cleanly.
    - **Plain English:** When you're sending status updates, you check that the person receiving the message still has an active account — but you don't check that the brand who triggered the message still exists. If a brand deletes their account in the split second between "start sending" and "send your individual message," an affiliate still gets the notification with the brand's old name. It's a tiny gap and unlikely to cause real harm, but it's worth closing to keep the system internally consistent.
    - **Evidence:**
        ```php
        // handle() — validates the affiliate recipient, but not the brand sender:
        $affiliate = Professional::find($this->affiliateProfessionalId);
        if (! $affiliate) {
            Log::warning('SendBrandStatusNotificationJob: affiliate not found, skipping', [
                'affiliate_professional_id' => $this->affiliateProfessionalId,
                'brand_professional_id' => $this->brandProfessionalId,
            ]);
            return;
        }

        // No equivalent check for $this->brandProfessionalId.
        // $this->brandName was resolved at fan-out time and may be stale:
        match ($this->brandStatus) {
            'building' => $publisher->publish(
                body: "{$this->brandName}'s affiliate program is no longer active.",
                // ...
            ),
            // ...
        };
        ```
