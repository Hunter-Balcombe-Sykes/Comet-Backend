<?php

namespace App\Routing;

/**
 * Secret-bearing query/fragment params: what must never survive into
 * `canonical_url`, and what must be redacted (not dropped) from `raw_url`.
 *
 * Hybrid design, not a plain denylist of names (#SEC-1). A pure allowlist
 * cannot work here — the canonicaliser handles arbitrary unknown domains
 * (`Verdict::Note`), where every query param is potentially load-bearing to
 * the resource the link actually points at. So this is:
 *
 *   1. Identity allowlist (checked first, wins over everything) — the
 *      catalog's `query_requires` union. A param the catalog itself depends
 *      on for identity is never redacted, even if it happens to look secret
 *      or carry a secret-shaped value. Bound to the catalog by a test
 *      (SecretParamsCatalogTest), not a runtime read — this class stays
 *      pure/no-I/O like IriCanonicalizer.
 *   2. Secret-name matching BY SEGMENT, not substring or equality. A key is
 *      split on non-alphanumeric characters and camelCase boundaries, and
 *      each segment is tested against a fixed vocabulary. This is what
 *      catches vendor-specific shapes like `auth_token_v2` (segments:
 *      auth, token, v2) while never flagging `keyword` (one segment, not
 *      `key`), `resident` (one segment, not `sid`), or `monkey` (not `key`).
 *   3. Value-shape matching, high-precision prefixes only (JWT header shape,
 *      known vendor secret prefixes). No generic entropy/length heuristic —
 *      that would false-positive on opaque-but-benign resource ids
 *      (`?listing=aGVsbG8gd29ybGQgdGhpcw`) and silently break real links.
 *
 * Fail-safe direction: under-stripping is a no-op (today's behaviour);
 * over-stripping only removes a param from `Iri->query`, which makes a
 * catalog `query_requires` match fail and demotes the link to
 * `Verdict::Note` — kept as a plain link, never dropped, never mis-routed.
 */
final class SecretParams
{
    /**
     * The catalog's `query_requires` union — every param a detector actually
     * depends on for identity. Pinned to the live catalog by
     * SecretParamsCatalogTest so a new detector adding a `?key=`-shaped
     * identity param cannot be silently eaten by this class.
     */
    private const IDENTITY_PARAMS = ['rid', 'accountid', 'venueid'];

    /**
     * Segment vocabulary. Deliberately excludes `mac` (macOS/`mac_address`
     * collisions) and the Azure SAS short params `sv`/`se`/`sp`/`st`
     * (two-letter, high collision risk) — `sig` alone already neuters a SAS.
     */
    private const SECRET_SEGMENTS = [
        'token', 'tokens', 'secret', 'secrets', 'password', 'passwd', 'pwd',
        'apikey', 'key', 'auth', 'authorization', 'jwt', 'session',
        'sessionid', 'sid', 'sig', 'signature', 'hmac', 'hash', 'credential',
        'credentials', 'creds', 'bearer', 'otp', 'passcode', 'refresh',
        'access', 'nonce', 'salt',
    ];

    /** @see https://www.iana.org/assignments/jwt : header.payload.signature, each base64url. */
    private const JWT_PATTERN = '/^eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\./';

    /** High-precision vendor secret prefixes. Near-zero false-positive rate. */
    private const SECRET_VALUE_PREFIXES = [
        'sk_live_', 'sk_test_', 'rk_live_', 'ghp_', 'gho_', 'ghs_', 'github_pat_',
        'xoxb-', 'xoxp-', 'xoxa-', 'AKIA', 'ASIA', 'AIza', 'SG.', 'glpat-',
        'npm_', 'shpat_', 'shpss_',
    ];

    /** True when this query/fragment key=value pair must never persist unredacted. */
    public static function isSecret(string $key, string $value): bool
    {
        if (in_array(strtolower($key), self::IDENTITY_PARAMS, true)) {
            return false;
        }

        foreach (self::segments($key) as $segment) {
            if (in_array($segment, self::SECRET_SEGMENTS, true)) {
                return true;
            }
        }

        if ($value !== '' && preg_match(self::JWT_PATTERN, $value) === 1) {
            return true;
        }

        foreach (self::SECRET_VALUE_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * No legitimate link approaches this length. A string packed with bare
     * `?`/`&`/`#` separators and no `=` (or a huge unmatched run between
     * them) makes the pair-scanning regex re-attempt its backtrack search
     * from every separator, which is quadratic in the number of separators —
     * observed at ~60s for a 20MB adversarial string. Reject outright rather
     * than let a scraped href (LinkInBioImporter has no length cap upstream,
     * unlike RoutingController::store()'s 2048-char request validation) tie
     * up a request or a queued job.
     */
    private const MAX_LENGTH = 8192;

    /**
     * Redacts secret-bearing query/fragment param VALUES in place, keeping
     * the keys — dropping the pair entirely would destroy `raw_url`'s reason
     * to exist (`routing:reproject` re-canonicalises it to replay historical
     * traffic against a new rulepack; a blanked query silently corrupts that).
     *
     * A pair-scanning regex, not `parse_url`: `$url` is untrimmed user text
     * that may be prose, a multi-line paste, or `<https://…>,` — `parse_url`
     * returns garbage or false on those, while this degrades gracefully. It
     * also covers the URL fragment (`#access_token=…`), which OAuth implicit
     * flow uses and which `parse_url`-based logic would never see.
     */
    public static function redactUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        if (strlen($url) > self::MAX_LENGTH) {
            return '';
        }

        // preg_replace_callback() returns null on a PCRE engine error
        // (backtrack limit, recursion limit, JIT stack overflow) — it does
        // NOT throw. A `?? $url` fallback here would silently persist the
        // unredacted secret this class exists to strip, on exactly the
        // adversarial input it's supposed to defend against. Fail CLOSED:
        // a redactor that cannot redact returns nothing, never the raw
        // value. Same failure class already bit this team once — see
        // tests/Unit/Platforms/GenericShopScraperTest.php ("fix-round P4").
        return preg_replace_callback(
            '/([?&#])([^=&#\s]+)=([^&#\s]*)/',
            static function (array $m): string {
                [, $separator, $key, $value] = $m;

                return self::isSecret($key, $value)
                    ? $separator.$key.'=[redacted]'
                    : $m[0];
            },
            $url
        ) ?? '';
    }

    /**
     * Lowercase, split on non-alphanumeric characters AND camelCase
     * boundaries, then strip a trailing version suffix (`v2`, `2`) from each
     * segment. `auth_token_v2` → [auth, token, v2→'']; `apiKey` → [api, key];
     * `X-Api-Key` → [x, api, key]. Segment equality (not substring) is what
     * keeps `keyword`/`resident`/`monkey`/`owner` from ever matching.
     *
     * @return list<string>
     */
    private static function segments(string $key): array
    {
        // Acronym-then-word boundary (APIKey -> API_Key), then lower/digit-
        // then-Upper boundary (fooBar -> foo_Bar). Order matters: the first
        // pass must run before lowercasing destroys the case info it needs.
        $s = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/', '_', $key) ?? $key;
        $s = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $s) ?? $s;
        $s = strtolower($s);

        $parts = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(
            static fn (string $segment): string => preg_replace('/v?\d+$/', '', $segment) ?: $segment,
            $parts
        );
    }
}
