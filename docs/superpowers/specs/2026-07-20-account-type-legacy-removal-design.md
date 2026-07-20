# Retiring the legacy `account_type` values

**Date:** 2026-07-20
**Status:** Approved — ready for implementation planning
**Origin:** Question "what is the point of `AccountType::Individual`, can it be removed?"

---

## 1. Summary

`AccountType::Individual` is a vestigial enum case whose stated justification expired on
2026-06-12. Removing it is straightforward on its own, but it is one symptom of a broader
problem:

> **The test suite seeds four `account_type` values, and production's CHECK constraint
> permits none of them.**

This spec covers removing all four, fixing a dated production landmine discovered in the
same code path, and adding the constraint that stops the class of bug recurring.

---

## 2. Root cause

`core.users.account_type` is constrained in production to `('partna', 'business')`
(migration `20260712000000`). The values actually in circulation:

| Value       | Enum case          | Permitted by prod CHECK | Where it comes from                       |
|-------------|--------------------|-------------------------|-------------------------------------------|
| `individual`| ✅ vestigial       | ❌                      | `createTenant()` default + ~79 test files |
| `brand`     | ❌ none            | ❌                      | `createBrandTenant()`                     |
| `partner`   | ❌ none            | ❌                      | `createAffiliateTenant()`                 |
| `staff`     | ❌ retired 07-12   | ⚠️ still live on dev    | migration drift (see §6)                  |

Nothing catches any of this. `brand` has been invalid since the May strip-down and the
suite has stayed green for two months.

### 2.1 Why the invalid values do not currently fail

Laravel enum casts are **lazy** — they resolve on attribute *access*, not on hydration.
`User::findOrFail()` loads a row with `account_type = 'brand'` without complaint; the
`ValueError` only fires when something reads `$user->account_type`.

Verified: `tests/Unit/Analytics/PostgresEventWriterTest.php` (13 tests, all using
`createBrandTenant`) passes 13/13. Those tests never touch `AccountCapabilities::for()`
or `isBusiness()`. Adding a single capability assertion to any of them would throw.

**This is load-bearing for §4.3:** because reading the attribute throws, no currently
passing test can be asserting on `account_type` for those users. Collapsing them to a
normal tenant therefore cannot change any assertion. The enum's own strictness certifies
the refactor.

---

## 3. Evidence

Gathered 2026-07-20 against dev Supabase `glncumufgaqcmqhzwrxm` (the live DB for all
environments — see CLAUDE.md).

**Live data — no `individual` rows exist:**
```
partna     18
business    5
```

**Production code references to `AccountType::Individual`:** zero.

**Test surface:**
- `'individual'` — 134 occurrences across 79 files, including the shared factory
  `tests/Pest.php:1019`
- `createBrandTenant` / `createAffiliateTenant` — 114 call sites
- 13 hand-rolled `CREATE TABLE IF NOT EXISTS core.users` definitions across 12 test
  files; 8 appear to declare `account_type` (exact list to be confirmed during
  implementation — occurrence count is not the same as a column declaration)

**Unrelated same-word usages — MUST NOT be touched:**
- `worker_kv_type: 'individual'` (`AccountCapabilities.php:55`) and
  `['type' => 'individual']` in `SyncSubdomainToKvJob` — Cloudflare KV routing discriminator
- `'individual'` bucket in `ShopBrand` / `ShopController` / `ShopBrandResource` — a
  product-grouping bucket

A naive find-and-replace across the repo would break Cloudflare routing.

---

## 4. Work units

### 4.1 U1 — Fix the KV backfill cutover landmine

`app/Console/Commands/BackfillUserKvEntries.php:35` filters:

```php
->where('account_type', 'individual');
```

This can only match zero rows. The command prints `Target cohort: 0 user(s).`, returns
`SUCCESS`, and populates nothing.

This matters because the command is **provisioning infrastructure, not dead code**. The
strip-down plan (`2026-05-21-standalone-pages-backend-strip.md` step 9) specifies running
it after provisioning a fresh Supabase project and a fresh `SUBDOMAIN_KV` namespace — and
a pilot prod cutover is the current plan of record (decided 2026-07-17). At cutover this
command would silently populate nothing, every sitepage would 404, and the command would
report success.

**Fix:** drop the `account_type` filter entirely.

```php
$query = User::query()
    ->whereNotNull('handle')
    ->where('handle', '!=', '');
```

