# STRIP-LEDGER Build Method

Reusable method for producing `audits/standalone-pages/STRIP-LEDGER.md` — the
authoritative, file-by-file classification for the standalone-pages backend
strip. The ledger supersedes every inline file list in the strip plan.

## Why this exists

Two adversarial reviews of the prose plan each found ~20 blocking gaps. A
hand-written file list is never complete — that is inherent to writing it by
hand. This method makes the ledger **complete by construction**: every file in
scope is enumerated mechanically, so the only possible error is a
*mis-classification* (bounded, reviewable) — never a *missing file*.

## Scope

Every git-tracked file under: `app/`, `bootstrap/`, `routes/`, `config/`,
`database/`, `tests/`, `resources/views/emails/`, `cloudflare-worker/`.
Plus all DB objects in `supabase/migrations/`.

## Classification — exactly one per file

- **DELETE** — the file exists *only* to serve a dropped domain: brand /
  affiliate / partner / Shopify / Square / Fresha / Stripe / billing /
  subscriptions / commerce / orders / payouts / commissions / exports /
  booking / the account-type transition machinery / commerce-coupled staff.
- **EDIT** — the file is on the surviving user/site/public path **but**
  contains references to a dropped domain that must be severed.
- **KEEP** — the file is on the surviving path and references nothing dropped.
- **DB** — database objects only; handled in the dedicated DB section.

## Method (per agent — each owns one subtree)

1. `git ls-files <subtree>` → the complete file list. **Every file gets a row.**
2. Grep each file's content for dropped-domain signal tokens:
   `Brand Affiliate Partner Shopify Square Fresha Stripe Billing Subscription
   Plan Commission Commerce Order Payout Export Booking Hydrogen Oxygen
   account_type professional_type AccountType AccountCapabilit`
3. Classify:
   - Zero signal **and** not inside a dropped-domain directory → **KEEP**.
   - Signal present → **read the file**. Decide DELETE (whole file is
     dropped-domain) vs EDIT (shared file, dropped refs to sever).
4. **Classify by content, not by path.** Wrong-directory files are a known
   trap (e.g. `CommissionMovementObserver` lives in `Observers/Core/`, not
   `Observers/Commerce/`).
5. Boot-path files (`bootstrap/app.php`, `app/Providers/*`) get extra
   scrutiny: list **every** alias / `observe()` / `Gate::policy` / `use` that
   must be severed, with line numbers.
6. Output one table row per file:
   `path | DELETE/EDIT/KEEP | signal tokens hit | one-line reason | (EDIT only) exact symbols/lines to sever`
7. Anything genuinely ambiguous → mark `NEEDS-REVIEW` with the open question.
   Do not guess.

## DB section

Enumerate across **all** of `supabase/migrations/`: every table, RLS policy,
GRANT, trigger, function, view. Classify each KEEP / DROP. For every KEEP
table, list the RLS policies + `app_backend` GRANTs that must be ported into
the single re-baseline migration. Flag every trigger/function/view on a KEEP
table that references a dropped table.

## Rules

- Trust nothing the plan asserts; verify against the live repo.
- Every in-scope file appears in the ledger exactly once.
- A prior adversarial review supplied a list of known landmines per subtree —
  verify and classify each one explicitly; do not assume it is correct.
