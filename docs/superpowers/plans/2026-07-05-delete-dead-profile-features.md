# Delete Dead Profile Features Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove four confirmed-dead profile-content features end-to-end — the bio/hero/CTA cluster, credentials + experience, the `countdown` block type, and the `sitepage_analytics` toggle — from PHP, config, tests, the two live Postgres views, and the schema.

**Architecture:** Delete in dependency order. First carve out the one live feature (`public_contact`) that currently piggybacks on the bio engine. Then strip the dead code and config in small, independently-committable units. The schema migration (columns, tables, block-type CHECK, views) lands **last**, applied to Supabase dev only after all code is deployed — because live views and code reference these columns until then.

**Tech Stack:** PHP 8.2 / Laravel 12, Pest (SQLite in-memory tests), Supabase Postgres (raw SQL migrations under `supabase/migrations/`).

## Global Constraints

- **Supabase migrations only.** Never create a Laravel migration file — the `guard:no-laravel-migrations` composer check rejects them. All DDL goes in `supabase/migrations/` as raw SQL.
- **Deploy ordering is non-negotiable.** Tasks 1–10 (PHP + config + tests) merge and deploy to the dev env FIRST. The DROP migration (Task 11) is applied via `supabase db push` against dev ref `glncumufgaqcmqhzwrxm` **only after** that code is live. Dropping a column while the live `public_site_payload` view or deployed code still selects it will 500 the public read path.
- **Surgical edits, never wholesale.** `Site::PROMOTED_SETTINGS_KEYS`, `Site::$fillable`, `config/partna.php` `block_types.sections` + `allowed_sections`, and `AnalyticsQueryService::sectionTitle()` each contain **live** siblings. Remove only the named dead keys.
- **Wire-contract changes (frontend coordination required):**
  - `GET /api/public/profiles/{handle}`: `profile.bio` object is **removed**; `profile.publicContact` (`{email, phone}`) is **added** (Task 3). If any skeleton read `bio.publicContact`, it must read `publicContact`.
  - `about` key is **removed** from `UserDashboardResource` and `UserStaffResource`. The signup "about" editor (credentials/experience) in the frontend must be removed in the same release.
- **Tests run on SQLite; CHECK constraints and the two views do not exist there.** Several test base classes hand-build SQLite stand-in `core.user_credentials` / `core.user_experience` tables and a `bio` column — these scaffolds must be trimmed alongside the feature (called out per task).
- **`composer test` after every task.** A task is done only when the suite is green (minus intentionally-deleted tests) and committed.

---

## File Structure

New code (Task 3 only — the single net-new addition, preserving a live feature):
- `app/Services/PublicSite/SitepageDataResolverService.php` — add `getPublicContact()`
- `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` — add `buildPublicContact()`, emit `publicContact`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php` — emit `publicContact`

New migration (Task 11):
- `supabase/migrations/20260705120000_drop_dead_profile_features.sql`

Everything else is deletion/trimming of existing files (enumerated per task).

---

## Phase 1 — `countdown` (isolated, no coupling)

### Task 1: Delete the countdown block type

**Files:**
- Delete: `app/Services/User/Visibility/Rules/CountdownVisibility.php`
- Modify: `app/Providers/SectionVisibilityServiceProvider.php` (remove `use ...CountdownVisibility;` line 7; remove `$r->register(new CountdownVisibility);` line 38)
- Modify: `app/Http/Requests/Api/User/Site/UpsertSectionBlockRequest.php` (remove dispatch `if ($type === 'countdown') {...}` lines 45–47; remove `countdownRules()` lines 106–149; remove sanitize dispatch `if (... === 'countdown') { $this->sanitizeCountdownSettings(); }` lines 186–188; remove `sanitizeCountdownSettings()` lines 198–232)
- Modify: `config/partna.php` (remove `'countdown'` from `$blockTypes['sections']` line 20; remove `'countdown'` from `allowed_sections` line 845)
- Modify: `app/Services/Analytics/AnalyticsQueryService.php` (remove `'countdown' => 'Countdown',` line 470 — safe, the `match` has a `default =>` fallback)
- Delete: `tests/Feature/Countdown/CountdownSectionBehaviorTest.php`, `tests/Feature/Countdown/CountdownSectionConfigTest.php`, `tests/Feature/Countdown/CountdownSectionValidationTest.php`
- Test (update): `tests/Feature/Site/BlockTypesConfigTest.php`, `tests/Feature/Site/SectionVisibilityRegistryTest.php`, `tests/Feature/Site/BatchCheckQueryCountTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `config('partna.block_types.sections')` and `allowed_sections` no longer contain `'countdown'`. `SectionVisibilityRegistry::get('countdown')` now returns `null`.

