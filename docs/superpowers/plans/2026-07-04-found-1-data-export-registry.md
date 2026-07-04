# FOUND-1: Data-Export Single-Source Section Manifest Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collapse the two independently hand-maintained section enumerations in `DataExportPayloadBuilder` (`build()` and `stream()`) into one ordered manifest, so a new GDPR-exportable section can never be silently added to one entry point and missed in the other.

**Architecture:** Add a private `sectionDescriptors()` method to `DataExportPayloadBuilder` returning an ordered array of `{name, kind, resolve, csv_columns?}` descriptors — one entry per current `stream*()`/scalar section, in the exact current emission order. `stream()` becomes a thin loop over this list (replacing its current 179-line hand-written yield block). `build()` no longer independently enumerates sections at all — it iterates `stream()`'s own output and re-nests it via `Illuminate\Support\Arr::set()`, which treats the descriptor's dotted `name` (e.g. `audit.handle_change_log`) as a nesting path. `DataExportZipWriter::csvNameFor()` loses its two now-redundant `match()` arms (verified: they already produce byte-identical output to the `default` branch) and becomes a single derivation. No new classes, no new service provider, no interface — this is a pure collapse of the existing duplication, chosen over a full class-per-section registry (see rationale below) because sections here are always ALL emitted, in one fixed order, by exactly one caller — there is no dynamic per-key lookup need the way `SectionVisibilityRegistry` has for block-type rules.

**Tech Stack:** PHP 8.2, Laravel 12 (`Illuminate\Support\Arr`), Pest 4.

## Global Constraints

- **Zero behavior change.** `build()`'s return shape (every key, every nesting level) and `stream()`'s yield order must be byte-for-byte identical to today — `DataExportZipWriterStreamingTest` asserts SHA-256 reproducibility of the streamed JSON, and `DataExportPayloadBuilderTest` asserts the exact nested shape of `build()`'s output. Both must stay green untouched.
- **Do not move or rewrite any `streamXxx()`/`metadata()`/`profile()`/`site()` method body.** Their redaction logic and legal-rationale docblocks (Article 15 reasoning, PII exclusions) stay exactly where they are — only the *enumeration* of which methods get called, in what order, collapses to one list.
- **No new Laravel migration files** — this task touches zero DB schema (per project rule; also simply not applicable here).
- 4-space indentation, LF line endings.

---

### Task 1: Single-source section manifest in `DataExportPayloadBuilder`

**Files:**
- Modify: `app/Services/User/DataExport/DataExportPayloadBuilder.php:1-110` (imports, `build()`) and `:112-300` (`stream()`)
- Test: `tests/Feature/User/DataExport/DataExportPayloadBuilderTest.php` (existing — no edits in this task, used as the regression net)

**Interfaces:**
- Produces: `DataExportPayloadBuilder::sectionDescriptors(User $professional, ?string $lookupEmail, ?string $siteId): array` — ordered list of `array{name: string, kind: 'value'|'rows', resolve: \Closure, csv_columns?: ?array<string>}`. `stream()` and `build()` both consume this; nothing outside the class calls it.
- `stream()` and `build()` keep their existing public signatures (`stream(string $userId): Generator`, `build(string $userId): array`) and existing yield/return shapes — unchanged from the caller's perspective (`DataExportZipWriter::writeStreaming()`, `ExportUserDataJobTest`, `DataExportPayloadBuilderTest` all keep working with no edits).

- [x] **Step 1: Confirm the baseline is green**

Run: `php artisan test --filter=DataExport`
Expected: All tests in `DataExportPayloadBuilderTest`, `DataExportZipWriterStreamingTest`, `ExportUserDataJobTest` PASS. This is the safety net for the refactor — no new failing test to write first, since no new behavior is being introduced yet (Task 3 adds the one new regression guard).

- [x] **Step 2: Add the `Arr` import**

