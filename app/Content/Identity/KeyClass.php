<?php

namespace App\Content\Identity;

/**
 * The identity key registry (plan §5). Each class declares its tier, which
 * item kinds it applies to, how its value is canonicalised, and a minimum
 * length below which the value is too weak to be evidence at all.
 *
 * Kind-scoping is what replaces the deleted KindFamily concept: keys only
 * union within the kinds they are declared for, so a track and a video can
 * never merge because they share a title.
 */
enum KeyClass: string
{
    // ── Joining: a shared value is identity. ────────────────────────────────
    case PlatformObject = 'platform_object';
    case CanonicalUrl = 'canonical_url';
    case Gtin14 = 'gtin14';
    case Isrc = 'isrc';
    case FeedGuid = 'feed_guid';
    case EnclosureUrl = 'enclosure_url';
    case ContentDigest = 'content_digest';

    // ── Corroborating: merges only when unambiguous + cross-source. ─────────
    case TitleRelease = 'title_release';
    case TitleDuration = 'title_duration';
    case TitleOnly = 'title_only';
    case EventOccurrence = 'event_occurrence';
    case OfferingName = 'offering_name';
    case OfferingNameSpec = 'offering_name_spec';
    case OfferingNameInCategory = 'offering_name_in_category';

    // ── Evidential: never merges alone. ─────────────────────────────────────
    case NamePriceBand = 'name_price_band';
    case TitleLoose = 'title_loose';
    case AuthorDateBody = 'author_date_body';

    public function tier(): KeyTier
    {
        return match ($this) {
            self::PlatformObject, self::CanonicalUrl, self::Gtin14, self::Isrc,
            self::FeedGuid, self::EnclosureUrl, self::ContentDigest => KeyTier::Joining,

            self::TitleRelease, self::TitleDuration, self::TitleOnly,
            self::EventOccurrence, self::OfferingName, self::OfferingNameSpec,
            self::OfferingNameInCategory => KeyTier::Corroborating,

            self::NamePriceBand, self::TitleLoose, self::AuthorDateBody => KeyTier::Evidential,
        };
    }

    /**
     * Minimum canonicalised length. A two-character title is not evidence of
     * anything; merging on it would silently glue unrelated items together.
     */
    public function minLength(): int
    {
        return match ($this) {
            self::TitleOnly => 12,
            self::OfferingName => 8,
            // Category corroborates, so a short dish name becomes usable:
            // "Fries" alone is nothing, "Sides|Fries" is a real match. This is
            // what stops the min-8 rule regressing short-name menu merges.
            self::OfferingNameInCategory => 5,
            self::TitleLoose => 10,
            self::Gtin14 => 8,
            self::Isrc => 12,
            default => 3,
        };
    }

    /**
     * Item kinds this key may union within. A key NEVER crosses these.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return match ($this) {
            self::Isrc, self::TitleDuration => ['track'],
            self::TitleRelease => ['release', 'track'],
            self::Gtin14 => ['product'],
            self::FeedGuid, self::EnclosureUrl => ['episode'],
            self::EventOccurrence => ['event'],
            self::OfferingName, self::OfferingNameSpec, self::OfferingNameInCategory => ['menu_item', 'service'],
            self::NamePriceBand => ['menu_item', 'service', 'product'],
            self::ContentDigest => ['media', 'document'],
            self::AuthorDateBody => ['review', 'article'],
            self::TitleOnly, self::TitleLoose => ['video', 'track', 'release', 'episode', 'article'],
            // PlatformObject is scoped by its own value (which embeds the
            // platform), and CanonicalUrl crosses kinds only via `link` — see
            // Resolver::mayUnion().
            self::PlatformObject, self::CanonicalUrl => [],
        };
    }

    public function appliesTo(string $kind): bool
    {
        $kinds = $this->kinds();

        return $kinds === [] || in_array($kind, $kinds, true);
    }

    /** Canonical form used for comparison. Never mutates what is displayed. */
    public function canonicalise(string $value): string
    {
        $value = trim($value);

        return match ($this) {
            self::Isrc, self::Gtin14 => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? $value),
            self::CanonicalUrl, self::EnclosureUrl => strtolower($value),
            self::TitleOnly, self::TitleLoose, self::TitleRelease, self::TitleDuration,
            self::OfferingName, self::OfferingNameSpec, self::OfferingNameInCategory,
            self::NamePriceBand => self::normalizeText($value),
            default => $value,
        };
    }

    /**
     * Aggressive text normalisation: case, punctuation, accents and the
     * decorations vendors add ("(Remastered 2019)", "[Official Video]") all
     * removed, because they are formatting rather than identity.
     *
     * Public because composite keys ("category|name") must normalise each part
     * SEPARATELY before joining — running canonicalise() over the joined string
     * would eat the separator. This enum stays the one owner of what
     * normalisation means; IdentityKeyDeriver calls it rather than repeating it.
     */
    public static function normalizeText(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/\((?:official|remaster(?:ed)?|explicit|clean|hd|4k|lyric)[^)]*\)/u', '', $value) ?? $value;
        $value = preg_replace('/\[(?:official|remaster(?:ed)?|explicit|clean|hd|4k|lyric)[^\]]*\]/u', '', $value) ?? $value;
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }
        // TRANSLIT is a C-library behaviour, not a PHP one: glibc renders "é"
        // as "e" while macOS/BSD libiconv renders it "'e". Dropping the
        // modifier marks BEFORE the alnum collapse is what makes the two
        // agree — otherwise a key derived locally ("beyonc e") would differ
        // from the one Cloud derives ("beyonce") and a green local suite would
        // say nothing about the values that actually land. Deleting rather
        // than spacing them also folds "don't" onto "dont", which is the
        // matching we want anyway.
        $value = preg_replace('/[\'`^~"]/', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
