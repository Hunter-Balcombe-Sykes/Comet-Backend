# Prompt: Remove CSAM pipeline (clean slate for later re-implementation)

## Context

The CSAM scanning pipeline (Plan C) was implemented but the Cloudflare R2 webhook integration
doesn't suit the current architecture. This prompt removes all CSAM-specific code and tables,
leaving the moderation foundation (cases, decisions, etc.) intact. CSAM will be re-added later
using a different scanning approach.

## Branch

Work on branch `feat/remove-csam-pipeline` off `development`. Push and open a PR to `development`.

## What to keep (do NOT touch)

- All base moderation tables: `moderation.cases`, `moderation.case_signals`, `moderation.evidence`,
  `moderation.decisions`, `moderation.action_log`
- All base moderation models, services, controllers, and commands that are NOT CSAM-specific
- The `scanned_at` column and `scanning`/`quarantined` values on `site.site_media` — these are
  harmless to leave in the DB schema and cost nothing to keep
- `ModerationDecisionService` — keep the whole file including `decideAsSystem()` (it's also
  used for non-CSAM auto-actions and the `override_csam_auto_action` check is harmless to leave)
- `docs/moderation/` — keep but update README (see below)
- `supabase/migrations/20260528020000_alter_site_media_for_scan_states.sql` — keep
- `supabase/migrations/20260528020001_alter_site_media_validate.sql` — keep

## Step 1: New Supabase reversal migration

Create `supabase/migrations/20260529200000_remove_csam_pipeline_tables.sql`:

```sql
-- Removes the CSAM pipeline tables. The moderation foundation (cases, decisions,
-- action_log, etc.) is unchanged. site.site_media scan columns are left in place —
-- they are harmless without the pipeline and will be used when CSAM is re-added.

BEGIN;

DROP TABLE IF EXISTS moderation.ncmec_submissions;
DROP TABLE IF EXISTS moderation.csam_quarantine;

COMMIT;
```

## Step 2: Archive the CSAM migration files

Move these four files from `supabase/migrations/` to `supabase/migrations-archive/`:

- `20260529000000_create_csam_quarantine_table.sql`
- `20260529000001_create_csam_quarantine_indexes.sql`
- `20260529010000_create_ncmec_submissions_table.sql`
- `20260529010001_create_ncmec_submissions_indexes.sql`

Use `git mv` so history is preserved.

## Step 3: Delete these files entirely

```
app/Models/Moderation/CsamQuarantine.php
app/Models/Moderation/NcmecSubmission.php
database/factories/Moderation/CsamQuarantineFactory.php
database/factories/Moderation/NcmecSubmissionFactory.php
app/Services/Moderation/CsamMatchHandlerService.php
app/Services/Moderation/NcmecSubmissionService.php
app/Services/Moderation/NcmecSubmissionFailedTooManyTimes.php
app/Services/Moderation/R2QuarantineService.php
app/Services/Cloudflare/CloudflareCsamScanClient.php
app/Services/Media/SiteMediaService.php
app/Jobs/Moderation/FileCyberTipReportJob.php
app/Jobs/Moderation/PromoteCleanMediaJob.php
app/DTOs/Moderation/CloudflareCsamMatchDto.php
app/Http/Controllers/Api/Internal/CloudflareCsamWebhookController.php
app/Http/Middleware/Cloudflare/VerifyCloudflareWebhookSignature.php
app/Http/Middleware/Moderation/EnforceCsamScanGate.php
app/Console/Commands/Moderation/ModerationAuditQuarantineBucketCommand.php
app/Console/Commands/Moderation/ModerationExpireCsamQuarantineCommand.php
app/Console/Commands/Moderation/ModerationRetryNcmecSubmissionsCommand.php
docs/moderation/csam-pipeline.md
```

Also update the docblock in `app/Services/Moderation/CaseStateMachine.php` line 9 — change:
```php
 * Used by ModerationCaseService and CsamMatchHandlerService — the only legal write paths.
```
to:
```php
 * Used by ModerationCaseService — the only legal write path.
```