- [ ] **Step 1: Remove the source + config + analytics label** per the Files list above.

- [ ] **Step 2: Update the three shared-list tests**
  - `BlockTypesConfigTest.php`: remove `'countdown'` from the expected canonical `sections` array (the test asserts exact-equality with `config('partna.block_types.sections')`).
  - `SectionVisibilityRegistryTest.php`: remove `'countdown'` from the "has a rule" assertion list (line ~10).
  - `BatchCheckQueryCountTest.php`: the batch-check asserts a fixed count of requirement-bearing types; drop `countdown` from that set (it contributed 0 EXISTS subqueries but was counted as one of the checked types — recount accordingly, lines 20–24 / 56–58).

- [ ] **Step 3: Run tests**

Run: `composer test`
Expected: PASS (the three `Countdown/` files are gone; shared-list tests reflect the trimmed set).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor(sections): remove dead countdown block type"
```

---

## Phase 2 — `sitepage_analytics` (isolated, trivial)

### Task 2: Delete the sitepage_analytics toggle

**Files:**
- Modify: `config/partna.php` (remove `'sitepage_analytics'` from `$blockTypes['sections']` line 19; remove from `allowed_sections` line 845)
- Modify: `app/Providers/SectionVisibilityServiceProvider.php` (remove `sitepage_analytics` from the "no rule, default visible" comment, line 21)
- Modify: `app/Services/Analytics/AnalyticsQueryService.php` (remove `'sitepage_analytics' => 'Sitepage Analytics',` line 471 — safe, `default =>` fallback exists)
- Test (update): `tests/Feature/Site/BlockTypesConfigTest.php`, `tests/Feature/Site/SectionVisibilityRegistryTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `'sitepage_analytics'` absent from all config section lists; `SectionVisibilityRegistry::get('sitepage_analytics')` still returns `null` (unchanged — it never had a rule).

- [ ] **Step 1: Remove config + comment + analytics label** per the Files list.

- [ ] **Step 2: Update tests**
  - `BlockTypesConfigTest.php`: remove `'sitepage_analytics'` from the expected `sections` array (line ~7).
  - `SectionVisibilityRegistryTest.php`: remove `'sitepage_analytics'` from the "no rule → `toBeNull()`" loop list (line 21).