In `app/Services/User/DataExport/DataExportPayloadBuilder.php`, add to the `use` block (after `use App\Services\User\Concerns\ResolvesDeletedEmail;`):

```php
use Illuminate\Support\Arr;
```

- [x] **Step 3: Replace `build()` and `stream()` with the manifest-driven versions**

Replace the entire `build()` method (currently lines 57-110) and the entire `stream()` method (currently lines 122-300) with:

```php
    /**
     * Build the full payload for a single professional, materialised in memory.
     *
     * Prefer stream() for production exports — this entry point exists for
     * tests and small-tenant scenarios. It iterates the same generators
     * stream() exposes, so memory usage scales with the largest section.
     *
     * @return array{metadata: array, profile: array, site: array, waitlist: array, media: array, design_kit: array, integrations: array, customers: array, services: array, service_categories: array, enquiries: array, lead_submissions: array, feedback: array, content_reports: array, email_subscriptions: array, notifications: array, ui_preferences: array, notification_preferences: array, auth: array, audit: array}
     */
    public function build(string $userId): array
    {
        $out = [];
        foreach ($this->stream($userId) as $section) {
            // $section['name'] is dot-delimited for nested groups (e.g. 'audit.handle_change_log');
            // Arr::set treats each dot as a nesting level, so this reconstructs the exact
            // nested shape the old hand-written build() built by hand — from stream()'s own
            // output, not a second independently-maintained list.
            Arr::set(
                $out,
                $section['name'],
                $section['kind'] === 'value' ? $section['value'] : $this->collect($section['rows'])
            );
        }

        return $out;
    }

    /**
     * Yield section descriptors in payload order. Each yielded item is one of:
     *   ['name' => string, 'kind' => 'value', 'value' => mixed]
     *   ['name' => string, 'kind' => 'rows',  'rows' => Generator, 'csv_columns' => ?array<string>]
     *
     * For nested groups (notifications, ui_preferences, notification_preferences,
     * auth, audit, media) the descriptor's 'name' uses dotted form (e.g.
     * 'audit.handle_change_log'); the writer reassembles the group structure
     * when emitting JSON, preserving the order each group is first encountered.
     *
     * This is the ONLY place sections are enumerated (FOUND-1) — sectionDescriptors()
     * below is the single manifest both this method and build() derive from.
     */
    public function stream(string $userId): Generator
    {
        $professional = $this->loadUser($userId);
        $lookupEmail = $this->resolveDeletedAccountEmail($professional);
        $siteId = DB::connection('pgsql')
            ->table('site.sites')
            ->where('user_id', $userId)
            ->value('id');

        foreach ($this->sectionDescriptors($professional, $lookupEmail, $siteId) as $section) {
            if ($section['kind'] === 'value') {
                yield ['name' => $section['name'], 'kind' => 'value', 'value' => ($section['resolve'])()];
            } else {
                yield [
                    'name' => $section['name'],
                    'kind' => 'rows',
                    'rows' => ($section['resolve'])(),
                    'csv_columns' => $section['csv_columns'] ?? null,
                ];
            }
        }
    }

    /**
     * Single source of truth for every GDPR export section: name, whether it's a
     * scalar 'value' or a 'rows' generator, how to resolve it, and its CSV column
     * allow-list (if any). Adding a new exportable section means adding ONE entry
     * here — both build() and stream() automatically pick it up. This directly
     * closes FOUND-1: previously build() and stream() each hand-enumerated the
     * same ~26 sections independently, so a missed edit to one silently omitted
     * a section from that entry point.
     *
     * @return array<int, array{name: string, kind: 'value'|'rows', resolve: \Closure, csv_columns?: ?array<string>}>
     */
    private function sectionDescriptors(User $professional, ?string $lookupEmail, ?string $siteId): array
    {
        $userId = $professional->id;

        return [
            ['name' => 'metadata', 'kind' => 'value', 'resolve' => fn () => $this->metadata($professional)],
            ['name' => 'profile', 'kind' => 'value', 'resolve' => fn () => $this->profile($professional)],
            ['name' => 'site', 'kind' => 'value', 'resolve' => fn () => $this->site($userId)],
            [
                'name' => 'waitlist',
                'kind' => 'rows',
                'resolve' => fn () => $this->streamWaitlistSignups($lookupEmail),
                'csv_columns' => ['id', 'name', 'email', 'phone', 'applicant_type', 'applicant_type_other', 'industry', 'industry_other', 'pilot_program_opt_in', 'number_of_team_members', 'consent_source', 'last_submitted_at', 'created_at', 'updated_at'],
            ],
            ['name' => 'media.site_media', 'kind' => 'rows', 'resolve' => fn () => $this->streamMedia($userId)],
            ['name' => 'design_kit', 'kind' => 'rows', 'resolve' => fn () => $this->streamDesignKit($siteId)],
            ['name' => 'integrations', 'kind' => 'rows', 'resolve' => fn () => $this->streamIntegrations($userId)],
            [
                'name' => 'customers',
                'kind' => 'rows',
                'resolve' => fn () => $this->streamCustomers($userId),
                'csv_columns' => ['id', 'email', 'phone', 'full_name', 'source', 'notes', 'created_at'],
            ],
            ['name' => 'services', 'kind' => 'rows', 'resolve' => fn () => $this->streamServices($userId)],
            ['name' => 'service_categories', 'kind' => 'rows', 'resolve' => fn () => $this->streamServiceCategories($userId)],
            [
                'name' => 'enquiries',
                'kind' => 'rows',
                'resolve' => fn () => $this->streamEnquiries($userId),
                'csv_columns' => ['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'],
            ],
            ['name' => 'lead_submissions', 'kind' => 'rows', 'resolve' => fn () => $this->streamLeadSubmissions($userId)],
            ['name' => 'feedback', 'kind' => 'rows', 'resolve' => fn () => $this->streamFeedback($userId)],
            ['name' => 'content_reports', 'kind' => 'rows', 'resolve' => fn () => $this->streamContentReports($userId, $lookupEmail)],
            ['name' => 'email_subscriptions', 'kind' => 'rows', 'resolve' => fn () => $this->streamEmailSubscriptions($userId, $lookupEmail)],
            ['name' => 'notifications.messages', 'kind' => 'rows', 'resolve' => fn () => $this->streamNotifications($userId)],
            ['name' => 'notifications.receipts', 'kind' => 'rows', 'resolve' => fn () => $this->streamNotificationReceipts($userId)],
            ['name' => 'ui_preferences.confirmation_preferences', 'kind' => 'rows', 'resolve' => fn () => $this->streamConfirmationPreferences($userId)],
            ['name' => 'notification_preferences.category_preferences', 'kind' => 'rows', 'resolve' => fn () => $this->streamNotificationPreferences($userId)],
            ['name' => 'notification_preferences.staff_policy_overrides', 'kind' => 'rows', 'resolve' => fn () => $this->streamNotificationPolicies($userId)],
            ['name' => 'auth.factor_events', 'kind' => 'rows', 'resolve' => fn () => $this->streamAuthFactorEvents($professional->auth_user_id)],
            ['name' => 'audit.data_export_audit', 'kind' => 'rows', 'resolve' => fn () => $this->streamAudit($userId)],
            ['name' => 'audit.handle_change_log', 'kind' => 'rows', 'resolve' => fn () => $this->streamHandleChangeLog($userId)],
            ['name' => 'audit.handle_aliases', 'kind' => 'rows', 'resolve' => fn () => $this->streamHandleAliases($userId)],
            ['name' => 'audit.subdomain_aliases', 'kind' => 'rows', 'resolve' => fn () => $this->streamSubdomainAliases($userId)],
            ['name' => 'audit.deletion_audit', 'kind' => 'rows', 'resolve' => fn () => $this->streamDeletionAudit($userId)],
        ];
    }
```