Also delete these test files:
```
tests/Feature/Commands/Moderation/AuditQuarantineBucketCommandTest.php
tests/Feature/Commands/Moderation/ExpireCsamQuarantineCommandTest.php
tests/Feature/Commands/Moderation/RetryNcmecSubmissionsCommandTest.php
tests/Feature/Moderation/CloudflareCsamWebhookTest.php
tests/Feature/Moderation/CsamAutoActionPipelineTest.php
tests/Feature/Moderation/CsamQuarantineSchemaTest.php
tests/Feature/Moderation/EnforceCsamScanGateTest.php
tests/Feature/Moderation/FileCyberTipReportJobTest.php
tests/Feature/Moderation/PromoteCleanMediaJobTest.php
tests/Feature/Moderation/SiteMediaUploadQuarantineTest.php
tests/Feature/Security/WebhookSignatureForgeryTest.php
tests/Unit/Models/Moderation/CsamModelsTest.php
tests/Unit/Services/Moderation/NcmecSubmissionServiceTest.php
tests/Unit/Services/Moderation/R2QuarantineServiceTest.php
```

## Step 4: Edit `routes/api.php`

Remove the CSAM webhook use statement and route. Specifically remove:

```php
use App\Http\Middleware\Moderation\EnforceCsamScanGate;
```

And the two middleware applications on existing routes:
```php
->middleware(['throttle:public-site', EnforceCsamScanGate::class]);
// and
->middleware(['throttle:public-profile', EnforceCsamScanGate::class]);
```
(Restore them to `->middleware('throttle:public-site')` and `->middleware('throttle:public-profile')` respectively.)

Also remove the entire CSAM webhook block:
```php
// Cloudflare CSAM webhook — receives hash-match callbacks from Cloudflare's
// Image Resizing CSAM scanning pipeline. Signature-gated via HMAC-SHA256;
// replay prevention via Redis nonce store.
Route::post('/v1/internal/cloudflare-csam-webhook',
    [\App\Http\Controllers\Api\Internal\CloudflareCsamWebhookController::class, 'handle']
)->middleware([\App\Http\Middleware\Cloudflare\VerifyCloudflareWebhookSignature::class, 'throttle:webhooks'])
 ->name('internal.cloudflare.csam.webhook');
```

## Step 5: Edit `routes/console.php`

Remove the four CSAM-related scheduled entries (lines 175–221). Keep everything else.

The blocks to remove are:
1. `PromoteCleanMediaJob` schedule (everyMinute)
2. `moderation:expire-csam-quarantine` schedule (dailyAt 03:00)
3. `moderation:audit-quarantine-bucket` schedule (dailyAt 04:00)
4. `moderation:retry-ncmec-submissions` schedule (everyFiveMinutes)

## Step 6: Edit `config/filesystems.php`

Remove the entire `r2_quarantine` disk block:

```php
        'r2_quarantine' => [
            'driver'   => 's3',
            'key'      => env('R2_QUARANTINE_ACCESS_KEY_ID'),
            'secret'   => env('R2_QUARANTINE_SECRET_ACCESS_KEY'),
            'region'   => 'auto',
            'bucket'   => env('R2_QUARANTINE_BUCKET', 'partna-media-quarantine'),
            'endpoint' => env('R2_QUARANTINE_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw'    => true,
        ],
```

Also remove the comment block above it:
```php
        /*
        |----------------------------------------------------------------------
        | Quarantine disk – separate R2 bucket for CSAM / flagged media
        |----------------------------------------------------------------------
        ...
        */
```

## Step 7: Edit `config/partna.php`

Remove the entire `csam` sub-block from inside the `moderation` key. It starts with:
```php
            'csam' => [
                'enabled' => ...
```
and ends with the closing `],` for the csam array (approximately 30 lines). Keep the rest
of the `moderation` config untouched.

## Step 8: Edit `.env.example`

Remove the CSAM pipeline block (approximately lines added in the last commit). Specifically remove:

```
# CSAM scanning pipeline — see docs/moderation/csam-pipeline.md
PARTNA_CSAM_SCAN_ENABLED=false
PARTNA_CSAM_CLOUDFLARE_WEBHOOK_SECRET=
R2_QUARANTINE_BUCKET=partna-media-quarantine
R2_QUARANTINE_ACCESS_KEY_ID=
R2_QUARANTINE_SECRET_ACCESS_KEY=
R2_QUARANTINE_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
NCMEC_API_KEY=
NCMEC_ESP_ID=
NCMEC_CYBERTIP_ENDPOINT=https://hashmatching.api.missingkids.org/cybertip
```