- [ ] **Step 3: Run tests** — `composer test` → PASS.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor(sections): remove unbuilt sitepage_analytics toggle"
```

---

## Phase 3 — Preserve `public_contact` before deleting the bio engine

### Task 3: Extract `public_contact` into its own top-level payload key

**Why first:** `public_contact` (a live feature with its own `public_contact` block type + `PublicContactVisibility` rule) is currently computed **and gated** inside `getBio()` via `sectionEnvelope($sections, 'bio', ...)`. Deleting the bio engine without this would remove public contact from the payload entirely. This task gives it an independent home gated on the already-existing `public_contact` section; the bio engine is untouched here (removed in Task 6), so public contact is never dropped.

**Files:**
- Modify: `app/Services/PublicSite/SitepageDataResolverService.php` (add `getPublicContact()`)
- Modify: `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` (add `buildPublicContact()`; emit `publicContact` in `build()`)
- Modify: `app/Http/Resources/PublicSite/IndividualProfileResource.php` (emit `publicContact`)
- Test: `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php` (add a `publicContact` case)

**Interfaces:**
- Consumes: existing `SitepageDataResolverService::sectionEnvelope()`, `PublicContactVisibility` (block type `public_contact`), `User::public_contact_email` / `public_contact_number`.
- Produces: `SitepageDataResolverService::getPublicContact(User $pro, Collection $sections): array` (envelope shape `{state, data: {email, phone}|null, block_id?}`); builder emits top-level `publicContact => {email, phone}|null`; resource emits `profile.publicContact`.

- [ ] **Step 1: Write the failing test** in `IndividualProfileControllerTest.php`

```php
it('emits publicContact as its own top-level key gated by the public_contact section', function () {
    $pro = /* seed a published professional */;
    $pro->update(['public_contact_email' => 'hi@example.com', 'public_contact_number' => null]);
    // publish a `public_contact` section block (is_active + is_enabled true)

    $res = $this->getJson("/api/public/profiles/{$pro->handle}")->assertOk();
    $res->assertJsonPath('data.profile.publicContact.email', 'hi@example.com');
    $res->assertJsonPath('data.profile.publicContact.phone', null);
});
```

- [ ] **Step 2: Run it, confirm it fails** — `composer test -- --filter='publicContact as its own top-level key'` → FAIL (`publicContact` not present).

- [ ] **Step 3: Add `getPublicContact()` to `SitepageDataResolverService`** (place next to `getBio`)

```php
/**
 * Public-contact engine — {email, phone} | null.
 *
 * Extracted from getBio() so public contact survives the bio-engine
 * removal. Gated on the `public_contact` section (its own block type +
 * PublicContactVisibility rule), NOT on `bio`.
 *
 * @param  Collection<string, Block>  $sections
 * @return array{state: string, data: array{email: string|null, phone: string|null}|null, block_id?: string}
 */
public function getPublicContact(User $pro, Collection $sections): array
{
    return $this->sectionEnvelope($sections, 'public_contact', function () use ($pro): array {
        return [
            'email' => trim_or_null($pro->public_contact_email ?? null),
            'phone' => trim_or_null($pro->public_contact_number ?? null),
        ];
    });
}
```

- [ ] **Step 4: Add `buildPublicContact()` to `IndividualProfilePayloadBuilder` and emit it in `build()`**

Add the method:
```php
/**
 * Public-contact engine — {email, phone} | null. Own top-level wire key;
 * no longer nested under the (removed) bio engine.
 *
 * @param  Collection<string, Block>  $sections
 * @return array{email: string|null, phone: string|null}|null
 */
private function buildPublicContact(User $pro, Collection $sections): ?array
{
    $data = $this->resolver->getPublicContact($pro, $sections)['data'] ?? null;
    if (! is_array($data)) {
        return null;
    }

    return [
        'email' => $data['email'] ?? null,
        'phone' => $data['phone'] ?? null,
    ];
}
```
In `build()`, add to the engine-outputs array (leave the existing `'bio' => $this->buildBio(...)` line in place for now — it's removed in Task 6):
```php
'publicContact' => $this->buildPublicContact($pro, $sections),
```

- [ ] **Step 5: Emit it in `IndividualProfileResource`** — inside the `profile` array, add:
```php
'publicContact' => $this->sections['publicContact'] ?? null,
```

- [ ] **Step 6: Run the test** — `composer test -- --filter='publicContact as its own top-level key'` → PASS. Then full `composer test` → PASS (bio still emits its own `publicContact` internally; harmless overlap until Task 6).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(publicsite): give public_contact its own payload key (pre-bio-removal)"
```

---

## Phase 4 — Delete bio + credentials + experience (coupled)

### Task 4: Remove the credentials/experience write + validation path

