<?php

namespace App\Routing;

use App\Catalog\Hosts;

/**
 * The ONE canonicaliser (C9). Turns a user-supplied string into an Iri the
 * projector can match, or an Iri carrying a rejection reason. Pure: no I/O,
 * no DNS, no fetching — shortener expansion is a runtime concern layered
 * above this by the observer.
 *
 * Order matters and is deliberate:
 *   scheme → host extraction (userinfo stripped, NEVER trusted) → UTS-46 IDN
 *   → confusable check → own-infra denylist → alias rewrite → registrable
 *   domain via PSL (or suffix override) → path normalise → query filter.
 */
class IriCanonicalizer
{
    /**
     * Locale / UI / presentation params that change nothing about WHICH thing
     * a URL points at — the `?hl=en`, `?lang=en-AU`, `?theme=dark` noise that
     * rides along on real pasted links. Stripped for the same reason tracking
     * params are: two URLs differing only by these are the same resource.
     * Kept separate from TRACKING_PARAMS so the intent stays readable.
     */
    private const PRESENTATION_PARAMS = [
        'hl', 'gl', 'lang', 'locale', 'language', 'ui', 'theme', 'variant',
        'app', 'app_id', 'platform', 'device', 'client', 'output', 'format',
        'src', 'source', 'ref', 'ref_', 'referrer', 'share', 'shared',
        'fromview', 'view', 'mode', 'sa', 'ved', 'usp', 'sc', 'nd',
    ];

