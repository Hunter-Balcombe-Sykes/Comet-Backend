`★ Insight ─────────────────────────────────────`
- The `theme_id` on `BrandStoreSettings` uses integer values 1–5 (validated with `'in:1,2,3,4,5'`), making it a numeric preset slot — not a UUID FK to `site.themes`. DeepSeek's premise was wrong; the column is live. This is a key example of why adjudication needs source cross-checking rather than assumption.
- The `runForBlockForeignKey` shim is called from 5+ distinct call sites (3 analytics controller methods, 1 cache service, 1 public analytics controller) — the blast radius of cleaning it up is wider than BLOT-1's single-model description suggested.
`─────────────────────────────────────────────────`

# Dead Code & Schema Bloat Audit — 2026-05-08

**Branch:** development
**Lens:** Unused columns, dead code, and unreferenced bloat in schema and models
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Models/Analytics/LinkClick.php
- app/Models/Analytics/CartEvent.php
- app/Models/Analytics/LeadSubmission.php
- app/Models/Analytics/SiteVisit.php
- app/Models/BaseModel.php
- app/Models/Billing/Plan.php
- app/Models/Billing/Subscription.php
- app/Models/Billing/WebhookEvent.php
- app/Models/Commerce/AffiliateProductSelection.php
- app/Models/Commerce/BrandAffiliateRollup.php
- app/Models/Commerce/Order.php
- app/Models/Commerce/OrderEvent.php
- app/Models/Commerce/OrderItem.php
- app/Models/Core/Gdpr/DataExportAudit.php
- app/Models/Core/Gdpr/GdprRequest.php
- app/Models/Core/MediaVariant.php
- app/Models/Core/Notifications/EmailSubscription.php
- app/Models/Core/Notifications/Notification.php
- app/Models/Core/Notifications/NotificationEmailPolicy.php
- app/Models/Core/Notifications/NotificationEmailPreference.php
- app/Models/Core/Notifications/NotificationReceipt.php
- app/Models/Core/Professional/BrandAffiliateInvite.php
- app/Models/Core/Professional/BrandPartnerLink.php
- app/Models/Core/Professional/BrandPartnerLinkEvent.php
- app/Models/Core/Professional/BrandProfile.php
- app/Models/Core/Professional/Customer.php
- app/Models/Core/Professional/Professional.php
- app/Models/Core/Professional/ProfessionalConfirmationPreference.php
- app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php
- app/Models/Core/Professional/ProfessionalIntegration.php
- app/Models/Core/Professional/Service.php
- app/Models/Core/Professional/ServiceCategory.php
- app/Models/Core/Professional/WalletCurrencySwitchAudit.php
- app/Models/Core/Site/Block.php
- app/Models/Core/Site/Enquiry.php
- app/Models/Core/Site/Site.php
- app/Models/Core/Site/SiteMedia.php
- app/Models/Core/Site/SiteSubdomainAlias.php
- app/Models/Core/Site/Theme.php
- app/Models/Core/Staff/PartnaStaff.php
- app/Models/Core/Waitlist/WaitlistSignup.php
- app/Models/Retail/BrandCommissionTopup.php
- app/Models/Retail/BrandStoreSettings.php
- app/Models/Retail/BrandTeamMembership.php
- app/Models/Retail/CommissionMovement.php
- app/Models/Retail/CommissionPayout.php
- app/Models/Retail/CommissionPayoutItem.php
- app/Models/Views/AllSiteData.php
- app/Models/Views/PublicSitePayload.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 1 complete ✅
- P3 Low: 2 of 2 complete ✅

---

## P2 — Should fix

- [x] **#BLOT-1** · P2 — LinkClick carries a live column-rename shim across 5+ production call sites
    - **Where:** app/Models/Analytics/LinkClick.php:49–122; also Block.php:61, ProfessionalAnalyticsController.php:299/329/547, AnalyticsCacheService.php:53, PublicSite/AnalyticsController.php:146, StaffAnalyticsController.php:121
    - **Affects:** Every analytics read path that counts or groups link clicks — the `information_schema` introspection fires once per PHP-FPM worker boot (not cached across workers) and the four-method shim is spread through six files, making the actual column name opaque to anyone reading any single call site.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Confirm the live column name in both dev and prod Supabase projects: `SELECT column_name FROM information_schema.columns WHERE table_schema = 'analytics' AND table_name = 'link_clicks' AND column_name IN ('block_id', 'link_block_id');`
        - Once the column name is settled, delete `resolveBlockForeignKeyColumn()`, `blockForeignKeyCandidates()`, `runForBlockForeignKey()`, and `isUndefinedColumnException()` from `LinkClick.php` along with the two static properties.
        - Simplify `LinkClick::block()` to `return $this->belongsTo(Block::class, 'block_id');` (or `link_block_id`, whichever is live).
        - Update `Block::clicks()` to hardcode the same key: `$this->hasMany(LinkClick::class, 'block_id')`.
        - Replace the six `runForBlockForeignKey(fn($col) => ...)` call sites in the analytics controllers and cache service with direct queries using the now-known column name.
        - If the rename migration hasn't been pushed to both Supabase environments yet, push it first, then do this cleanup in the same PR.
    - **Technical:** The shim was designed to survive a rolling column rename (`link_block_id` → `block_id`) across deploys without downtime. `resolveBlockForeignKeyColumn()` queries `information_schema.columns` at first use and caches the result in two `private static` properties — but PHP-FPM `static` properties reset per worker, not per deployment. Under typical FPM settings (32 workers), this fires up to 32 times per deploy rather than once globally. The six `runForBlockForeignKey()` call sites add a try/catch retry loop per query, and `Block::clicks()` calls `resolveBlockForeignKeyColumn()` at relationship-definition time, meaning every eager-load of `Block` with `clicks` re-enters the resolution path. None of this is wrong while the migration is in flight, but it becomes dead complexity the moment both environments agree on the column name — and dead complexity with runtime overhead at that.
    - **Plain English:** Imagine a road crew that put up two alternate detour signs during construction and programmed the GPS to check a database every trip to decide which road is open. When construction ends and one road is permanently closed, you'd expect to remove the database check and leave just one sign up. That's what this fix is: the construction is done (or should be), so tear down the detour signs and let traffic flow the direct route.
    - **Evidence:**
        ```php
        private static bool $blockForeignKeyResolved = false;
        private static ?string $blockForeignKeyColumn = null;

        public static function resolveBlockForeignKeyColumn(): ?string
        {
            if (self::$blockForeignKeyResolved) {
                return self::$blockForeignKeyColumn;
            }

            self::$blockForeignKeyResolved = true;

            try {
                $columns = DB::table('information_schema.columns')
                    ->where('table_schema', 'analytics')
                    ->where('table_name', 'link_clicks')
                    ->whereIn('column_name', ['block_id', 'link_block_id'])
                    ->pluck('column_name')
                    ->all();
            } catch (\Throwable) {
                $columns = [];
            }
            // ...
        }

        public static function isUndefinedColumnException(QueryException $exception): bool
        {
            $sqlState = $exception->errorInfo[0] ?? null;

            return $sqlState === '42703';
        }
        ```
        And in Block.php:
        ```php
        public function clicks(): HasMany
        {
            return $this->hasMany(LinkClick::class, LinkClick::resolveBlockForeignKeyColumn() ?? 'link_block_id');
        }
        ```