Leave every other method in the file (`loadUser`, `metadata`, `profile`, `site`, all `streamXxx()` methods, `lazyRows`, `collect`, `normaliseEmail`) exactly as-is — this task only touches the two enumeration methods plus adds the one new private method above.

- [x] **Step 4: Run the DataExport test suite**

Run: `php artisan test --filter=DataExport`
Expected: All tests PASS, identical to the Step 1 baseline. If `DataExportPayloadBuilderTest` fails on a specific section (e.g. `payload['audit']['handle_change_log']` missing), check that section's entry in `sectionDescriptors()` for a typo in `name` (dotted path must match exactly what the old hand-written `build()` used as its array key path).

- [x] **Step 5: Commit**

```bash
git add app/Services/User/DataExport/DataExportPayloadBuilder.php
git commit -m "refactor(gdpr): collapse build()/stream() into one section manifest (FOUND-1)"
```

---

### Task 2: Simplify `DataExportZipWriter::csvNameFor()`

**Files:**
- Modify: `app/Services/User/DataExport/DataExportZipWriter.php:246-253`
- Test: `tests/Feature/User/DataExport/DataExportZipWriterStreamingTest.php` (existing — regression net; asserts `customers.csv` and non-existence of `waitlist.csv`/`audit_handle_change_log.csv`)

