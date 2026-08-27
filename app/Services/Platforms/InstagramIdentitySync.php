<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Profile\BioIntelligence;
use App\Services\Profile\FoodContentProbe;
use App\Services\Profile\SectorProvenance;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fold Instagram-scraped identity fields into a user's core.users columns.
 * Instagram is the LOWEST-ranked sector source (see SectorProvenance): it fills
 * a blank, and loses to Google Business and to a human's pick. It may not even
 * refresh its own earlier value — PARTNA_INSTAGRAM_ACTOR is a no-deploy
 * rollback whose two actors return different keys.
 */
class InstagramIdentitySync
{
    private const INSTAGRAM_SOURCE = 'instagram';

    public function __construct(private readonly FoodContentProbe $foodContent) {}

    public function applyIdentity(User $user, array $payload): void
    {
        // InstagramConnectionSeeder passes the RAW Apify profile node here, not
        // the normalised $selection array — so this reads the same two shapes
        // InstagramScraper already tolerates for other fields: the figue actor's
        // raw Instagram GraphQL snake_case, and the legacy camelCase actor's
        // shape. Legacy first, matching that precedent.

        // Category lives under a different key per actor, and the figue actor
        // returns business_category_name NULL while putting the real value in
        // category_name (verified 2026-08-11 against its last live run:
        // simondoylehair → business_category_name null, category_name "Hair
        // Stylist"). That is why sector_source='instagram' never once succeeded
        // on dev until the 2026-08-10 swap to the apify actor. Since
        // PARTNA_INSTAGRAM_ACTOR is a no-deploy rollback, reading only the first
        // two keys would silently switch sector detection back off on rollback.
        $this->applySector(
            $user,
            [
                $payload['businessCategoryName'] ?? null,
                $payload['business_category_name'] ?? null,
                $payload['category_name'] ?? null,
            ],
            $this->stringOrNull($payload['username'] ?? null),
            $this->stringOrNull($payload['fullName'] ?? $payload['full_name'] ?? null),
        );
        $this->applyDisplayName($user, $this->stringOrNull(
            $payload['fullName'] ?? $payload['full_name'] ?? null
        ));
        $this->applyHandle($user, $this->stringOrNull($payload['username'] ?? null));
        $this->applyContactFields($user, $payload);
        $this->applyBioIntelligence($user, $payload);
    }

    /**
     * T13/T16 (2026-08-27, D8): fill an EMPTY About / public-contact from the
     * biography — builds AND any later IG connect where they are still empty.
     * Only-fill-empty is the whole ownership contract: an owner-authored value
     * is never touched. Names are deliberately NOT changed here — for a
     * claimed account the owner's identity is theirs; only the pre-account
     * generator (no owner yet) writes AI names. Instagram withholds business
     * email/phone from logged-out scrapes (applyContactFields' docblock), so
     * the bio TEXT — gated to literal presence — is the only contact source.
     */
    private function applyBioIntelligence(User $user, array $payload): void
    {
        $biography = $this->stringOrNull($payload['biography'] ?? $payload['bio'] ?? null);
        if ($biography === null) {
            return;
        }
        $needsBio = $this->isBlank($user->bio);
        $needsEmail = $this->isBlank($user->public_contact_email);
        $needsPhone = $this->isBlank($user->public_contact_number);
        if (! $needsBio && ! $needsEmail && ! $needsPhone) {
            return;
        }

        $intel = app(BioIntelligence::class)->analyse(
            (string) ($payload['username'] ?? $user->handle),
            $this->stringOrNull($payload['fullName'] ?? $payload['full_name'] ?? null),
            $biography,
        );

        $changed = false;
        if ($needsBio && $intel['about'] !== null) {
            $user->bio = $intel['about'];
            $changed = true;
        }
        if ($needsEmail && $intel['email'] !== null) {
            $user->public_contact_email = $intel['email'];
            $changed = true;
        }
        if ($needsPhone && $intel['phone'] !== null) {
            $user->public_contact_number = $intel['phone'];
            $changed = true;
        }
        if ($changed) {
            $user->save();
        }
    }

