<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Profile\SectorTaxonomy;
use App\Support\BusinessName;
use Illuminate\Support\Facades\DB;

/**
 * Central-identity precedence engine: folds a Google Business payload into the
 * canonical identity stores (the user's site.workplaces row + a few core.users
 * mirror columns), so identity edited on Google flows to one place instead of
 * living only inside the integration payload.
 *
 * Precedence is account-type-driven, but the type is read in EXACTLY one spot —
 * `AccountCapabilities::for($user)->google_business_full_sync` (true for
 * Business Partna). That single boolean, `$overwrite`, decides every field:
 *   - business ($overwrite = true)  → Google is authoritative; overwrite.
 *   - partna   ($overwrite = false) → Google fills gaps only; never clobbers a
 *                                     value the user set by hand.
 *
 * Every field actually written is stamped in workplaces.field_sources so the
 * dashboard can render a "Synced from Google" badge and future per-platform
 * precedence has provenance to reason about.
 *
 * Best-effort by contract: the whole apply is wrapped in try/catch and reports
 * on failure, because it runs from the connection-save observer — an identity
 * fold must NEVER break the connect/refresh it rides on.
 */
class IdentitySync
{
    private const GOOGLE_SOURCE = 'google-business';

    // Google's opening-hours `period.open.day` / `period.close.day` are 0=Sunday
    // .. 6=Saturday. We store per-day under these slugs.
    private const DAY_SLUGS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * Fold a google-business payload into the user's identity stores. Silent
     * no-op on anything malformed — see class docblock for the contract.
     *
     * @param  array<string, mixed>  $gbPayload  the stored google-business connection payload
     */
    public function applyFromGooglePayload(User $user, array $gbPayload): void
    {
        try {
            $site = $user->site;
            if ($site === null) {
                return; // No site → no workplace row to anchor identity on.
            }

            $overwrite = AccountCapabilities::for($user)->google_business_full_sync;

            // Candidate identity fields Google can provide, mapped to workplace
            // columns. Absent keys (null) are skipped so we never write a null
            // over a stored value. Email is deliberately NOT here — Places never
            // returns one, so contact_email stays manual-only. Address is
            // written as structured columns (never a flat string — that column
            // was dropped 2026-07-23, signup testing repairs item 1) from
            // addressParts, which sits in this same payload alongside the
            // formatted string GoogleBusinessService also maps.
            $candidates = [
                'name' => $this->nameOrNull($gbPayload['name'] ?? null),
                ...$this->addressPartsCandidates($gbPayload['addressParts'] ?? null),
                'phone' => $this->stringOrNull($gbPayload['phone'] ?? null),
                'website' => $this->stringOrNull($gbPayload['website'] ?? null),
                'category' => $this->stringOrNull($gbPayload['category'] ?? null),
                'latitude' => $this->floatOrNull($gbPayload['lat'] ?? null),
                'longitude' => $this->floatOrNull($gbPayload['lng'] ?? null),
                'opening_hours' => $this->deriveOpeningHours($gbPayload['hours'] ?? null),
            ];

            // LIFE-108: fold onto site.workplaces under a locked re-read.
            $this->applyWorkplaceFields($site, $candidates, $overwrite);

            // Sector lives on the user, not the workplace — same precedence
            // shape, its own store + provenance column. Mapping is pure, so it
            // stays outside the lock LIFE-107 takes below.
            $mappedSector = SectorTaxonomy::fromGoogleCategory($this->stringOrNull($gbPayload['category'] ?? null));

            // LIFE-107: sector + the phone mirror below both live on core.users
            // and share the identical read-then-write precedence shape, so both
            // fold under ONE locked re-read of the row — never $user itself
            // (see applyUserIdentityFields()'s docblock for why that matters:
            // this observer fires on every connection save, and the hourly
            // integrations:refresh job runs the same fold, so a concurrent
            // second fold for the same user is a real race, not theoretical).
            //
            // display_name is intentionally untouched here — GoogleBusinessController
            // ::maybeAdoptGoogleName owns the business name → display_name mirror.
            $this->applyUserIdentityFields($user, $overwrite, $mappedSector, $candidates['phone']);
        } catch (\Throwable $e) {
            report($e); // Must never break the connection save that triggered us.
        }
    }

