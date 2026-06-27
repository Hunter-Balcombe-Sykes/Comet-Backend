# STRIP-LEDGER Review — Method & Prompt

The final review before execution. Reviews **`STRIP-LEDGER.md`** — not the
strip plan. Run once; if it comes back clean of blockers, the ledger is
execution-ready and there is no further review.

## What this review is — and is NOT

The ledger was built by mechanical enumeration (`git ls-files` per subtree), so
**file-completeness is already settled**. Do NOT re-hunt for "missed files" —
that question is closed. This review checks one thing: **are the classifications
correct and the EDIT specs complete and safe?**

The dangerous failure mode is a **misclassified KEEP** — a file the ledger calls
KEEP that actually references a dropped domain. The ledger gate cannot catch it
(the gate trusts KEEP); it surfaces only as a runtime 500 after the DB
re-baseline. Hunting those is the review's first priority.

## Inputs

- `audits/standalone-pages/STRIP-LEDGER.md` — the artifact under review.
  Section 0 (the 18 resolved decisions + propagation overrides) is binding.
- The live repo at `/Users/joshuahunter/Herd/Side Street/backend`.

## Operating rules

1. Trust nothing the ledger asserts — verify every spot-check against the live
   repo with `rg` and file reads.
2. Adversarial stance: assume each classification is wrong until the code shows
   otherwise. A confirmed-correct row is not a finding; a wrong one is.
3. Evidence or it didn't happen: every finding cites file:line from the repo.
4. The ledger — including Section 0's propagation overrides — is the artifact
   under review. Where Section 0 and Sections 2–5 disagree, Section 0 wins;
   flag the stale Section 2–5 text as a finding.

## Checks (every agent runs all of these on its slice)

**C1 — Misclassified KEEP (highest priority).** For every KEEP row in the slice,
`rg` the file for dropped-domain tokens (`Brand Affiliate Partner Shopify Square
Fresha Stripe Billing Subscription Plan Commission Commerce Order Payout Export
Booking Hydrogen account_type professional_type`). Any hit → read the file →
confirm it is genuinely a comment/variable false-positive, OR it is a real
reference and the row must be EDIT/DELETE. `ProfessionalAnalyticsController` was
exactly this class of error — find the next one.

**C2 — Boot-unsafe DELETE.** For every DELETE file, `rg` its class name across
all KEEP and EDIT files. Any surviving reference must be severed by a named EDIT
spec in Section 2. A reference with no matching sever spec = blocking.

**C3 — EDIT sever-spec completeness.** For every EDIT row, read the file: (a)
confirm each named symbol/line actually exists (line numbers are approximate);
(b) confirm NO dropped-domain symbol is left unnamed; (c) confirm the sever does
not gut surviving logic (over-severing) — especially the SWR/single-flight core
in `SiteCacheService`, the idempotency guard in `SendEnquiryNotificationJob`,
and the auth/MFA path.

**C4 — Section 0 propagation consistency.** Verify the propagation overrides
hold against the live code. Specifically: (d3) the manual-booking-link path
genuinely survives untouched — `updateBookingSettings()`, `booking_mode`
validation, the resolver booking envelope; (d4) every `ProfessionalIntegration`
reference is severed or deleted; (d2) nothing the surviving `Customer` path
needs is itself DELETE; (d1) the kept Services path has no residual commerce
coupling; (d11/d12/d13) the dropped tables have no surviving reader.

**C5 — Decision-set contradiction.** Sanity-check the 18 resolutions against
each other and the code: does any KEEP depend on a DELETE? Does any kept feature
read a dropped column/table/config key?

## DB-specific checks (DB agent only)

- Re-verify the RLS port list against ALL `supabase/migrations/` — confirm no
  KEEP-table RLS policy is omitted; confirm every `comet_staff`/`sidest_staff`
  reference is flagged for rewrite to `partna_staff`.
- Confirm the trigger/function rewrites (`trg_professional_handle_change`,
  `compute_professional_url`, `trg_recompute_partna_url`) and the FK drop order.
- Confirm `BYPASSRLS` is kept (decision 16) and the baseline reflects that.
- Confirm `account_type` is kept but `professional_type` + all `stripe_*` +
  `payout_method` columns are dropped, and the `all_site_data` view drops
  `professional_type`.

## Dispatch structure

Parallel agents, one per subtree — the same slices as the ledger scan:
controllers · middleware/requests/resources · services · jobs+mail · models ·
observers/policies/listeners/enums/exceptions · providers/console/bootstrap ·
routes+config · factories/emails/worker · tests · DB. Each agent ultrathinks its
slice, runs C1–C5 (DB agent runs the DB checks), and reports only the rows it
found wrong. Consolidate into a single verdict.

## Deliverable

1. **Blocking** — misclassified KEEP, boot-unsafe DELETE, an EDIT spec that
   over-severs a PRESERVE item, a decision contradiction. With file:line.
2. **Spec gaps** — EDIT rows missing a symbol, or naming a symbol/line that
   doesn't exist.
3. **Stale text** — Sections 2–5 text that contradicts a Section 0 override.
4. **Verdict** — one of:
   - *Execution-ready* — only cosmetic spec tweaks, zero blocking. Ship it.
   - *Ready with listed corrections* — apply the named fixes, no re-review.
   - *Re-scan needed* — only if a systemic enumeration error is found (should
     not happen; the scan was mechanical).

## Stopping rule

If the verdict is *execution-ready* or *ready with listed corrections*, the
review treadmill ends here. Fold the corrections + the ledger into the strip
plan and execute behind the per-task gates (`composer test` + `route:list` +
`php artisan about` + request-path smoke). Do not run a second ledger review.
