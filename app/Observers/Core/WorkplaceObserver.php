<?php

namespace App\Observers\Core;

use App\Jobs\Platforms\ScanPreviousWebsiteContentJob;
use App\Models\Core\Site\Workplace;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\SiteCacheInvalidator;
use Illuminate\Support\Facades\Log;

// Dispatches the previous-website content scan (about text, menu, links,
// accent colour, logo candidates — see ScanPreviousWebsiteContentJob) off a
// GENUINE previous_website change — no staleness/version reconciliation:
// fill-if-empty writes are permanent, not periodically re-synced, so there's
// no "current analysis vs current URL" state to keep in step any more (that
// concept belonged entirely to the deleted headless-browser design-scan
// pipeline this observer used to trigger).
//
// ALSO busts the public-page cache when a workplace field that renders on the
// sitepage changes. The workplace card (address/hours/phone/description/…) feeds
// the public payload, but editing an ALREADY-ENABLED card never touched any
// cache layer before: UserWorkplaceController relies on SectionVisibilityService
// which only saves the Block (→ BlockObserver → purge) when the enabled-state
// flips, so a plain hours/address edit went live-invisible until an unrelated
// mutation happened to touch the site.
class WorkplaceObserver
{
    public bool $afterCommit = true;

    // Columns whose change is visible on the public sitepage. A save touching
    // ONLY other columns (previous_website + its field_sources provenance —
    // design-factor inputs, never rendered) skips the purge.
    private const CACHE_AFFECTING_COLUMNS = [
        'name', 'address_line1', 'city', 'state', 'postcode', 'country',
        'latitude', 'longitude', 'phone', 'website', 'category', 'description',
        'opening_hours', 'contact_email',
    ];

    public function __construct(
        private readonly SiteCacheInvalidator $invalidator,
    ) {}

    public function saved(Workplace $workplace): void
    {
        // wasChanged() is unreliable immediately after create() — getChanges()
        // reads empty on a fresh insert on this model (confirmed directly: a
        // Workplace::create(['previous_website' => '...']) reports
        // wasChanged('previous_website') === false, while a genuine later
        // update to the same column correctly reports true). A brand-new
        // workplace created WITH a previous_website already set (e.g. from an
        // import) must still trigger the scan, so wasRecentlyCreated covers
        // that case explicitly rather than trusting wasChanged() for it.
        $hasUrl = trim((string) $workplace->previous_website) !== '';
        $isNewWithUrl = $workplace->wasRecentlyCreated && $hasUrl;
        $changedToUrl = ! $workplace->wasRecentlyCreated && $workplace->wasChanged('previous_website') && $hasUrl;
        if ($isNewWithUrl || $changedToUrl) {
            $this->dispatchContentScan($workplace);
        }

        // wasRecentlyCreated covers first insert (whole card is new content);
        // wasChanged() gates updates to the public-visible columns only.
        if ($workplace->wasRecentlyCreated || $workplace->wasChanged(self::CACHE_AFFECTING_COLUMNS)) {
            $this->invalidator->touchSite(
                fn () => $workplace->site,
                'workplace-save',
                ['site_id' => $workplace->site_id],
            );
        }

        // Keep User.public_contact_number/email (what the public sitepage
        // actually renders) in step with Workplace.phone/contact_email for
        // EVERY writer, not just the dashboard's own save path (which used
        // to be the only caller of this mirror — UserWorkplaceController's
        // own mirrorContactFields() was removed in favour of this, so a
        // scan/sync-written contact_email now reaches the public page
        // automatically too, same as a manual edit always did).
        if ($workplace->wasRecentlyCreated || $workplace->wasChanged(['phone', 'contact_email'])) {
            $this->mirrorContactFields($workplace);
        }
    }

    /**
     * Workplace contact column -> the core.users column the PUBLIC PAGE renders.
     *
     * Per-field, and precedence-aware since 2026-08-19. It used to assign both
     * columns unconditionally, which silently defeated IdentitySync's documented
     * partna contract ("Google fills gaps only; never clobbers a value the user
     * set by hand"). That guard is enforced on site.workplaces, and the save that
     * enforces it fires THIS observer — before IdentitySync reaches its own user
     * mirror. So an automated write that legitimately filled a blank workplace
     * phone went on to overwrite a hand-typed public_contact_number, and
     * IdentitySync's guard then no-opped with nothing left to protect. Pinned by
     * "a partna google connect does not clobber a hand-typed public contact
     * number" in tests/Feature/Platforms/IdentitySyncTest.php.
     */
    private function mirrorContactFields(Workplace $workplace): void
    {
        try {
            $user = $workplace->site?->user;
            if ($user === null) {
                return;
            }

            // data_get, not is_array + offsets: field_sources is a jsonb column
            // whose @property annotation is a claim about its writers, not a
            // constraint the DB enforces, and data_get degrades to null on a
            // null/scalar value instead of throwing on an illegal offset.
            // The one sanctioned account_type read (CLAUDE.md): true for Business
            // Partna, which grants external listings authority over identity.
            // Named for Google because that is the precedence it was introduced
            // for, but the question here is the general one — "may an automated
            // source outrank the user?" — and the answer is the same per type for
            // every writer that stamps field_sources.
            $externalWins = AccountCapabilities::for($user)->google_business_full_sync;

            $dirty = false;

            foreach (['phone' => 'public_contact_number', 'contact_email' => 'public_contact_email'] as $field => $column) {
                if ($user->{$column} === $workplace->{$field}) {
                    continue;
                }
                if (! $this->mayMirror($user->{$column}, data_get($workplace->field_sources, "{$field}.source"), $externalWins)) {
                    continue;
                }
                $user->{$column} = $workplace->{$field};
                $dirty = true;
            }

            if ($dirty) {
                $user->save();
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('WorkplaceObserver contact-mirror failed', [
                'site_id' => $workplace->site_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * May a workplace write replace what is already on the user's public column?
     *
     * @param  string|null  $source  field_sources provenance for the workplace field
     *                               ('manual' | 'google-business' | 'website-scan' | 'instagram' | null)
     */
    private function mayMirror(?string $current, ?string $source, bool $externalWins): bool
    {
        // Nothing of the user's to protect.
        if ($current === null || $current === '') {
            return true;
        }

        // They just typed it on the workplace card — that IS the intent, and it
        // is the case the original unconditional mirror existed to serve.
        if ($source === 'manual') {
            return true;
        }

        // Otherwise an automated source is proposing the change; only a business
        // account has granted external listings that authority.
        return $externalWins;
    }

    public function deleted(Workplace $workplace): void
    {
        // Row gone → URL gone. Fill-if-empty writes are permanent (not a
        // reconciled analysis document), so unlike the old design-scan
        // reconciliation this observer used to do, there is nothing
        // scan-related to (re-)dispatch here.
        $this->invalidator->touchSite(
            fn () => $workplace->site,
            'workplace-delete',
            ['site_id' => $workplace->site_id],
        );
    }

    // Observer must never crash the parent write.
    private function dispatchContentScan(Workplace $workplace): void
    {
        try {
            ScanPreviousWebsiteContentJob::dispatch(
                (string) $workplace->site->user_id,
                (string) $workplace->site_id,
                trim((string) $workplace->previous_website),
            );
        } catch (\Throwable $e) {
            report($e);
            Log::warning('WorkplaceObserver content-scan dispatch failed', [
                'site_id' => $workplace->site_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
