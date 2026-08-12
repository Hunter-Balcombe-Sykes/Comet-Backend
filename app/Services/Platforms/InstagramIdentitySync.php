<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Profile\FoodContentProbe;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Support\Str;

/**
 * Fold Instagram-scraped identity fields into a user's core.users columns —
 * always fill-if-empty, both account types equally (unlike Google Business,
 * which is authoritative for a Business account via IdentitySync). Instagram
 * is a supplementary source: it only ever closes gaps, never overrides
 * anything already set — by hand, by Google, or by an earlier Instagram sync.
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
        $this->applySector($user, [
            $payload['businessCategoryName'] ?? null,
            $payload['business_category_name'] ?? null,
            $payload['category_name'] ?? null,
        ]);
        $this->applyDisplayName($user, $this->stringOrNull(
            $payload['fullName'] ?? $payload['full_name'] ?? null
        ));
        $this->applyHandle($user, $this->stringOrNull($payload['username'] ?? null));
        $this->applyContactFields($user, $payload);
    }

    /**
     * First candidate that MAPS wins — not the first that is non-null.
     * Instagram returns the literal string "None" as a category (observed on
     * crucibletattooco, 2026-08-10), which a `??` chain would accept and then
     * fail to map, discarding a usable sibling key.
     *
     * @param  list<mixed>  $candidates
     */
    private function applySector(User $user, array $candidates): void
    {
        $mapped = null;
        foreach ($candidates as $candidate) {
            $mapped = SectorTaxonomy::fromInstagramCategory($this->stringOrNull($candidate));
            if ($mapped !== null) {
                break;
            }
        }
        if ($mapped === null) {
            return;
        }
        // A manual pick, or a value already stamped by any source, is
        // permanent from Instagram's perspective — fill-if-empty only.
        if ($user->sector_source === 'manual' && $user->sector !== null) {
            return;
        }
        if ($this->isBlank($user->sector)) {
            $user->sector = $mapped;
            $user->sector_source = self::INSTAGRAM_SOURCE;
            $user->save();
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