**Files:**
- Delete: `app/Services/User/SyncUserAboutService.php`
- Delete: `app/Http/Requests/Concerns/ValidatesUserAbout.php`
- Modify: `app/Http/Requests/Api/User/UpdateUserRequest.php` (remove `use ValidatesUserAbout;` line 15; the `], $this->aboutRules());` merge line 51; `$this->validateExperienceDateOrder($v);` line 57; `$this->normalizeAboutPayload();` line 63)
- Modify: `app/Http/Requests/Api/Staff/UserSite/StaffUpdateUserRequest.php` (same four call sites: lines 13, 43, 49, 55)
- Modify: `app/Http/Controllers/Api/User/Account/UserSelfController.php` (remove `SyncUserAboutService $aboutSync` ctor injection lines 23–25; remove the `about` extraction + `$this->aboutSync->sync(...)` block lines 68–92 — keep the rest of `update()`)
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php` (remove the mirrored injection + about-sync block lines 188–215)
- Delete: `tests/Feature/User/UserAboutWritePathTest.php`, `tests/Feature/Validation/UserAboutValidationTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `PATCH /api/me` and the staff user-update no longer accept an `about` key; nothing writes `core.user_credentials` / `core.user_experience`.

- [ ] **Step 1: Delete the service + trait; strip the four request call sites and both controller blocks.**
- [ ] **Step 2: Delete the two write-path test files.**
- [ ] **Step 3: Run tests** — `composer test` → PASS.
- [ ] **Step 4: Commit** — `git commit -m "refactor(user): remove credentials/experience write path"`

### Task 5: Remove the bio-block → `users.bio` sync

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php` (remove the `if ($blockType === 'bio' && ...) { $pro->bio = ...; $pro->save(); }` block lines 215–224; update the class comment line 18)
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffSectionManagementController.php` (remove the mirrored block lines 87–96; comment line 18)
- Modify: `app/Http/Requests/Api/User/Site/UpsertSectionBlockRequest.php` (remove `'bio'` from the `in_array($type, ['bio', 'promotional_text'], true)` MaxWords branch lines 21–23 — leave any surviving type in the array intact)

**Interfaces:**
- Produces: publishing a section block no longer writes `users.bio`.

- [ ] **Step 1: Remove both sync blocks + the MaxWords `'bio'` entry.**
- [ ] **Step 2: Run tests** — `composer test`. If a section-block test seeded a `bio` block to assert the sync, remove that assertion (see Task 10 for the fixture swaps).
- [ ] **Step 3: Commit** — `git commit -m "refactor(sections): drop bio-block to users.bio sync"`

### Task 6: Remove bio/credentials/experience from the public read path

**Files:**
- Modify: `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` (remove `buildBio()` lines 96–127; remove the `'bio' => $this->buildBio(...)` line from `build()`)
- Modify: `app/Services/PublicSite/SitepageDataResolverService.php` (remove `getBio()` lines ~374–410; remove `normaliseCredential()` line ~458; remove `normaliseExperience()` line ~480; remove the `// ── Bio + credentials + experience ──` section header)
- Modify: `app/Http/Resources/PublicSite/IndividualProfileResource.php` (remove the `'bio' => $this->sections['bio'] ?? null,` line 99; remove `bio` from the docblock lines 24–26, 52)
- Modify: `app/Models/Core/User/User.php` (remove `credentials()` + `experience()` relations lines ~197–215; remove `aboutPayload()` lines ~216–242)
- Modify: `app/Http/Resources/UserDashboardResource.php` (remove the `'about' => $this->aboutPayload(),` key, line 28)
- Modify: `app/Http/Resources/UserStaffResource.php` (remove the `'about' => ...aboutPayload()` key, line 23)
- Modify: `app/Services/User/DataExport/DataExportPayloadBuilder.php` (remove the `aboutPayload()` usage line 200 — replace the exported `about` section with nothing, or drop the key; keep the surrounding export intact)
- Test (update): `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php` (delete the bio-engine cases lines 625–682 and the `bio` null-assertions at 151, 344); delete `tests/Feature/User/UserAboutTest.php`; `tests/Feature/Staff/StaffAdminNotesTest.php` (drop the `core.user_credentials`/`user_experience` scaffolding + any `about` assertion, lines 49–63)

