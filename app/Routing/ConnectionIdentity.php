<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Collection;

/**
 * Which of this user's existing connections IS this identity — across the
 * three identity schemes `site.platform_connections` has accumulated.
 *
 * The schemes do not agree on what `resource_id` means, and until #R4 nothing
 * translated between them:
 *
 *   1. LEGACY SINGLETON — `resource_id` is the platform SLUG ('instagram'),
 *      not the account. Written by ManagesIntegrationConnection::
 *      defaultResourceId(), InstagramSourceGenerator and GoogleBusinessAutoSync.
 *      The real identity lives in the payload.
 *   2. FOUND-14 MULTI-ACCOUNT — `resource_id` is 'acct-<sha1 prefix>' and
 *      `canonical_key` carries the normalised URL/handle.
 *   3. P8 ROUTING — `resource_id` is the catalog identifier ('crucibletattooco')
 *      and `canonical_key` is NULL.
 *
 * So the build's own Instagram connection (scheme 1) and the same account
 * harvested from the person's Linktree (scheme 3) compared unequal, and the
 * owner got two Instagram connections, two ingest.sources rows and two
 * recurring sync jobs for ONE account (#R4, docs/reviews/
 * 2026-08-18-instagram-build-wave-RESULTS-RUN2.md).
 *
 * MATCHES ONLY ON A POSITIVELY DERIVED IDENTIFIER. "This row wears the legacy
 * marker" is NOT evidence that it is the account in hand — dev carries a
 * youtube.channel marker row for @dvlpmnttv sitting beside an 'acct-*' row for
 * casey, two entirely different channels. A resolver that treated the marker as
 * a wildcard would merge them and destroy a real connection. When no identifier
 * can be derived, this returns null and the caller creates the row exactly as
 * before: fail OPEN, never fail-merged.
 */
final class ConnectionIdentity
{
    /**
     * Surfaces whose PLATFORM treats the handle as case-insensitive — the
     * only ones matchExisting() may fold (M-7). Deliberately an allowlist:
     * IdentifierKind::Handle is a parse-strategy label and covers
     * case-SENSITIVE opaque codes too (discord.server invite codes — critic
     * catch, 2026-08-21). Verify the platform semantic before adding one.
     *
     * @var list<string>
     */
    private const CASE_INSENSITIVE_HANDLE_SURFACES = [
        'youtube.channel',
        'instagram.profile',
        'tiktok.profile',
        'x.profile',
        'facebook.profile',
        'threads.profile',
        'twitch.channel',
        'reddit.profile',
        'snapchat.profile',
        'telegram.channel',
        'kick.channel',
    ];

    public function __construct(
        private readonly IriCanonicalizer $canonicalizer,
        private readonly LinkProjector $projector,
    ) {}

    /**
     * The id of the user's live connection on $surfaceKey that is $identifier,
     * or null when they have none.
     *
     * @param  string|null  $ignoreConnectionId  skip this row (a caller that already
     *                                           holds it, e.g. a conflict incumbent)
     */
    public function matchExisting(User $user, string $surfaceKey, string $identifier, ?string $ignoreConnectionId = null): ?string
    {
        if ($identifier === '') {
            return null;
        }

        $rows = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('surface_key', $surfaceKey)
            ->whereNull('deleted_at')
            ->get(['id', 'resource_id', 'canonical_key', 'payload']);

        if ($ignoreConnectionId !== null) {
            $rows = $rows->reject(fn ($row) => (string) $row->id === $ignoreConnectionId);
        }

        return $this->matchWithin($rows, $surfaceKey, $identifier);
    }