## Step 9: Edit `app/Services/PublicSite/SitepageDataResolverService.php`

Remove the `applyCsamScanGate` private method and all three call sites. The method is:

```php
    // ── CSAM scan gate ───────────────────────────────────────────────────

    /**
     * Defence-in-depth WHERE clause applied to every public media query.
     * ...
     */
    private function applyCsamScanGate(\Illuminate\Database\Eloquent\Builder $q): void
    {
        $cutoff = config('partna.moderation.csam.grandfather_cutoff', '2026-05-26 00:00:00');

        $q->where(function ($inner) use ($cutoff): void {
            $inner->whereNotNull('scanned_at')
                ->orWhere(function ($g) use ($cutoff): void {
                    $g->whereNull('scanned_at')
                        ->where('created_at', '<', $cutoff);
                });
        });
    }
```

Remove each call site (three places):
```php
->where(fn ($q) => $this->applyCsamScanGate($q))
```
Simply delete these lines from their respective query chains.

## Step 10: Edit `app/Models/Core/Site/SiteMedia.php`

Remove the two CSAM-specific constants:

```php
    // Awaiting CSAM hash-match scan in the quarantine bucket.
    // Transition: scanning → pending (clean) via CsamScanCompleteJob, or scanning → quarantined (match).
    public const PROCESSING_STATE_SCANNING = 'scanning';
```

```php
    public const PROCESSING_STATE_QUARANTINED = 'quarantined';
```

Keep all other constants and the model body unchanged.

## Step 11: Edit `tests/Pest.php`

Remove the two CSAM-specific helper function definitions.

Functions to remove entirely:
- `setupCsamQuarantineTable()` — the full function definition (approximately lines 1610–1637)
- `setupNcmecSubmissionsTable()` — the full function definition (approximately lines 1638–1665)

**Note:** Do NOT look for call sites in `setupAllModerationTables()` — these helpers are NOT
called from there. They are called directly by `CsamModelsTest.php`, which is deleted in Step 3,
so the call sites are already gone.

## Step 12: Edit `tests/Feature/Security/PolicyCoverageTest.php`

Remove the two CSAM models from the `POLICY_EXEMPT` list:
```php
    \App\Models\Moderation\CsamQuarantine::class,  // system-generated legal compliance record
    \App\Models\Moderation\NcmecSubmission::class, // system-generated legal compliance record
```

## Step 13: Edit `docs/moderation/README.md`

Update the status table — change:
```
| **CSAM scanning + NCMEC outbox** | ✅ live |
```
to:
```
| **CSAM scanning + NCMEC outbox** | 🔜 deferred |
```

Remove the reference link to `docs/moderation/csam-pipeline.md`.

## Step 14: Verify

```bash
composer test
```

Expected: all tests pass. If any test references a deleted file or class, fix the import.
Also confirm no remaining references to deleted classes:

```bash
grep -rn "CsamQuarantine\|NcmecSubmission\|R2QuarantineService\|CsamMatchHandler\|FileCyberTipReport\|PromoteCleanMedia\|EnforceCsamScanGate\|VerifyCloudflareWebhookSignature\|CloudflareCsamMatchDto\|NcmecSubmissionService\|CloudflareCsamScanClient\|ModerationAuditQuarantine\|ModerationExpireCsam\|ModerationRetryNcmec" app/ routes/ config/ tests/ --include="*.php" | grep -v "migrations-archive"
```

Expected: no output.

## Step 15: Commit and push

```bash
git add -A
git commit -m "chore(moderation): remove CSAM pipeline — deferred for re-implementation"
git push -u origin feat/remove-csam-pipeline
gh pr create --base development \
  --title "chore(moderation): remove CSAM pipeline (deferred)" \
  --body "Removes the Cloudflare-webhook-based CSAM scanning pipeline. The moderation foundation (cases, decisions, auto-actions) is unchanged. CSAM scanning will be re-added using a hash-matching API approach (Thorn/PhotoDNA) at a later date.

Tables dropped: moderation.csam_quarantine, moderation.ncmec_submissions.
site.site_media.scanned_at column and scan states are left in schema (harmless, will be used on re-implementation).

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```

## Step 16: Push the reversal migration to dev Supabase

After the PR is merged, the user needs to run (interactively with ! prefix):
```
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

This will apply `20260529200000_remove_csam_pipeline_tables.sql` and drop the two tables from dev.
