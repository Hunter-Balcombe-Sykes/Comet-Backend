<?php

namespace App\Ingest\Projection;

use App\Content\Identity\IdentityKey;
use App\Content\Identity\KeyClass;

/**
 * The evidence a projection carries about what it IS (plan §5), derived — not
 * accumulated. Pure: no database, no clock, no vendor call, so the same
 * projection always yields the same keys, which is what makes
 * `ingest:project --rebuild` a recomputation rather than a history.
 *
 * Until Phase 2 the writer emitted 2 of 17 classes — `platform_object` (which
 * embeds the platform, so it can never match cross-source by construction) and
 * `canonical_url` (which never matches across platforms). The Joining tier
 * could union nothing and Corroborating/Evidential were empty, which is the
 * whole reason content.item_merges sat at 0 (log F1).
 *
 * `feed_guid` is the ONE class deliberately not derived: no projection field
 * means a feed's <guid>, and adding one no connector fills would rebuild the
 * `profile_fields` seam Phase 1 just deleted (log F15/F20) — the field and its
 * emission land together, with the connector that has the data. `isrc`/`gtin14`
 * ARE derived despite having no producer today, because f_catalog.isrc/gtin are
 * existing columns: reading an empty column invents nothing and starts working
 * the day a connector fills it.
 */
final class IdentityKeyDeriver
{
    /** Price band width, in major currency units, for the evidential NamePriceBand. */
    private const PRICE_BAND = 5;

    /**
     * @param  string  $coord  the source item's platform:account_ref:external_key
     * @param  array<string, mixed>  $projection  the shape Projector::project() returns
     * @return list<IdentityKey>
     */
    public function derive(string $coord, array $projection): array
    {
        $kind = (string) ($projection['kind'] ?? '');
        $facets = (array) ($projection['facets'] ?? []);
        $headline = $this->text($projection['headline'] ?? null);

        /** @var list<array{0: KeyClass, 1: string}> $candidates */
        $candidates = [[KeyClass::PlatformObject, $coord]];

        foreach ([
            ...$this->joining($facets, $projection),
            ...$this->corroborating($headline, $facets, $projection),
            ...$this->evidential($headline, $facets, $projection),
        ] as $candidate) {
            $candidates[] = $candidate;
        }

        $keys = [];
        $seen = [];
        foreach ($candidates as [$class, $value]) {
            // appliesTo/minLength are re-applied by the Resolver; filtering
            // here keeps rows nobody could explain out of the table, and
            // measures length on the canonical form exactly as it does.
            if (! $class->appliesTo($kind) || $value === '') {
                continue;
            }
            if (mb_strlen($class->canonicalise($value)) < $class->minLength()) {
                continue;
            }
            $signature = $class->value.'|'.$value;
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $keys[] = new IdentityKey($class, $value);
        }

        return $keys;
    }

    /**
     * A shared value here IS identity, so every one of these is a stable
     * vendor-side identifier rather than anything derived from prose.
     *
     * @param  array<string, mixed>  $facets
     * @param  array<string, mixed>  $projection
     * @return list<array{0: KeyClass, 1: string}>
     */
    private function joining(array $facets, array $projection): array
    {
        $keys = [];

        $url = $this->text($facets['f_link']['url'] ?? null);
        if ($url !== null) {
            $keys[] = [KeyClass::CanonicalUrl, KeyClass::CanonicalUrl->canonicalise($url)];
        }

        $isrc = $this->text($facets['f_catalog']['isrc'] ?? null);
        if ($isrc !== null) {
            $keys[] = [KeyClass::Isrc, KeyClass::Isrc->canonicalise($isrc)];
        }

        $gtin = $this->text($facets['f_catalog']['gtin'] ?? null);
        if ($gtin !== null) {
            $keys[] = [KeyClass::Gtin14, KeyClass::Gtin14->canonicalise($gtin)];
        }

        // An RSS enclosure IS the playable file; f_playable.stream_url is where
        // a feed connector already lands it.
        $enclosure = $this->text($facets['f_playable']['stream_url'] ?? null);
        if ($enclosure !== null) {
            $keys[] = [KeyClass::EnclosureUrl, KeyClass::EnclosureUrl->canonicalise($enclosure)];
        }

        $digest = $this->contentDigest($projection);
        if ($digest !== null) {
            $keys[] = [KeyClass::ContentDigest, $digest];
        }

        return $keys;
    }