**Interfaces:**
- Consumes: `publicContact` from Task 3 (already emitted).
- Produces: `profile.bio` gone from the public payload; `about` key gone from dashboard/staff user resources; `User::aboutPayload()` / `credentials()` / `experience()` no longer exist.

- [ ] **Step 1: Remove the builder/resolver/resource bio+cred+exp read code.**
- [ ] **Step 2: Remove the `User` relations + `aboutPayload()`, and the `about` key from both user resources + the data export.**
- [ ] **Step 3: Update/delete the listed tests.**
- [ ] **Step 4: Run tests** — `composer test` → PASS. Confirm `profile.publicContact` still present (Task 3 case) and `profile.bio` absent.
- [ ] **Step 5: Commit** — `git commit -m "refactor(publicsite): remove bio/credentials/experience read path"`

### Task 7: Delete credentials/experience models, visibility rules, policy bindings

**Files:**
- Delete: `app/Models/Core/User/UserCredential.php`, `app/Models/Core/User/UserExperience.php`
- Delete: `app/Services/User/Visibility/Rules/CredentialsVisibility.php`, `app/Services/User/Visibility/Rules/ExperienceVisibility.php`
- Modify: `app/Providers/SectionVisibilityServiceProvider.php` (remove the two `use` imports lines 8, 10; remove `$r->register(new CredentialsVisibility);` + `$r->register(new ExperienceVisibility);` lines 34–35)
- Modify: `app/Providers/AppServiceProvider.php` (remove `Gate::policy(UserCredential::class, ...)` + `Gate::policy(UserExperience::class, ...)` lines ~160–162, plus their `use` imports)
- Test (update): `tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php` (delete cred/exp cases lines 190–243); `tests/Feature/FeatureFlags/SectionVisibilityTestCase.php` (remove the SQLite stand-in `user_credentials`/`user_experience` table creation lines 119–140); `tests/Feature/Site/SectionVisibilityRegistryTest.php` (remove cred/exp from the "has a rule" list); `tests/Feature/Site/BatchCheckQueryCountTest.php` (drop cred/exp from the checked-types count, lines 20–24, 56–58); `tests/Feature/User/DataExport/DataExportTestCase.php` (remove cred/exp scaffolding lines 499–524); `tests/Feature/Staff/StaffAdminNotesTest.php` (already handled Task 6). `PolicyCoverageTest` auto-passes once the models are gone.

**Interfaces:**
- Produces: `UserCredential` / `UserExperience` classes no longer exist; registry has no `credentials`/`experience` rule.

- [ ] **Step 1: Delete the 4 classes; remove registrations + policy bindings.**
- [ ] **Step 2: Trim the listed test scaffolds + assertions.**
- [ ] **Step 3: Run tests** — `composer test` → PASS.
- [ ] **Step 4: Commit** — `git commit -m "refactor(user): delete credentials/experience models + visibility rules"`

### Task 8: Remove `bio` from user resources + hero/CTA from site

