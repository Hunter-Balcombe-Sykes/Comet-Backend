<?php

namespace App\Console\Commands;

use App\Models\Core\Professional\BrandProfile;
use App\Services\Professional\Brand\BrandSignupCodeService;
use Illuminate\Console\Command;

/**
 * One-shot backfill: assign a unique signup code to every brand that doesn't have one.
 *
 * Why we have this:
 *   BrandProfile::creating hook generates codes for new brands, but existing brands
 *   (created before this column was added) have NULL signup_code. This command fills
 *   those in so the migration step 3 (NOT NULL + UNIQUE constraint) can proceed.
 *
 * Safe to re-run:
 *   Queries only rows WHERE signup_code IS NULL, so re-runs are no-ops for already-backfilled brands.
 *
 * Run order:
 *   1. Push migration 20260522000000 (add columns nullable).
 *   2. php artisan brand:backfill-signup-codes
 *   3. Push migration 20260522000002 (CONCURRENTLY index).
 *   4. Push migration 20260522000003 (promote to constraint + NOT NULL).
 */
class BackfillBrandSignupCodes extends Command
{
    protected $signature = 'brand:backfill-signup-codes
        {--dry-run : Log what would be written without touching the database.}';

    protected $description = 'Assign a unique signup code to every brand profile that has a NULL signup_code.';

    public function handle(BrandSignupCodeService $codeService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $total = BrandProfile::query()->whereNull('signup_code')->count();

        if ($total === 0) {
            $this->info('All brand profiles already have a signup code. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Backfilling signup codes for %d brand(s)%s.',
            $total,
            $dryRun ? ' (DRY RUN)' : ''
        ));

        $count = 0;

        BrandProfile::query()->whereNull('signup_code')->cursor()->each(function (BrandProfile $brand) use ($codeService, $dryRun, &$count): void {
            $code = $codeService->generate();

            if ($dryRun) {
                $this->line(sprintf('  [dry-run] would assign code to brand_profile %s', $brand->id));
                $count++;

                return;
            }

            // saveQuietly() to avoid triggering model observers (e.g. cache invalidation
            // at scale). The creating hook does NOT fire on save — intentional.
            $brand->signup_code = $code;
            $brand->saveQuietly();

            $count++;

            if ($count % 100 === 0) {
                $this->line(sprintf('  ... processed %d/%d', $count, $total));
            }
        });

        $this->info(sprintf(
            'Done. Assigned codes to %d brand(s).%s',
            $count,
            $dryRun ? ' (DRY RUN — nothing written)' : ''
        ));

        return self::SUCCESS;
    }
}
