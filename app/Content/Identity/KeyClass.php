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
     * Latin letters folded to ASCII, spelled out rather than delegated.
     *
     * This USED to be `iconv('UTF-8', 'ASCII//TRANSLIT')`, which is a
     * C-library behaviour and not a PHP one: macOS/BSD libiconv renders "é"
     * as "'e", while glibc under the container's POSIX locale does not
     * transliterate at all — it emits "?", so "Björk" normalised to "bj rk"
     * and "Beyoncé" to "beyonc" on Cloud while a developer's machine produced
     * something else again. An identity key that depends on which libc
     * computed it is not an identity key, and no test on a laptop could ever
     * have caught it. Verified live on dev, 2026-08-14, before this table
     * replaced it.
     *
     * Coverage is Latin-1 Supplement plus Latin Extended-A, which is what
     * artist, dish and service names are actually written in. Anything
     * outside it (Cyrillic, CJK, emoji) still collapses to spaces at the
     * alphanumeric pass below — the same outcome as before, now reached
     * identically everywhere instead of by accident.
     *
     * @var array<string, string>
     */
    private const TRANSLITERATIONS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e',
        'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i',
        'ĭ' => 'i', 'į' => 'i', 'ı' => 'i', 'ĳ' => 'ij',
        'ĵ' => 'j', 'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n', 'ŋ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ſ' => 's', 'ß' => 'ss',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'þ' => 'th',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u',
        'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

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
    /**
     * The FIRST credited artist, normalised — the half of TitleRelease that
     * platforms actually agree on. Spotify credits "Tame Impala, JENNIE, Boys
     * Noize"; Apple credits "Tame Impala"; SoundCloud credits the uploader.
     * Splitting on the credit separators (",", "&", "and", "feat.", "ft.",
     * "x", "with", "vs") before normalising lets the same song union across
     * them (overnight 2026-08-18, W5).
     */
    public static function primaryArtist(string $creator): string
    {
        $parts = preg_split('/\s*(?:,|&|\band\b|\bfeat\.?\b|\bft\.?\b|\bfeaturing\b|\bwith\b|\bvs\.?\b|\bx\b|×|·|\/)\s*/iu', trim($creator), 2) ?: [$creator];
        // No `?? $creator`: the `?: [$creator]` above makes $parts a
        // non-empty-list, so index 0 always exists (phpstan nullCoalesce.offset).
        $first = $parts[0];
        // "Lil Nas X": the separator match must leave a real second credit
        // behind, otherwise the "x" was the tail of ONE name (review W5).
        if (trim((string) ($parts[1] ?? '')) === '' || mb_strlen(trim((string) $parts[1])) < 2) {
            $first = $creator;
        }

        $normalized = self::normalizeText($first);

        return $normalized !== '' ? $normalized : self::normalizeText($creator);
    }

    public static function normalizeText(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/\((?:official|remaster(?:ed)?|explicit|clean|hd|4k|lyric)[^)]*\)/u', '', $value) ?? $value;
        $value = preg_replace('/\[(?:official|remaster(?:ed)?|explicit|clean|hd|4k|lyric)[^\]]*\]/u', '', $value) ?? $value;
        $value = strtr($value, self::TRANSLITERATIONS);
        // Apostrophes are DELETED rather than spaced, so "don't" folds onto
        // "dont" — one source writing the contraction and another omitting it
        // is a spelling difference, not two different things.
        $value = preg_replace('/[\'\x{2018}\x{2019}]/u', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