    /** Tracking params stripped everywhere. DENYLIST ONLY — never an allowlist:
     *  identity params (rid, v, owner, studioid, accountid, venueid…) must survive. */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'utm_name', 'utm_cid', 'utm_reader', 'utm_social', 'utm_brand',
        'fbclid', 'gclid', 'dclid', 'gbraid', 'wbraid', 'msclkid', 'twclid', 'ttclid',
        'igshid', 'igsh', 'ref_src', 'ref_url', 'si', 'feature', 'mc_cid', 'mc_eid',
        '_ga', '_gl', 'yclid', 'scid', 'trk', 'trkCampaign', 'sc_campaign', 'sc_channel',
        'epik', 'hsa_acc', 'hsa_cam', 'hsa_grp', 'hsa_ad', 'hsCtaTracking',
    ];

    /** Own infrastructure — a pasted link must never point the platform at itself. */
    private const OWN_INFRA_SUFFIXES = [
        'partna.au', 'supabase.co', 'laravel.cloud',
        'r2.cloudflarestorage.com', 'r2.dev', 'workers.dev',
    ];

    /** Known link shorteners: recorded, never auto-connected pre-expansion. */
    private const SHORTENERS = [
        'bit.ly', 'tinyurl.com', 't.co', 'lnkd.in', 'rebrand.ly', 'ow.ly', 'buff.ly',
        'cutt.ly', 'shorturl.at', 'rb.gy', 'is.gd', 'linktr.ee', 'goo.gl',
    ];

    public function __construct(private readonly PublicSuffixList $psl) {}

    public function canonicalize(string $input): Iri
    {
        $raw = $this->trimPastedJunk($input);
        if ($raw === '' || strlen($raw) > 2048) {
            return Iri::reject($input, $raw === '' ? 'malformed' : 'too_long');
        }

        // Protocol-relative input is a legitimate paste shape (copied from an
        // href); everything else must declare http(s) explicitly. A bare
        // "instagram.com/x" is assumed https — users paste that constantly.
        if (str_starts_with($raw, '//')) {
            $raw = 'https:'.$raw;
        } elseif (! preg_match('~^[a-z][a-z0-9+.-]*:~i', $raw)) {
            $raw = 'https://'.$raw;
        }

        // Parse BEFORE judging the scheme: "https://" carries a valid scheme
        // and no host, which is malformed — not a scheme problem.
        $parts = parse_url($raw);
        if ($parts === false) {
            return Iri::reject($input, 'malformed');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return Iri::reject($input, 'non-http-scheme');
        }

        if (! isset($parts['host']) || $parts['host'] === '') {
            return Iri::reject($input, 'malformed');
        }

        // Userinfo is parsed away by parse_url and DELIBERATELY discarded:
        // https://opentable.com.au@evil.com/x has host evil.com, which is the
        // only thing that decides identity.
        $host = strtolower(rtrim($parts['host'], '.'));

        // An IP-literal host can never be a platform surface.
        if (filter_var($host, FILTER_VALIDATE_IP) || str_starts_with($host, '[')) {
            return Iri::reject($input, 'ip-host');
        }

        $ascii = $this->toAscii($host);
        if ($ascii === null) {
            return Iri::reject($input, 'malformed');
        }
        // Check the UNICODE form for confusables. Both entry shapes must be
        // covered: a pasted unicode host, and an already-punycode host (the
        // common attack shape — xn--pentable-9db.com is pure ASCII on the
        // wire, so checking only non-ASCII input would miss it entirely).
        $unicode = str_contains($ascii, 'xn--') ? $this->toUnicode($ascii) : $host;
        if ($unicode !== null && $unicode !== $ascii && $this->isConfusable($unicode)) {
            return Iri::reject($input, 'idn-confusable');
        }
        $host = $ascii;

        if (! preg_match('~^[a-z0-9.-]+$~', $host) || str_contains($host, '..')) {
            return Iri::reject($input, 'malformed');
        }

        foreach (self::OWN_INFRA_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return Iri::reject($input, 'own-infra');
            }
        }

        $registrable = $this->psl->registrableDomain($host);
        if ($registrable === null) {
            return Iri::reject($input, 'public-suffix-host');
        }

        if (in_array($registrable, self::SHORTENERS, true)) {
            return Iri::reject($input, 'shortener');
        }

        // Alias rewrite (youtu.be → youtube.com): the alias host maps to the
        // registrable key whose detectors should evaluate.
        $aliasedFrom = null;
        $aliases = Hosts::aliases();
        if (isset($aliases[$host])) {
            $aliasedFrom = $host;
            $registrable = $aliases[$host];
        }

        // Suffix overrides: acme.myshopify.com identifies TENANT acme, so the
        // registrable boundary is the full host, not the eTLD+1. The suffix is
        // kept so the projector can still consult the PLATFORM's own rules
        // (which are registered against myshopify.com, not per tenant).
        $tenantScoped = false;
        $suffixParent = null;
        $tenantLabel = null;
        foreach (Hosts::suffixOverrides() as $suffix) {
            if (str_ends_with($host, '.'.$suffix) && $host !== $suffix) {
                $registrable = $host;
                $tenantScoped = true;
                $suffixParent = $suffix;
                $label = substr($host, 0, -1 * (strlen($suffix) + 1));
                $tenantLabel = $label === 'www' ? null : ($label ?: null);
                break;
            }
        }

        // The subdomain is always derived from the ACTUAL host against the
        // registrable key in force. This matters for aliases: rewriting
        // music.youtube.com → youtube.com selects YouTube's rules, but the
        // `music` label is exactly what tells YouTube Music apart from
        // YouTube, so it must survive. A true short-host alias (youtu.be)
        // shares no suffix with its target and correctly yields null.
        $subdomain = null;
        if ($tenantScoped) {
            // A tenant label IS the subdomain as far as rules are concerned —
            // that is what `#^(?<tenant>…)$#` patterns are written against.
            $subdomain = $tenantLabel;
        } elseif ($host !== $registrable && str_ends_with($host, '.'.$registrable)) {
            $sub = substr($host, 0, -1 * (strlen($registrable) + 1));
            $subdomain = $sub === 'www' ? null : ($sub ?: null);
        }

        $path = $this->normalizePath($parts['path'] ?? '/');
        $query = $this->filterQuery($parts['query'] ?? '');
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        $canonical = 'https://'.$host.($port !== null && ! in_array($port, [80, 443], true) ? ':'.$port : '').$path;
        if ($query !== []) {
            ksort($query);
            $canonical .= '?'.http_build_query($query);
        }

        return new Iri(
            raw: $input,
            canonical: $canonical,
            scheme: $scheme,
            host: $host,
            registrableKey: $registrable,
            subdomain: $subdomain,
            path: $path,
            query: $query,
            port: $port,
            rejected: null,
            aliasedFrom: $aliasedFrom,
            tenantScoped: $tenantScoped,
            suffixParent: $suffixParent,
            tenantLabel: $tenantLabel,
        );
    }

    /**
     * UTS-39 single-script restriction, per LABEL: `аpple.com` (Cyrillic а +
     * Latin pple) is confusable; `münchen.de` (one script) is not. Testing
     * per-label matters — testing the whole host would see the Latin public
     * suffix on every IDN and flag legitimate domains.
     *
     * Scope note: this is defence-in-depth for honest verdicts and logging.
     * The STRUCTURAL protection is the registrable domain — every catalog
     * brand key is ASCII, so a confusable host can never match a detector
     * regardless of what this returns. Latin-lookalike domains
     * (`0pentable.com`, `pentabėle.com`) are a different class this cannot
     * and does not try to catch: they are simply other people's domains.
     */
    private function isConfusable(string $unicodeHost): bool
    {
        $scripts = ['Latin', 'Cyrillic', 'Greek', 'Armenian', 'Hebrew', 'Arabic', 'Han', 'Hiragana', 'Katakana', 'Hangul', 'Thai', 'Devanagari'];

        foreach (explode('.', $unicodeHost) as $label) {
            if ($label === '' || ! preg_match('/[^\x20-\x7e]/', $label)) {
                continue; // pure ASCII label cannot mix scripts
            }
            $present = 0;
            foreach ($scripts as $script) {
                if (preg_match('/\p{'.$script.'}/u', $label)) {
                    $present++;
                }
            }
            if ($present > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Real pastes are messy: they arrive wrapped in <angle brackets> or
     * "quotes", carrying the sentence punctuation that followed them, with a
     * newline in the middle from a wrapped email, or prefixed with "URL:".
     * None of that changes which resource is meant, so it is trimmed before
     * parsing rather than rejected — a user pasting a good link with a stray
     * comma should not be told their link is invalid.
     */
    private function trimPastedJunk(string $input): string
    {
        $s = trim($input);

        // Interior whitespace is ambiguous: it is either a URL an email client
        // wrapped across lines ("…/some\nuser" → someuser) or prose around a
        // link ("Check this out: https://…. Thanks!"). Distinguish by what
        // FOLLOWS the break — a single break whose tail is URL-shaped is a
        // wrap and gets rejoined; anything else is prose and the URL token is
        // extracted instead. Getting this backwards either truncates a handle
        // or glues a sentence onto one.
        if (preg_match('/\s/', $s)) {
            $runs = preg_split('/\s+/', $s) ?: [];
            $isWrappedUrl = count($runs) === 2
                && $runs[1] !== ''
                && preg_match('{^[a-z0-9._~+/?=&%-]+$}i', $runs[1]) === 1;

            if ($isWrappedUrl) {
                $s = $runs[0].$runs[1];
            } elseif (preg_match('~[a-z][a-z0-9+.-]*://\S+~i', $s, $m)) {
                $s = $m[0];
            } else {
                $s = preg_replace('/\s+/', '', $s) ?? $s;
            }
        }

        // Wrapping and trailing punctuation, alternately, until stable — a
        // paste like "<https://x.com/u>," needs both, in either order.
        $previous = null;
        while ($previous !== $s) {
            $previous = $s;
            $s = trim($s, "<>\"'`«»‹›“”‘’");
            $s = preg_replace('/[.,;:!?)\]}]+$/', '', $s) ?? $s;
        }

        return $s;
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        // Collapse duplicate slashes and resolve dot segments without touching
        // percent-encoding (identity slugs can legitimately contain %XX).
        $path = preg_replace('~/{2,}~', '/', $path) ?? $path;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }
        // Presentation suffixes that name the same resource: /amp, /index.html.
        // Only ever removed from the END, so a legitimate segment called
        // "amp" mid-path (an artist named AMP) is untouched.
        $last = end($segments);
        if ($last !== false) {
            if (in_array(strtolower($last), ['amp', 'index.html', 'index.htm', 'index.php'], true)) {
                array_pop($segments);
            }
        }

        $normalized = '/'.implode('/', $segments);

        // Preserve a meaningful trailing slash only for the root.
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }

    /**
     * Tracking + presentation params, lowercased once.
     *
     * @return list<string>
     */
    private static function noiseParams(): array
    {
        static $params = null;

        return $params ??= array_map('strtolower', array_merge(self::TRACKING_PARAMS, self::PRESENTATION_PARAMS));
    }

    /** @return array<string, string> */
    private function filterQuery(string $query): array
    {
        if ($query === '') {
            return [];
        }
        parse_str($query, $parsed);
        $out = [];
        foreach ($parsed as $key => $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $lower = strtolower((string) $key);
            if (in_array($lower, self::noiseParams(), true)) {
                continue;
            }
            $out[(string) $key] = (string) $value;
        }

        return $out;
    }

    private function toAscii(string $host): ?string
    {
        if (! preg_match('/[^\x20-\x7e]/', $host)) {
            return $host;
        }
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? null : strtolower($ascii);
    }

    /** Punycode back to unicode, so isConfusable() sees what a human sees. */
    private function toUnicode(string $asciiHost): ?string
    {
        $unicode = idn_to_utf8($asciiHost, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $unicode === false ? null : $unicode;
    }
}