**Interfaces:**
- Consumes: nothing new.
- Produces: `csvNameFor(string $sectionName): string` keeps its existing signature and output for every current call site — this step only removes now-provably-redundant special cases.

- [x] **Step 1: Verify the two match() arms are redundant (already done during planning, re-confirm here)**

Run: `php -r "echo str_replace('.', '_', 'customers').'.csv', PHP_EOL, str_replace('.', '_', 'enquiries').'.csv', PHP_EOL;"`
Expected output:
```
customers.csv
enquiries.csv
```
This is identical to what the `match()`'s explicit `'customers' => 'customers.csv'` and `'enquiries' => 'enquiries.csv'` arms already produce — confirming they're dead weight before removing them.

- [x] **Step 2: Replace `csvNameFor()`**

Replace (in `app/Services/User/DataExport/DataExportZipWriter.php`):

```php
    private function csvNameFor(string $sectionName): string
    {
        return match ($sectionName) {
            'customers' => 'customers.csv',
            'enquiries' => 'enquiries.csv',
            default => str_replace('.', '_', $sectionName).'.csv',
        };
    }
```

with:

```php
    private function csvNameFor(string $sectionName): string
    {
        return str_replace('.', '_', $sectionName).'.csv';
    }
```

- [x] **Step 3: Run the streaming writer test suite**

Run: `php artisan test --filter=DataExportZipWriterStreaming`
Expected: PASS — in particular the assertions `expect($zip->locateName('customers.csv'))->not->toBeFalse();` and `expect($zip->locateName('waitlist.csv'))->toBeFalse();` (`tests/Feature/User/DataExport/DataExportZipWriterStreamingTest.php:78-81`) must still pass unchanged.

- [x] **Step 4: Commit**

```bash
git add app/Services/User/DataExport/DataExportZipWriter.php
git commit -m "refactor(gdpr): drop redundant csvNameFor() match arms (FOUND-1)"
```

---

### Task 3: Add the FOUND-1 regression guard

**Files:**
- Modify: `tests/Feature/User/DataExport/DataExportPayloadBuilderTest.php` (append new test at end of file)

**Interfaces:**
- Consumes: `DataExportPayloadBuilder::stream(string $userId): Generator` (yields `['name' => string, ...]`), `DataExportPayloadBuilder::build(string $userId): array`, `Illuminate\Support\Arr::has()`.
- Produces: nothing new for other tasks — this is the terminal regression guard for FOUND-1.