**Files:**
- Modify: `app/Models/Core/User/User.php` (remove `'bio'` from `$fillable` line 53)
- Modify: `app/Http/Resources/UserDashboardResource.php` (remove `'bio' => $this->bio,` line 26), `app/Http/Resources/UserStaffResource.php` (line 21), `app/Http/Resources/UserPublicResource.php` (line 20), `app/Http/Resources/Staff/StaffSiteResource.php` (remove `'bio'` from the `professional` block, line 36)
- Modify: `app/Http/Requests/Api/User/UpdateUserRequest.php` (remove the `'bio' => [...]` rule line 22 and `$this->cleanText(['bio']);` line 66), `app/Http/Requests/Api/Staff/UserSite/StaffUpdateUserRequest.php` (rule line 22, cleanText line 58)
- Modify: `app/Models/Core/Site/Site.php` (remove the 5 keys `hero_title, hero_subtitle, primary_button_text, primary_button_url, bio_text` from `PROMOTED_SETTINGS_KEYS` lines 43–54 **and** from `$fillable` lines 71–75 — keep `show_branding`, `charlie_enabled`, `services_auto_sync_enabled`, `booking_mode`, `manual_booking_url`)
- Modify: `app/Http/Requests/Api/User/Site/UpdateSiteRequest.php` (remove hero/bio entries from the cleanString loop line 34; remove the 5 `settings.hero_*` / `settings.bio_text` rules lines 57–61), `app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php` (loop line 30, rules lines 77–81)
- Modify: `app/Http/Resources/SiteResource.php` (remove the 5 hero/bio keys from the `$promoted` re-merge array lines 26–30)
- Test (update): `tests/Feature/Resources/UserPublicResourceTest.php` (remove the `bio` assertion line 15); `tests/Unit/Resources/SiteResourceTest.php` (remove the hero_title re-merge assertion lines 91–101); delete `tests/Feature/Services/UpdateSiteSettingsPromotionTest.php` (whole file is the 5-column round-trip) OR trim it to the 5 surviving promoted keys — prefer trim; `tests/Feature/Services/UpdateSiteActionTest.php` (swap the `hero_title` hoist assertion lines 194–216 to a surviving promoted key, e.g. `booking_mode`); `tests/Feature/Api/User/SiteManagement/UpdateSiteValidationTest.php` (remove the `settings.hero_title` case line 105); `tests/Feature/Api/Staff/UserSiteManagement/StaffUpdateSiteValidationTest.php` (line 82); `tests/Feature/Api/Staff/StaffSiteControllerTest.php` (remove `bio` + `hero_title` round-trip assertions lines 36, 57, 62, 82, and the SQLite `bio` column if declared there)

**Interfaces:**
- Produces: user resources no longer expose `bio`; `Site` no longer promotes/accepts the 5 hero/CTA keys.

- [ ] **Step 1: Remove `bio` from the 4 user resources + `User::$fillable` + both user-update requests.**
- [ ] **Step 2: Remove the 5 hero/CTA keys from `Site` (both arrays), both site-update requests, and `SiteResource`.**
- [ ] **Step 3: Trim/delete the listed tests.**
- [ ] **Step 4: Run tests** — `composer test` → PASS.
- [ ] **Step 5: Commit** — `git commit -m "refactor(site): remove bio + hero/CTA promoted columns from API surface"`

### Task 9: Remove `bio` from observer / bootstrap / cache / moderation / deletion

**Files:**
- Modify: `app/Observers/User/UserObserver.php` (remove `'bio'` from `PUBLIC_PROFILE_USER_FIELDS` line 43)
- Modify: `app/Services/User/UserBootstrapService.php` (remove `'bio' => null,` signup default line 59)
- Modify: `app/Services/Cache/UserCacheService.php` (remove `'bio' => $pro->bio,` line 98)
- Modify: `app/Services/User/AccountDeletionService.php` (remove `'bio' => null,` from `pseudonymiseAccountPii` line 266; remove `'bio'` from the evidence-redaction key list line 726)
- Modify: `app/Services/Moderation/EvidenceSnapshotService.php` (remove `'bio' => $site->user?->bio ?? null,` line 65)
- Modify: `app/Services/Analytics/AnalyticsQueryService.php` (remove the `'bio' => 'About',` line 462, `'experience' => 'Experience',` line 465, `'credentials' => 'Credentials',` line 466 — keep the unrelated `'about' => 'About',` line 457)
- Test (update): `tests/Feature/User/UserObserverHandleChangeTest.php` (remove `'bio'` from the parametrised field list lines 132, 138–140); `tests/Feature/User/AccountDeletion/ConfirmDeletionTest.php` (remove the `bio` pseudonymise assertion lines 231–246); `tests/Feature/User/AccountDeletion/AccountDeletionTestCase.php` (remove the SQLite `bio TEXT` column line 63); `tests/Feature/Account/AccountDeletionPurgeEvidencePiiTest.php` (remove `bio` from the evidence-snapshot redaction cases lines 49, 51, 140, 146)

**Interfaces:**
- Produces: no service/observer references `users.bio`; analytics labels for the 3 dead keys fall through to the `default =>` formatter.