    /**
     * LIFE-108: re-read site.workplaces under `lockForUpdate` inside a
     * transaction before applying the per-field precedence — the read (used to
     * decide "is the current value blank?" for a partna account) is the check
     * half of a check-then-write, and without the lock a concurrent fold (a
     * second connection save, or the hourly `integrations:refresh` job racing
     * this same user) can read the same stale blank and both writers "win",
     * with whichever commits last silently discarding the other's fields.
     *
     * RACE NOTE: `lockForUpdate()` locks an EXISTING row — it locks nothing for
     * a site that has never had a workplace row, so two simultaneous
     * first-ever syncs can still both attempt an INSERT. That's bounded by
     * `site.workplaces.site_id` being the PRIMARY KEY (not a separate unique
     * index — verified in supabase/migrations/20260701150000_create_workplaces.sql
     * and the setupWorkplacesTable() test stub): the loser gets a duplicate-key
     * exception rather than a silently lost update, and that exception is
     * caught by applyFromGooglePayload's outer try/catch (best-effort by
     * contract — see class docblock).
     *
     * Kept SHORT by design (see class docblock: this runs off the connection-
     * save observer on every payload change, and hourly off integrations:refresh
     * at real volume) — no HTTP/queue work happens inside the transaction.
     *
     * @param  array<string,mixed>  $candidates  field => value|null; pure (site + Google payload only), safe to compute outside the lock
     */
    private function applyWorkplaceFields(Site $site, array $candidates, bool $overwrite): void
    {
        DB::connection($site->getConnectionName())->transaction(function () use ($site, $candidates, $overwrite) {
            $workplace = Workplace::query()->where('site_id', (string) $site->id)->lockForUpdate()->first()
                ?? new Workplace(['site_id' => (string) $site->id]);

            $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
            $stamp = now()->toIso8601String();
            $changed = false;

            foreach ($candidates as $field => $value) {
                if ($value === null) {
                    continue; // Google didn't provide this field (or malformed).
                }

                // Per-field precedence: business overwrites; partna fills only
                // when the current column value is blank.
                if (! $overwrite && ! $this->isBlank($workplace->{$field})) {
                    continue;
                }

                $workplace->{$field} = $value;
                $sources[$field] = ['source' => self::GOOGLE_SOURCE, 'at' => $stamp];
                $changed = true;
            }

            if ($changed) {
                $workplace->field_sources = $sources;
                $workplace->save();
            }
        });
    }

    /**
     * LIFE-107: applySector() + mirrorPublicContactNumber() both read-then-write
     * the SAME core.users row under the SAME account-type precedence — folded
     * into one locked re-read so a concurrent fold for this user can't read a
     * value either write is about to make stale (the "manual sector pick"
     * guard is the sharpest instance: it must see a `sector_source` a
     * concurrent request just set to 'manual', not the value $user had when
     * the observer loaded it — see IdentitySyncConcurrencyTest for the race
     * this closes).
     *
     * FOOTGUN CLOSED BY DESIGN, not by luck: both applySector() and
     * mirrorPublicContactNumber() below are called with $fresh (the locked
     * row), never with the caller's $user instance — so neither ever marks
     * $user's own sector/sector_source/public_contact_number attributes dirty.
     * That means a caller who kept holding $user across this call and later
     * calls $user->save() for an unrelated reason cannot clobber what this
     * method just committed (Eloquent's save() only persists attributes dirty
     * on THAT instance). $user is refreshed below regardless, so this isn't
     * left as an implicit invariant for the next reader to rediscover.
     */
    private function applyUserIdentityFields(User $user, bool $overwrite, ?string $mappedSector, ?string $phone): void
    {
        DB::connection($user->getConnectionName())->transaction(function () use ($user, $overwrite, $mappedSector, $phone) {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($fresh === null) {
                return; // Raced with a hard delete mid-sync — nothing left to fold onto.
            }

            $this->applySector($fresh, $overwrite, $mappedSector);
            $this->mirrorPublicContactNumber($fresh, $overwrite, $phone);
        });

        $user->refresh();
    }

    /**
     * Sector precedence (widened 2026-07-20 — any non-Google source is now
     * permanent, not just 'manual'): apply precedence in two steps against
     * $mappedSector (already resolved from Google's category by
     * SectorTaxonomy::fromGoogleCategory — see applyUserIdentityFields,
     * LIFE-107: that mapping is pure so it happens OUTSIDE the lock this
     * method now runs under). First, any sector_source that is neither null
     * nor Google's own (`self::GOOGLE_SOURCE`) is NEVER overwritten by a
     * business account's Google resync — originally this only protected
     * 'manual' (the 2026-07-15 fix: business used to silently revert a
     * user's own pick on every resync), but the same clobbering bug applies
     * to any other automated source (e.g. a sector seeded by an Instagram
     * connect ahead of Google during pre-account signup): first non-Google
     * source to write wins. Only once that's ruled out does the account-type
     * rule apply: business overwrites a blank value or one it previously
     * stamped itself; partna fills only when sector is currently blank.
     * Provenance in users.sector_source.
     *
     * $user MUST be the locked $fresh row from applyUserIdentityFields — the
     * source guard below reads $user->sector_source, and evaluating it
     * against a stale pre-lock instance is exactly the race LIFE-107 closes.
     */
    private function applySector(User $user, bool $overwrite, ?string $mappedSector): void
    {
        if ($mappedSector === null) {
            return;
        }

        if ($overwrite) {
            // Any other source's stamped value is permanent from Google's
            // perspective — first non-Google source to write wins. Google may
            // still fill a blank sector or replace one it set itself on an
            // earlier sync. The `sector !== null` leg is defensive: provenance
            // can be stamped ahead of the value being cleared elsewhere, and a
            // (null, <source>) row must not block Google's fill forever.
            if ($user->sector_source !== null && $user->sector_source !== self::GOOGLE_SOURCE && $user->sector !== null) {
                return;
            }

            if ($user->sector !== $mappedSector) {
                $user->sector = $mappedSector;
                $user->sector_source = self::GOOGLE_SOURCE;
                $user->save();
            }

            return;
        }

        // partna: fill only when nothing is set yet — inherently safe
        // regardless of the current source, so no source check needed here.
        if ($this->isBlank($user->sector)) {
            $user->sector = $mappedSector;
            $user->sector_source = self::GOOGLE_SOURCE;
            $user->save();
        }
    }