- [x] **Step 1: Write the guard test**

Append to `tests/Feature/User/DataExport/DataExportPayloadBuilderTest.php`:

```php
it('every section stream() yields resolves to a real key in build() — FOUND-1 regression guard', function () {
    // FOUND-1: build() and stream() used to be two independently hand-written
    // enumerations of the same ~26 sections; a missed edit to one silently
    // dropped a section from a GDPR export with nothing warning anyone. Now
    // build() derives its output from stream()'s own descriptors, so this
    // assertion can only fail if someone reintroduces a second, separately
    // maintained list — it is not possible for the two to drift on their own.
    $pro = seedProForPayload((string) Str::uuid());
    $builder = app(DataExportPayloadBuilder::class);

    $streamedNames = [];
    foreach ($builder->stream($pro->id) as $section) {
        $streamedNames[] = $section['name'];
    }

    // Sanity check the manifest itself isn't accidentally empty.
    expect($streamedNames)->not->toBeEmpty();

    $payload = $builder->build($pro->id);

    foreach ($streamedNames as $name) {
        expect(\Illuminate\Support\Arr::has($payload, $name))
            ->toBeTrue("build() is missing section '{$name}' that stream() yields");
    }
});
```

- [x] **Step 2: Run it**

Run: `php artisan test --filter=DataExportPayloadBuilderTest`
Expected: PASS, including the new test.

- [x] **Step 3: Commit**

```bash
git add tests/Feature/User/DataExport/DataExportPayloadBuilderTest.php
git commit -m "test(gdpr): add FOUND-1 build()/stream() parity regression guard"
```

---

### Task 4: Full-suite verification

**Files:** none (verification only)

- [x] **Step 1: Run the full Pest suite**

Run: `composer test`
Expected: full green, same pass count as the branch's baseline (no new failures, no skipped tests introduced).

- [x] **Step 2: Run Pint**

Run: `php artisan pint --test app/Services/User/DataExport/ tests/Feature/User/DataExport/`
Expected: no style violations. If any, run `php artisan pint app/Services/User/DataExport/ tests/Feature/User/DataExport/` and commit the fix separately.

- [x] **Step 3: Final commit check**

```bash
git log --oneline -5
git status
```
Expected: three feature commits from Tasks 1-3 (plus Task 2's), clean working tree, no stray files.

---

## Self-Review Notes

- **Spec coverage:** The finding's three "what to do" bullets (interface/registry/wire into build+stream+csvNameFor) are addressed by the manifest instead of classes — see the architecture rationale above and the conversation that selected this shape over the literal class-registry suggestion. The finding's core compliance risk (a missed edit silently omits a section) is fully closed: there is now exactly one list, and Task 3's test makes drift structurally guarded, not just "hopefully collapsed."
- **Placeholder scan:** No TBD/TODO; every step shows complete, exact code copied from the real current file contents (verified via Read on 2026-07-04) — not paraphrased.
- **Type consistency:** `sectionDescriptors()`'s return shape (`name`, `kind`, `resolve`, `csv_columns?`) is used identically in both consumers (`stream()`, and transitively `build()` via `stream()`); `csv_columns` is always accessed via `?? null` so entries that omit the key (all `'value'`-kind and most `'rows'`-kind sections) are safe.

## Next Steps (per project audit fix-flow)

This is a P0 finding — per `CLAUDE.md`'s blocker gate, implementation should not start until Josh signs off on this plan. Once approved:
1. Branch `audit-fix/found-1-data-export-registry-2026-07-04` off `development`.
2. Dispatch an independent Sonnet subagent to implement Tasks 1-4 exactly as written.
3. Dispatch a second, separate Sonnet subagent to independently review the diff against this plan and the Global Constraints above (behavior-preservation is the primary thing to verify).
4. On PASS, push the branch and open a PR into `development` (no Supabase migration involved — this task touches zero schema).