    /**
     * Fold a sector under the shared ladder (manual > google > instagram).
     *
     * Locked like IdentitySync's fold (LIFE-107): this used to read and write
     * the caller's instance, so a stale blank read could clobber a value Google
     * had just committed — a live ordering, since GoogleBusinessAutoSync
     * dispatches the Instagram connect after Google's own fold on an unclaimed
     * business build.
     *
     * @param  list<mixed>  $categoryCandidates
     */
    private function applySector(User $user, array $categoryCandidates, ?string $username, ?string $fullName): void
    {
        $mapped = SectorTaxonomy::fromInstagramProfile($categoryCandidates, $username, $fullName);
        if ($mapped === null) {
            return;
        }

        $isBusiness = AccountCapabilities::for($user)->google_business_full_sync;
        $sectorBefore = $user->sector;

        DB::connection($user->getConnectionName())->transaction(function () use ($user, $mapped, $isBusiness) {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($fresh === null) {
                return; // Raced with a hard delete mid-sync.
            }

            if (! SectorProvenance::mayWrite($fresh, self::INSTAGRAM_SOURCE)) {
                return;
            }

            if (SectorProvenance::isFoodDemotion($isBusiness, $fresh->sector, $mapped)
                && $this->foodContent->existsFor($fresh)) {
                SectorProvenance::logTransition($fresh, $mapped, self::INSTAGRAM_SOURCE, __METHOD__, 'refused_food_demotion');

                return;
            }

            SectorProvenance::logTransition($fresh, $mapped, self::INSTAGRAM_SOURCE, __METHOD__);
            $fresh->sector = $mapped;
            $fresh->sector_source = self::INSTAGRAM_SOURCE;
            $fresh->save();
        });

        // MUST refresh: InstagramConnectionSeeder:230 hands this same instance to
        // autoSaveUnmatchedLinks -> LinkRouter::gateAllows, which reads ->sector.
        $user->refresh();

        if ($user->sector !== $sectorBefore) {
            $user->site()->first()?->touch();
        }
    }

    private function applyDisplayName(User $user, ?string $fullName): void
    {
        if ($fullName === null || ! $this->isBlank($user->display_name)) {
            return;
        }
        $user->display_name = $fullName;
        $user->save();
    }

    private function applyHandle(User $user, ?string $username): void
    {
        if ($username === null || ! $this->isBlank($user->handle)) {
            return;
        }
        $base = Str::slug($username, '-') ?: 'user';
        $candidate = $base;
        $attempt = 1;
        while (User::query()->where('handle_lc', strtolower($candidate))->exists()) {
            $candidate = $base.'-'.$attempt;
            $attempt++;
        }
        $user->handle = $candidate;
        $user->handle_lc = strtolower($candidate);
        $user->save();
    }

    private function applyContactFields(User $user, array $payload): void
    {
        // INERT ON THE SCRAPE PATH, BY DESIGN — do not "fix" by swapping actors
        // or widening the key list. Instagram does not disclose business contact
        // details to a logged-out viewer, and both actors read the logged-out
        // endpoint. Verified 2026-08-11 against live Apify run history: the apify
        // actor omits the keys entirely; the figue actor returns them as NULL
        // while simultaneously reporting should_show_public_contacts=true and
        // business_contact_method="TEXT" (simondoylehair) — i.e. Instagram
        // confirms the contacts exist and withholds the values. Nor does the
        // official Graph API close this: business_discovery returns no email or
        // phone for a third-party handle, and for an owner-authorised account
        // those live on the linked Facebook Page, not the IG node.
        //
        // The method stays because it is a correct fold for ANY source that does
        // supply these (it is reached by no other caller today), and because
        // deleting it would invite the next reader to re-add it. Real contact
        // details arrive from Google Business (phone — IdentitySync:69) or from
        // the person at signup/claim. Nothing to wait for here.
        $email = $this->stringOrNull($payload['businessEmail'] ?? $payload['business_email'] ?? null);
        $phone = $this->stringOrNull($payload['businessPhoneNumber'] ?? $payload['business_phone_number'] ?? null);
        if ($email === null && $phone === null) {
            return;
        }
        $site = Site::query()->where('user_id', $user->id)->first();
        if ($site === null) {
            return;
        }
        $workplace = Workplace::firstOrNew(['site_id' => (string) $site->id]);
        // site_id is not mass-assignable (#SEC-17) — the new-row branch needs it set explicitly.
        $workplace->site_id = (string) $site->id;
        $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
        $stamp = now()->toIso8601String();
        $changed = false;
        foreach (['contact_email' => $email, 'phone' => $phone] as $field => $value) {
            if ($value === null || ! $this->isBlank($workplace->{$field} ?? null)) {
                continue;
            }
            $workplace->{$field} = $value;
            $sources[$field] = ['source' => self::INSTAGRAM_SOURCE, 'at' => $stamp];
            $changed = true;
        }
        if ($changed) {
            $workplace->field_sources = $sources;
            $workplace->save();
        }
    }

    private function isBlank($value): bool
    {
        return $value === null || $value === '';
    }

    private function stringOrNull($value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