    /**
     * Mirror the workplace phone onto users.public_contact_number under the same
     * per-field rule, so both the workplace card and the sitepage contact block
     * read one number.
     */
    private function mirrorPublicContactNumber(User $user, bool $overwrite, ?string $phone): void
    {
        if ($phone === null) {
            return;
        }

        if (! $overwrite && ! $this->isBlank($user->public_contact_number)) {
            return;
        }

        if ($user->public_contact_number !== $phone) {
            $user->public_contact_number = $phone;
            $user->save();
        }
    }

    /**
     * Derive structured per-day opening hours from Google's `hours.periods`.
     * Google shape: [{open:{day:1,hour:9,minute:0}, close:{day:1,hour:17,minute:0}}, …],
     * day 0=Sunday..6=Saturday. Output: {"mon":[{"open":"0900","close":"1700"}], …}.
     * Best-effort — returns null when periods are missing or unusable so a
     * malformed block never overwrites stored hours.
     *
     * @return array<string, list<array{open: string, close: string}>>|null
     */
    private function deriveOpeningHours(mixed $hours): ?array
    {
        $periods = is_array($hours) ? ($hours['periods'] ?? null) : null;
        if (! is_array($periods) || $periods === []) {
            return null;
        }

        $result = [];
        foreach ($periods as $period) {
            if (! is_array($period)) {
                continue;
            }

            $open = $this->hhmm($period['open'] ?? null);
            $close = $this->hhmm($period['close'] ?? null);
            $daySlug = $this->daySlug($period['open'] ?? null);
            // A period missing an open time or a resolvable day is unusable.
            // A 24/7 place has an open with no close — skip those here rather
            // than invent a closing time; the weekday descriptions still carry it.
            if ($open === null || $close === null || $daySlug === null) {
                continue;
            }

            $result[$daySlug][] = ['open' => $open, 'close' => $close];
        }

        return $result === [] ? null : $result;
    }

    /** Format a Google {hour,minute} time part as a zero-padded HHMM string. */
    private function hhmm(mixed $part): ?string
    {
        if (! is_array($part) || ! isset($part['hour'])) {
            return null;
        }

        $hour = (int) $part['hour'];
        $minute = (int) ($part['minute'] ?? 0);
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d%02d', $hour, $minute);
    }

    /** Resolve a Google time part's `day` (0=Sun..6=Sat) to a weekday slug. */
    private function daySlug(mixed $part): ?string
    {
        if (! is_array($part) || ! isset($part['day'])) {
            return null;
        }

        $day = (int) $part['day'];

        return self::DAY_SLUGS[$day] ?? null;
    }

    /**
     * Split Google's `addressParts` (lines/suburb/state/postcode/country —
     * see GoogleBusinessService::mapDetails()) into the workplace's structured
     * columns. `lines` is a list; only the first is used for address_line1,
     * matching the single street-address-line shape manual entry also uses.
     *
     * @return array{address_line1: ?string, city: ?string, state: ?string, postcode: ?string, country: ?string}
     */
    private function addressPartsCandidates(mixed $addressParts): array
    {
        if (! is_array($addressParts)) {
            return ['address_line1' => null, 'city' => null, 'state' => null, 'postcode' => null, 'country' => null];
        }

        $lines = $addressParts['lines'] ?? null;
        $firstLine = is_array($lines) ? ($lines[0] ?? null) : null;

        return [
            'address_line1' => $this->stringOrNull($firstLine),
            'city' => $this->stringOrNull($addressParts['suburb'] ?? null),
            'state' => $this->stringOrNull($addressParts['state'] ?? null),
            'postcode' => $this->stringOrNull($addressParts['postcode'] ?? null),
            'country' => $this->stringOrNull($addressParts['country'] ?? null),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Business names cap at 15 chars. Auto-adopted from Google, so it can't
     * be rejected mid-sync like manual entry — word-trimmed instead.
     */
    private function nameOrNull(mixed $value): ?string
    {
        $name = $this->stringOrNull($value);

        return $name !== null ? BusinessName::wordTrim($name) : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** True when a stored column value is null, an empty string, or whitespace. */
    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return is_string($value) ? trim($value) === '' : false;
    }
}