Cohort decisions stay in `SyncSubdomainToKvJob`, which is already the single authority:
moderation-hidden → retire; unclaimed → TTL aligned to `pre_account_builds.expires_at`;
expired or buildless unclaimed → retire. Re-implementing any of that in the command would
drift from the job.

U1 is independent of U2–U5 and shippable on its own.

### 4.2 U2 — Sweep `individual` → `partna` in tests

- `tests/Pest.php:1019` — the shared `createTenant()` factory default
- ~79 further files with direct inserts
- Delete the `$type` parameter from `createTenant(string $handle, string $type = 'professional')`
  — confirmed **zero** references inside the function body; it is pure decoration

### 4.3 U3 — Retire `createBrandTenant` / `createAffiliateTenant`

114 call sites → `createTenant($handle)`. Delete both helpers.

Both are pre-strip-down fossils. `createAffiliateTenant`'s comment claims it exists "so
`AccountCapabilities` returns the partner capability set" — there has been no partner
capability set since May 2026. Safety argument in §2.1.

### 4.4 U4 — Delete `case Individual`

`app/Enums/AccountType.php` becomes a two-case enum. Update the docblock to drop the
now-obsolete backfill-window paragraph.

### 4.5 U5 — Add the CHECK guard to the test schema

Mirror production's constraint in every test `core.users` definition that declares the
column:

```sql
account_type TEXT NULL CHECK (account_type IN ('partna','business'))
```

SQLite enforces CHECK constraints, so an invalid seed fails **at the insert** — at the
seam where it is introduced — rather than passing silently or `ValueError`-ing somewhere
unrelated later. This closes the documented SQLite-vs-Postgres drift class (CLAUDE.md,
"Verify Before Done") for this column.

### 4.6 U6 — Apply the drift migrations to dev *(GATED)*

Separate branch, separate sign-off. Not to be folded into a test-cleanup PR.

---

## 5. Sequencing

**U5 is a discovery instrument, not just a guard.**

Land U5 first in the working branch, deliberately red. The failing suite then produces an
exact inventory of every bad seed — including any the greps missed — rather than trusting
a 79-file find-and-replace. Fix until green, then **reorder so U5 lands last** in commit
history so every commit is bisectable.

Working order: U1 → U5(red) → U2 → U3 → U4 → green
Commit order:  U1 → U2 → U3 → U4 → U5

---

## 6. Migration drift (context for U6)

Dev's live CHECK is the **three-value** form:

```sql
CHECK (account_type = ANY (ARRAY['partna'::text, 'business'::text, 'staff'::text]))
```

But `supabase_migrations.schema_migrations` records neither `20260711000000` (added
`staff`) nor `20260712000000` (retired it) as applied — only `20260612120000`. The staff
constraint was therefore applied out-of-band and never recorded.

Consequence: **dev currently permits an `account_type` the code retired on 2026-07-12.**

Both migrations are `DROP CONSTRAINT IF EXISTS` + `ADD ... NOT VALID` + `VALIDATE`, so
replaying them in order lands the correct two-value constraint. `20260712000000` is ~45
lines and does more than the CHECK — read it in full before applying.

---

## 7. Out of scope

**Consolidating the 13 duplicate `core.users` test-table definitions into one shared
helper.** It is the obvious next refactor and is deliberately excluded — it would triple
the diff without changing the outcome of this work. Worth its own ticket.

Any similar audit of `core.users.status` values. Not investigated; do not scope-creep.

---

## 8. Verification

- `composer test` green
- PHPStan gate clean — removing an enum case may shift the baseline
  (`reportUnmatchedIgnoredErrors` defaults true; a correct fix can fail the build)
- `php artisan pint` on changed files only
- Grep `docs/` and `scripts/audit/` prose for stale references to `individual` **as an
  account type** — leave KV-routing and ShopBrand usages alone (§3)
- U1: assert the command's cohort query returns a non-zero count against seeded users

---

## 9. Risks

| Risk | Mitigation |
|---|---|
| Find-and-replace breaks Cloudflare KV routing or ShopBrand | §3 lists the protected usages; sweep `account_type` assignments only, never the bare string |
| Sweep misses files | U5-first sequencing turns any miss into a test failure |
| U3 changes test semantics | §2.1 — reading the attribute throws, so no passing test can depend on it |
| Drift migration does more than expected | U6 gated on explicit sign-off; read `20260712000000` in full first |
