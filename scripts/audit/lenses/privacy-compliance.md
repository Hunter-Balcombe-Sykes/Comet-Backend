# Privacy & data-rights compliance: PII inventory, export/delete completeness, retention enforcement, processor flows

Hunt **personal data the platform holds but cannot account for**: PII stores missing from the export path, surviving the deletion path, lacking an enforced retention rule, or flowing to third parties beyond need. Partna is an Australian platform (Privacy Act / APPs) serving individual professionals whose sitepages collect **other people's** data too (customers, enquiries, analytics visitors) — so every right has two subjects: the account holder AND the people in their data.

This lens asks one question per PII item, four ways: **can we export it, can we delete it, when does it expire, and who else receives it?** The canonical machinery (verify each before grading against it):

- **Export:** `app/Jobs/Gdpr/ExportUserDataJob.php` → `app/Services/User/DataExport/` (`DataExportService`, `DataExportPayloadBuilder`, `DataExportZipWriter`); artifact retention `config('partna.gdpr.export_retention_days')` (default 30).
- **Deletion:** account-deletion flow (`UserAccountDeletionController`, `AccountDeletionService`, `app/Jobs/Account/`), `EnforcePendingDeletionReadOnly` middleware, soft-delete purge after `config('partna.soft_delete_retention_days')` (default 30), `DeleteMediaArtifactsJob` for stored media.
- **Retention config:** `analytics_raw_event_retention_days` (90), `notification_retention_days`, handle-audit `audit_retention_years` (7), GDPR export retention (30) — all in `config/partna.php`.
- **Log hygiene:** `tests/Feature/Security/PiiLogHygieneSweepTest.php` is the house sweep; staff access auditing via the `staff.audit` middleware into the append-only `audit` schema.

Sibling boundaries: request-boundary PII exposure (Resources leaking fields) → `security.md` (SEC) owns it; schema-side FK/constraint correctness → `data-integrity.md` (DINT). This lens owns the **rights-and-retention ledger**: inventory ↔ export ↔ delete ↔ expire ↔ share. Overlaps go to the more specific lens; the adjudicator dedupes.

## Use the lens prefix `PRIV` for findings

Number them `PRIV-1`, `PRIV-2`, … sequentially. **P0 for an export that can include another tenant's data, or a deletion path that silently abandons PII forever with no retention bound. P1 for a PII store absent from BOTH export and deletion, or a declared retention rule with no enforcement job. P2 for minimisation and processor-hygiene gaps. P3 for documentation/config drift.**

## Findings categories

### (1) PII inventory completeness

Build the inventory from the schema (`supabase/migrations/`, baseline first) and models, then grade everything else against it.

- Columns holding direct identifiers (name, email, phone, address, DOB, IP, precise location), indirect identifiers (handle, avatar, social URLs), and content that embeds PII (enquiry messages, feedback text, moderation evidence, JSONB settings blobs).
- JSONB fields are the blind spot: enumerate keys the app actually writes (`site.sites.settings`, block content, notification payloads) and flag PII living inside JSONB that no inventory, export, or redact path addresses by key.
- Visitor-side PII in `analytics.*`: IP address, user agent, region — quote how `AnalyticsEvent` / `PostgresEventWriter` capture and store them, and whether raw values or derived/truncated values land in Postgres.
- Any PII column added after the baseline migration that the export builder and deletion service were not updated for — diff column lists against `DataExportPayloadBuilder` and the deletion path explicitly.

### (2) Export completeness & artifact safety (right of access)

- Diff `DataExportPayloadBuilder` against the category-1 inventory: every PII table/JSONB key the user's account touches must appear in the export, or be documented as excluded with a reason (e.g., moderation evidence under review). Missing-from-export is P1.
- **Tenant scoping inside the builder is P0 territory:** every query must scope by the exporting `user_id`; quote any join or relation that could pull rows belonging to another user (e.g., shared/global tables exported unscoped).
- Artifact safety: where does the zip live, who can fetch it (signed URL? authenticated download? expiring link?), is the retention (30d) actually enforced by a scheduled cleanup, and does the export job avoid logging payload contents?
- Second-subject data: enquiries/customers contain THIRD PARTIES' PII — confirm the export gives the account holder their business records without turning the platform into a bulk PII dump (no fields beyond what the professional already legitimately holds).

### (3) Deletion completeness (right of erasure) — the highest-stakes category

Trace the account-deletion flow end-to-end and tick off EVERY store from the inventory:

- DB rows: soft-deleted at request, hard-purged after the 30-day retention — confirm a scheduled purge job exists, runs (check `routes/console.php`), covers every soft-deletable PII model, and that `EnforcePendingDeletionReadOnly` blocks writes in the window.
- Stored media: `DeleteMediaArtifactsJob` — confirm it covers all pools/variants (originals, processed variants, design media) and what happens when artifact deletion fails (retry? orphan tracking? silent loss = P1).
- Edge + KV: the subdomain KV entry and BOTH edge-cache copies (primary + stale shadow) must be cleared — a deleted user's page surviving at the edge is the deletion-path twin of the takedown gap in `edge-worker.md` (EDGE owns the purge mechanics; PRIV owns "deletion didn't invoke it").
- Handle/subdomain aliases: alias rows retain the old handle (an identifier) for up to 90 days post-rename — confirm account deletion shortens or clears them rather than inheriting the rename lifecycle.
- Analytics: per-site events/sessions for a deleted user's site — deleted, anonymised, or retained-with-justification? Silence is a finding; raw IP/user-agent retained past site deletion is P1.
- Notifications, feedback, enquiries, customers, GDPR export artifacts themselves, Redis (queued job payloads carrying PII outlive the row), and the `audit` schema (append-only by design — confirm the deletion record itself doesn't embed more PII than needed, and that the 7-year handle-audit retention is a documented deliberate exception, not an accident).
- Supabase Auth: the auth user record vs `core.users` — confirm both sides are removed/disabled and which system is authoritative.

### (4) Retention rules & enforcement

- For EVERY retention value declared in `config/partna.php` (soft-delete 30d, raw analytics 90d, notification retention, export artifacts 30d, handle audit 7y): find the scheduled job/command that enforces it in `routes/console.php`. **A declared retention with no enforcement job is a P1 finding — config that lies.**
- For every append-heavy or PII-bearing table NOT covered by a retention rule: flag unbounded accumulation (P2) — moderation evidence snapshots, feedback, enquiry threads, audit rows beyond the handle log.
- Enforcement jobs must log what they purged (counts, not contents) — silent purges are unverifiable compliance.

### (5) Minimisation at collection

- Analytics ingest: is IP truncated/hashed or stored raw? Is user agent stored verbatim? Is region derived-then-dropped or kept alongside the raw IP? Storing raw forever when a derived value would serve is the canonical APP minimisation finding (P2).
- Public forms (enquiries, feedback, early access): fields collected vs fields used — any collected-but-never-read PII field is minimisation debt. This is exactly how `core.waitlist_signups` became write-only and was retired 2026-07-19.
- Media uploads: EXIF/location metadata stripped on processing? (`app/Services/Media` pipeline — confirm or flag.)
- Bot-protection providers receive what (IP, UA, token)? Confirm only the minimum crosses the boundary.

### (6) Processor / third-party flows

Inventory every place PII leaves the platform, and grade necessity:

- Email (Resend/Postmark/SES): message bodies embedding more PII than needed; recipient lists in logs.
- Slack notifications (ops/staff alerts): user emails/handles in alert payloads land in a third-party workspace — flag anything beyond an opaque ID.
- Cloudflare KV: confirm entries hold routing data only (handle, type, redirect) — no names/emails at the edge.
- Nightwatch: exception context carrying request bodies or PII fields (the log-hygiene sweep's telemetry twin).
- For each flow, the canonical fix: send opaque IDs, fetch detail on the receiving side, or document the processor relationship.

### (7) Staff access & audit trail

- Every staff route that reads user PII must carry the `staff.audit` middleware (audit entry in the append-only `audit` schema) — sweep `routes/api/staff.php` for PII-reading endpoints missing it.
- Staff endpoints returning MORE PII than the staff task needs (full customer lists where a count would do) — minimisation applies internally too.
- Confirm audit rows capture who-accessed-what without duplicating the PII itself into the audit record.

## Per-finding requirements

For every finding:
- Cite the category number (1–7).
- Quote verbatim evidence: the schema column, the builder/service method, the missing entry in `routes/console.php`, the log call.
- For export/delete gaps: name the PII store AND the exact method that should cover it (`DataExportPayloadBuilder::…`, the purge job, `DeleteMediaArtifactsJob`).
- Name the canonical fix: add table to export builder, wire column into deletion service, schedule the retention command, truncate/hash at ingest, opaque-ID the outbound payload, add `staff.audit` to the route.
- **Plain English matters double here** — these findings describe legal exposure; write them so a founder can repeat them to a lawyer.

## Out of scope — do NOT re-flag

- Request-boundary PII exposure in API Resources → `security.md` (SEC category) owns it; flag here only the at-rest/rights-ledger side.
- Schema constraint mechanics (FKs, CASCADE correctness) → `data-integrity.md` (DINT).
- Edge purge mechanics → `edge-worker.md` (EDGE); this lens flags only "deletion never invokes the purge".
- Policy documents / privacy-policy wording — not in this repo. Flag only in-repo contradictions (config value vs code comment vs enforcement).
- Dormant moderation/CSAM vocabulary — deliberate (pipeline deferred); the moderation foundation's data handling IS in scope.
- The append-only `audit` schema's existence — by design; only its PII payload discipline is in scope.

## Suggested per-domain scope groups

### Group A — Rights machinery (export + deletion)
```
--scope app/Jobs/Gdpr
--scope app/Jobs/Account
--scope app/Services/User
--scope app/Models
--scope app/Http/Resources
```

### Group B — Collection & retention surfaces
```
--scope app/Services/Analytics
--scope app/Jobs/Analytics
--scope app/Services/Moderation
--scope app/Services/Notifications
--scope app/Http/Middleware/Logging
--scope config/partna.php
--scope routes/console.php
```

### Group C — Schema-side PII inventory
```
--scope supabase/migrations
```

### Group D — Third-party data landed by connectors
```
--scope app/Ingest/Connectors
--scope app/Ingest/Landing
--scope app/Content
```
## Exhaustiveness directive

Build the inventory first, then audit the four ledgers (export / delete / expire / share) against it — do not sample. Every PII column and JSONB key gets a verdict in all four; emit a finding for each gap you can quote. A PII store that is missing from export AND deletion AND retention is three findings collapsed into one P1 with all three gaps named. **Under-reporting here is deferred legal risk: the gap doesn't hurt until the first deletion request or breach notice, and then it's unfixable retroactively.**