    /**
     * Names and times: strong enough to merge only cross-source and only when
     * unambiguous, which the Resolver enforces — not this method.
     *
     * @param  array<string, mixed>  $facets
     * @param  array<string, mixed>  $projection
     * @return list<array{0: KeyClass, 1: string}>
     */
    private function corroborating(?string $headline, array $facets, array $projection): array
    {
        if ($headline === null) {
            return [];
        }

        $keys = [];
        $name = KeyClass::normalizeText($headline);

        $keys[] = [KeyClass::TitleOnly, $name];
        $keys[] = [KeyClass::OfferingName, $name];

        // The release ARTIST, never the year: two platforms disagree about a
        // remaster's date constantly and agree about who made it, and the
        // registry rules the year out of the music key explicitly.
        $creator = $this->text($facets['f_authored']['creator'] ?? null);
        if ($creator !== null) {
            $keys[] = [KeyClass::TitleRelease, $name.'|'.KeyClass::normalizeText($creator)];
        }

        $seconds = $this->positiveInt($facets['f_duration']['seconds'] ?? null);
        if ($seconds !== null) {
            $keys[] = [KeyClass::TitleDuration, $name.'|'.$seconds];
            // A 30-minute massage and a 60-minute one are different offerings
            // however identically they are named.
            $keys[] = [KeyClass::OfferingNameSpec, $name.'|'.$seconds];
        }

        // norm(category)|norm(name), per the registry: category corroboration
        // is what makes short dish names ("Fries") usable at all. One key per
        // category — a dish listed under two categories is evidence twice.
        foreach ((array) ($projection['collections'] ?? []) as $collection) {
            $label = $this->text(((array) $collection)['label'] ?? null);
            if ($label !== null) {
                $keys[] = [KeyClass::OfferingNameInCategory, KeyClass::normalizeText($label).'|'.$name];
            }
        }

        $instant = $this->utcInstant($facets['f_occurrence'] ?? null);
        if ($instant !== null) {
            $keys[] = [KeyClass::EventOccurrence, $name.'|'.$instant];
        }

        return $keys;
    }

    /**
     * Never merges — surfaces a pair for a human to judge.
     *
     * @param  array<string, mixed>  $facets
     * @param  array<string, mixed>  $projection
     * @return list<array{0: KeyClass, 1: string}>
     */
    private function evidential(?string $headline, array $facets, array $projection): array
    {
        $keys = [];

        if ($headline !== null) {
            // Every bracketed segment stripped, not just the decorations
            // KeyClass::normalizeText knows: the point is matching "Song
            // (Live at Wembley)" against plain "Song", so BOTH sides must
            // emit — suppressing the key when loose equals strict would mean
            // the decorated row has nothing to pair with.
            $loose = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $headline) ?? $headline;
            $keys[] = [KeyClass::TitleLoose, KeyClass::normalizeText($loose)];

            $band = $this->priceBand($projection['offers'] ?? null);
            if ($band !== null) {
                $keys[] = [KeyClass::NamePriceBand, KeyClass::normalizeText($headline).'|'.$band];
            }
        }

        $review = $this->authorDateBody($facets['f_review'] ?? null);
        if ($review !== null) {
            $keys[] = [KeyClass::AuthorDateBody, $review];
        }

        return $keys;
    }

    /**
     * ONE digest per item, from its primary image — not one per carousel
     * frame. ContentDigest is joining tier, so a gallery frame two different
     * posts happen to share would otherwise fuse them outright.
     *
     * @param  array<string, mixed>  $projection
     */
    private function contentDigest(array $projection): ?string
    {
        $entries = array_values((array) ($projection['media'] ?? []));
        if ($entries === []) {
            return null;
        }

        $primary = (array) $entries[0];
        foreach ($entries as $entry) {
            if ((((array) $entry)['role'] ?? null) === 'cover') {
                $primary = (array) $entry;
                break;
            }
        }

        return MediaFingerprint::for($primary)[0];
    }

    /**
     * Two platforms spell the same instant differently ("+10:00" vs a UTC "Z"),
     * so the key is the resolved instant or nothing at all.
     */
    private function utcInstant(mixed $occurrence): ?string
    {
        $occurrence = (array) $occurrence;
        $raw = $this->text($occurrence['starts_at_utc'] ?? null) ?? $this->text($occurrence['starts_at_local'] ?? null);
        if ($raw === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The cheapest offer's band, in major units. Currency is deliberately not
     * in the value: identity is per-user and a user's own price list is one
     * currency, while the tier this feeds never merges on its own anyway.
     */
    private function priceBand(mixed $offers): ?string
    {
        $amounts = [];
        foreach ((array) $offers as $offer) {
            $amount = ((array) $offer)['amount_minor'] ?? null;
            if (is_int($amount) || (is_string($amount) && ctype_digit($amount))) {
                $amounts[] = (int) $amount;
            }
        }

        if ($amounts === []) {
            return null;
        }

        $major = intdiv(min($amounts), 100);
        $low = intdiv($major, self::PRICE_BAND) * self::PRICE_BAND;

        return $low.'-'.($low + self::PRICE_BAND);
    }

    /**
     * Hashed, not composed in the clear: the reviewer's name is PII and
     * content.identity_candidates surfaces key values for human review, so the
     * plaintext would leak a second copy into a table the redaction and DSAR
     * paths do not reach (LEGAL-2 / PRIV-2 are both still open). Equality is
     * all the resolver needs, and a hash preserves exactly that.
     */
    private function authorDateBody(mixed $review): ?string
    {
        $review = (array) $review;
        $body = $this->text($review['text'] ?? null);
        if ($body === null) {
            return null;
        }

        $author = $this->text($review['author_name'] ?? null) ?? '';
        $date = $this->text($review['reviewed_at'] ?? null) ?? '';
        try {
            $date = $date === '' ? '' : (new \DateTimeImmutable($date))->format('Y-m-d');
        } catch (\Exception) {
            // An unparseable stamp still identifies when spelled identically.
        }

        return sha1(KeyClass::normalizeText($author).'|'.$date.'|'.KeyClass::normalizeText($body));
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