- [ ] **Step 1: Remove all `bio` references from the 6 service/observer files + the 3 analytics labels.**
- [ ] **Step 2: Update the 4 listed tests.**
- [ ] **Step 3: Run tests** — `composer test` → PASS.
- [ ] **Step 4: Commit** — `git commit -m "refactor(user): purge users.bio from observer/cache/moderation/deletion"`

### Task 10: Remove the remaining 3 block types from config + swap test fixtures

**Files:**
- Modify: `config/partna.php` (remove `'credentials', 'experience', 'bio'` from `$blockTypes['sections']` line 21; remove the same three from `allowed_sections` line 845 — leave `default_sections` line 846 unchanged, none of the 5 were defaults)
- Test (update): `tests/Feature/Site/BlockTypesConfigTest.php` (final expected `sections` list = the 10 survivors); `tests/Feature/Site/SectionBlockInvalidTypeTest.php` (change the valid-type fixture off `bio`; assert `bio` is now rejected, lines 14–68); `tests/Feature/Site/SectionBlockUpsertSortOrderTest.php` (swap the `bio` fixture block to a surviving type e.g. `newsletter`, lines 115–131); `tests/Feature/Site/SectionReorderTest.php` (swap the `bio` fixture, lines 58–77); `tests/Feature/Observers/BlockAndMediaTouchSiteTest.php` (swap the `bio` fixture, line 68); `tests/Feature/Analytics/TopSectionsExpandedTypesTest.php` (swap the `bio` fixture, lines 20–42)

**Interfaces:**
- Produces: `config('partna.block_types.sections')` = final 10-value survivor list matching the trimmed DB CHECK.

- [ ] **Step 1: Trim the two config arrays to the 10 survivors** (`gallery, services, booking, contacts_collection, barbershop_info, documents, newsletter, contact, public_contact, workplace`).
- [ ] **Step 2: Swap `bio` test fixtures to a surviving type; update `BlockTypesConfigTest` + `SectionBlockInvalidTypeTest`.**
- [ ] **Step 3: Run tests** — `composer test` → PASS.
- [ ] **Step 4: Commit** — `git commit -m "refactor(sections): drop bio/credentials/experience from block-type config"`

> **Deploy gate:** merge Phases 1–4 to `development` and confirm the deploy is live before Task 11. The DROP migration assumes this code is running.

---

## Phase 5 — Schema migration (LAST, applied after code deploy)

### Task 11: Supabase migration — recreate views, trim CHECK, drop columns + tables

**Files:**
- Create: `supabase/migrations/20260705120000_drop_dead_profile_features.sql`

**Pre-flight (do before writing SQL):**
- [ ] Confirm view interdependency: check whether `site.public_site_payload` selects `FROM site.all_site_data`. Drop in reverse-dependency order, recreate in dependency order. (If independent, order is free.)
- [ ] Copy the CURRENT `CREATE VIEW site.all_site_data` and `CREATE VIEW site.public_site_payload` bodies verbatim from `supabase/migrations/20260701200000_strip_site_settings_jsonb_keys.sql`. These are the source of truth for the recreation.

**The migration performs, in this exact order:**

- [ ] **Step 1: Delete rows of the dead block types** (so the CHECK VALIDATE passes)

```sql
BEGIN;
DELETE FROM site.blocks
WHERE block_type IN ('bio', 'credentials', 'experience', 'countdown', 'sitepage_analytics');
```

- [ ] **Step 2: Recreate the two views without the dead references.** `all_site_data` exposes `bio` as a top-level column, so its signature changes → must `DROP VIEW` + `CREATE VIEW` (not `CREATE OR REPLACE`). `public_site_payload` only removes JSONB-internal keys (signature unchanged) but recreate it the same way for clarity.