    /**
     * The same three-scheme comparison, against rows the caller ALREADY holds.
     *
     * Split out so a caller with many identifiers to check can pay for one
     * query instead of one per identifier. The suggestions inbox renders up
     * to 100 cards and asks this question of every one of them; going through
     * matchExisting() there would have put 100 SELECTs on a GET, which is the
     * cost SCALE-8 had just finished taking off that handler.
     *
     * @param  Collection<int, IntegrationConnection>  $rows  the user's live connections on $surfaceKey
     */
    public function matchWithin(Collection $rows, string $surfaceKey, string $identifier): ?string
    {
        if ($identifier === '' || $rows->isEmpty()) {
            return null;
        }

        // 1. Exact — scheme 3 against itself, and the overwhelmingly common
        //    case. Kept first so the fix costs a string compare on the hot path.
        //
        //    M-7 (matrix run 2, thejunglegiants live): HANDLE surfaces compare
        //    case-insensitively — youtube.com/@TheJungleGiants and
        //    /@thejunglegiants are the same channel, and the case-sensitive
        //    compare re-proposed the user's own already-connected channel as a
        //    suggestion. Explicit allowlist, NOT IdentifierKind::Handle
        //    (critic catch: the kind is a PARSE-strategy label, and e.g.
        //    discord.server tags its case-SENSITIVE invite codes as Handle) —
        //    only surfaces whose platform treats the handle itself as
        //    case-insensitive fold. Add a surface here only with that platform
        //    semantic verified. (YouTube's UC… branch shares the handle
        //    surface; two of one user's own channels colliding
        //    case-insensitively is not a real shape.)
        $foldable = in_array($surfaceKey, self::CASE_INSENSITIVE_HANDLE_SURFACES, true);
        $needle1 = $foldable ? self::fold($identifier) : $identifier;
        foreach ($rows as $row) {
            $candidate = $foldable ? self::fold((string) $row->resource_id) : (string) $row->resource_id;
            if ($candidate === $needle1) {
                return (string) $row->id;
            }
        }

        // 2. The FOUND-14 column, which exists for exactly this question. Read
        //    only — never stamped from here: the two schemes disagree on what
        //    the canonical value IS (the dashboard stores a YouTube HANDLE
        //    where the routing lane stores a channel ID), so writing one
        //    lane's answer into a column the other treats as authoritative,
        //    under a UNIQUE index, would trade this bug for a worse one.
        $needle = self::fold($identifier);
        foreach ($rows as $row) {
            if ($row->canonical_key !== null && self::fold((string) $row->canonical_key) === $needle) {
                return (string) $row->id;
            }
        }

        // 3. Scheme 1. Derive the marker row's REAL identity and compare that.
        $marker = LegacyPlatformMap::legacyFor($surfaceKey);
        foreach ($rows as $row) {
            if ((string) $row->resource_id !== $marker) {
                continue;
            }

            $derived = $this->identityOf($row, $surfaceKey);
            if ($derived !== null && self::fold($derived) === $needle) {
                return (string) $row->id;
            }
        }

        return null;
    }

    /**
     * A legacy-marker row's real identity, or null when it cannot be read.
     *
     * Both branches resolve through the SAME projector that resolved the
     * incoming link, which is what makes the comparison correct by
     * construction rather than by a hand-written parse agreeing with the
     * catalog by luck.
     */
    private function identityOf(IntegrationConnection $row, string $surfaceKey): ?string
    {
        // `?? []` rather than is_array(): the property is annotated
        // array<string,mixed> (PHPStan narrows is_array to always-true) but the
        // annotation itself records that payload is NOT NULL only on Postgres —
        // the SQLite test mirror allows null, so the guard is real.
        $payload = (array) ($row->payload ?? []);

        // A stored URL is the strongest evidence: re-project it and require it
        // to land on THIS surface. A marker row whose url projects elsewhere
        // (or nowhere) is not this account.
        $url = is_string($payload['url'] ?? null) ? trim($payload['url']) : '';
        if ($url !== '') {
            $projection = $this->projector->project($this->canonicalizer->canonicalize($url));
            if ($projection->surfaceKey === $surfaceKey && $projection->identifier !== null) {
                return $projection->identifier;
            }
        }

        // No url. This is the ACTUAL R4 shape and the reason a url-only
        // resolver would have passed its test and done nothing in production:
        // InstagramConnectionSeeder writes username/fullName/images and never a
        // `url` key at all. On a handle-identity surface the stored username IS
        // the identifier, so rebuild the canonical URL from the catalog's own
        // template and project THAT — same rule, same answer, no second parser.
        $surface = CompiledCatalog::surface($surfaceKey);
        $username = is_string($payload['username'] ?? null) ? trim($payload['username']) : '';
        $template = is_string($surface['canonical_url_template'] ?? null) ? $surface['canonical_url_template'] : '';

        if ($username === '' || $template === '' || ($surface['identifier_kind'] ?? null) !== 'handle') {
            return null;
        }

        $rebuilt = preg_replace('/\{[a-z_]+\}/', $username, $template);
        if (! is_string($rebuilt) || $rebuilt === $template) {
            return null;
        }

        $projection = $this->projector->project($this->canonicalizer->canonicalize($rebuilt));

        return $projection->surfaceKey === $surfaceKey ? $projection->identifier : null;
    }

    /**
     * Case-folded for comparison. Every handle surface treats its handles
     * case-insensitively (instagram.com/Casey and /casey are one account), and
     * the projector preserves the case the link happened to be written in, so
     * a case-sensitive compare would miss the match this class exists to make.
     *
     * The one theoretical cost: youtube.channel is a handle-kind surface whose
     * detector also captures case-SENSITIVE channel ids, so two ids differing
     * only by case would fold together. That needs one user to have connected
     * both, and no such pair exists or plausibly could.
     */
    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