---

## P3 — Nice to have

- [x] **#BLOT-2** · P3 — BrandStoreSettings class comment claims the model is "simplified" but contradicts its own fillable list
    - **Where:** app/Models/Retail/BrandStoreSettings.php:8–11 (docblock) vs :21–28 (`$fillable`)
    - **Affects:** Developers onboarding to the Shopify wizard code path — the stale comment misdirects anyone trying to understand what lives in this table before they read the full file.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update the class-level comment to accurately describe all active columns: `default_commission_rate`, `payout_hold_days`, `theme_id` (numeric preset 1–5), `oxygen_storefront_id`, `hydrogen_install_confirmed`, `oxygen_deployment_token` (encrypted wizard state).
        - Cross-check whether `oxygen_deployment_token` + `oxygen_storefront_id` are still required given recent Cloudflare routing commits (`2a752fa`, `7aa5d40`) that dropped the custom-domain/Oxygen path; if those commits made the Oxygen setup defunct, these two columns are candidates for a future drop migration.
    - **Technical:** The comment reads "V2: Simplified. Now only holds `default_commission_rate` and `payout_hold_days`." but `$fillable` has six entries and `$casts` includes an encrypted `oxygen_deployment_token`. Active read paths in `BrandStoreSettingsController`, `EmbeddedSetupController`, `HydrogenBrandConfigController`, and `BrandStoreSettingsResource` all reference `theme_id` (validated as `'in:1,2,3,4,5'` — a numeric preset, not a UUID FK to `site.themes`). The docblock comment was accurate at some past point in the refactor but was not updated when the Shopify wizard fields were added. Stale comments cause the same maintenance debt as stale code: a future developer will trust the comment and miss fields that exist, leading to incorrect assumptions in new features built on top of this model.
    - **Plain English:** The label on this filing cabinet says "contains only two folders" but when you open it there are six. Nobody got hurt yet, but the next person who needs something from it will waste time because they stopped looking after two folders. It's a five-minute fix — update the label.
    - **Evidence:**
        ```php
        // V2: Simplified. Now only holds default_commission_rate and payout_hold_days. Per-product overrides moved to Shopify metafields.
        class BrandStoreSettings extends BaseModel
        ```
        ```php
        protected $fillable = [
            'professional_id',
            'default_commission_rate',
            'payout_hold_days',
            'theme_id',
            'oxygen_storefront_id',
            'hydrogen_install_confirmed',
        ];

        protected $casts = [
            'default_commission_rate' => 'decimal:2',
            'payout_hold_days' => 'integer',
            'theme_id' => 'integer',
            // Encrypted at-rest using APP_KEY (AES-256-CBC via Laravel's encrypter)
            'oxygen_deployment_token' => 'encrypted',
        ];
        ```

- [x] **#BLOT-3** · P3 — Block model has a `@var false|mixed` docblock on its `$table` string property
    - **Where:** app/Models/Core/Site/Block.php:17–19
    - **Affects:** Static analysis tools (PHPStan/Psalm) and any IDE using the type hint — `false|mixed` collapses to `mixed`, effectively suppressing type narrowing on a property that should always be `string`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `@var false|mixed` with `@var string` on the `$table` property, or remove the docblock entirely since `$table` is already declared in the Eloquent parent as `protected string $table`.
        - Run `vendor/bin/pint --dirty` after the change.
    - **Technical:** `@var false|mixed` is logically equivalent to `@var mixed` (since `mixed` is the top type that includes all other types). On a property that Eloquent treats as a `string` table name, this annotation is both semantically wrong and counterproductive: it tells PHPStan the property might be `false` or any other type, preventing the type-narrowing that would catch accidental `null` assignments or mismatched method calls on the table name. The annotation appears to be an IDE autocomplete artifact — likely generated when creating the property stub and never corrected.
    - **Plain English:** Someone accidentally put a sticky note on a filing cabinet that says "this drawer might be empty or contain anything." The drawer is clearly labeled and holds exactly one thing. The note confuses anyone reading it and should just be removed.
    - **Evidence:**
        ```php
        /**
         * @var false|mixed
         */
        protected $table = 'site.blocks';
        ```