```sql
DROP VIEW IF EXISTS site.public_site_payload;
DROP VIEW IF EXISTS site.all_site_data;

-- Recreate site.all_site_data: paste the current body from 20260701200000,
-- then delete the `p.bio,` line from the SELECT list (was line 46).
CREATE VIEW site.all_site_data AS
  /* ...current definition, with `p.bio,` removed... */;

-- Recreate site.public_site_payload: paste the current body, then delete
-- from the settings jsonb_build_object: `'hero_title', s.hero_title,`
-- `'hero_subtitle', s.hero_subtitle,` `'primary_button_text', s.primary_button_text,`
-- `'primary_button_url', s.primary_button_url,` `'bio_text', s.bio_text,`
-- (were lines 96–100) and from the professional jsonb_build_object:
-- `'bio', p.bio,` (was line 239). Leave every other column/key identical —
-- StaffSiteResource + SiteCacheService read these views by exact key.
CREATE VIEW site.public_site_payload AS
  /* ...current definition, with the 6 references removed... */;
```

- [ ] **Step 3: Trim the block-type CHECK** to the 10 surviving section types

```sql
ALTER TABLE site.blocks DROP CONSTRAINT blocks_group_type_check;

ALTER TABLE site.blocks ADD CONSTRAINT blocks_group_type_check
    CHECK (
        (block_group = 'links' AND block_type = 'link')
        OR (block_group = 'sections' AND block_type IN (
            'gallery', 'services', 'booking', 'contacts_collection',
            'barbershop_info', 'documents', 'newsletter',
            'contact', 'public_contact', 'workplace'
        ))
    ) NOT VALID;

ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_group_type_check;
```

- [ ] **Step 4: Drop the dead columns**

```sql
ALTER TABLE site.sites
    DROP COLUMN hero_title,
    DROP COLUMN hero_subtitle,
    DROP COLUMN primary_button_text,
    DROP COLUMN primary_button_url,
    DROP COLUMN bio_text;

ALTER TABLE core.users DROP COLUMN bio;
```

- [ ] **Step 5: Drop the child tables** (FKs are `ON DELETE CASCADE`; no view/function depends on them)

```sql
DROP TABLE core.user_credentials;
DROP TABLE core.user_experience;

COMMIT;
```

- [ ] **Step 6: Dry-run then apply to dev**

```bash
supabase db push --dry-run   # review the diff
supabase db push             # apply to dev ref glncumufgaqcmqhzwrxm
```

Expected: migration applies cleanly; `GET /api/public/profiles/{handle}` returns `publicContact`, no `bio`; staff site view + public cache still resolve (views recreated with all other keys intact).

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/20260705120000_drop_dead_profile_features.sql
git commit -m "feat(schema): drop dead bio/credentials/experience columns, tables, and block types"
```

---

## Self-Review

- **Spec coverage:** countdown (Task 1), sitepage_analytics (Task 2), bio-text + hero/CTA columns (Tasks 3/5/6/8/9/11), credentials+experience (Tasks 4/6/7/11), block-type CHECK + views + schema (Task 11). `public_contact` preserved (Task 3). ✅
- **Ordering safety:** `public_contact` extracted (T3) before bio read-path removal (T6); all PHP/config (T1–T10) deployed before the DROP migration (T11); inside T11, rows deleted → views recreated → CHECK trimmed → columns dropped → tables dropped. ✅
- **Live-sibling protection:** `Site::PROMOTED_SETTINGS_KEYS`/`$fillable` keep 5 unrelated keys; `AnalyticsQueryService` keeps `'about'`; `default_sections` untouched; `PublicContactVisibility` + `public_contact` block type untouched. ✅
- **Test scaffolds:** SQLite stand-in `user_credentials`/`user_experience` tables (SectionVisibilityTestCase, DataExportTestCase) and `bio` columns (AccountDeletionTestCase, StaffSiteControllerTest) trimmed in the tasks that remove their features. ✅

## Frontend coordination (out of this repo, must ship together)
- Remove the signup "about" editor (credentials/experience) — the only caller of `lib/about/`.
- Stop reading `profile.bio`; if `bio.publicContact` was consumed, read `profile.publicContact` instead.
- Remove any dashboard read of the `about` key on the user resource, and any `settings.hero_*` / `bio_text` writers (the `overview` Publish handler writes `hero_title`).
