<?php

namespace App\Routing\Probes;

use App\Routing\Iri;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Cache;

/**
 * The decision to SPEND an outbound request, made before any is spent
 * (plan §11).
 *
 * Four refusals, in cost order — each one is cheaper than the one after it, so
 * the cheapest disqualification always wins:
 *
 *   1. lexical  — scheme, IP literals, own-infrastructure suffixes. Pure
 *                 string work; costs nothing and needs no DNS.
 *   2. shape    — a URL that already projected to a real surface has nothing
 *                 to probe FOR, and one carrying a deep path is a page on a
 *                 site, not the site.
 *   3. cooldown — a URL probed recently keeps its answer. Keyed by URL, not by
 *                 user: the request is the cost, so two users pasting the same
 *                 storefront should cost one probe.
 *   4. budget   — the three ceilings in ProbeBudget.
 *
 * The lexical pass is a PRE-filter, not the SSRF defence: SafeUrlFetcher still
 * resolves and validates every hop at fetch time. Its job here is to make sure
 * an obviously-bad URL never claims budget or a cooldown slot, so a paste loop
 * aimed at 169.254.169.254 cannot exhaust anyone's allowance.
 */
class ProbeGate
{
    /**
     * Confidence a successful probe reports. Above `shop`'s auto threshold
     * (75) with the harvest penalty (10) still applied, because a
     * platform-only endpoint answering with a well-formed body is direct
     * evidence rather than a pattern guess — but deliberately not 100: a
     * probed storefront is still a machine's reading of someone's site.
     */
    public const PROBE_CONFIDENCE = 90;

    /** Answers kept this long, hit or miss. */
    private const COOLDOWN_MINUTES = 720;

    public function __construct(private readonly ProbeBudget $budget) {}

    /**
     * @return array{allowed: bool, reason: ?string}
     */
    public function allows(Iri $iri, ?string $userId): array
    {
        $lexical = $this->lexicalRefusal($iri);
        if ($lexical !== null) {
            return ['allowed' => false, 'reason' => $lexical];
        }

        if ($this->cachedAnswer($iri) !== null) {
            return ['allowed' => false, 'reason' => 'cooldown'];
        }

        if (! $this->budget->tryClaim($userId)) {
            return ['allowed' => false, 'reason' => 'budget_'.$this->budget->exhaustedDimension($userId)];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Lexical disqualifications — no DNS, no I/O. Mirrors the denylist
     * SafeUrlFetcher enforces at fetch time so the two cannot disagree about
     * what our own infrastructure is.
     */
    private function lexicalRefusal(Iri $iri): ?string
    {
        // The canonicaliser already refuses own-infrastructure hosts, IP hosts
        // and shorteners. Its reason is carried through verbatim rather than
        // flattened to "unroutable": a refusal nobody can attribute is a
        // refusal nobody can debug.
        if ($iri->rejected !== null) {
            return $iri->rejected;
        }

        if (! in_array($iri->scheme, ['http', 'https'], true)) {
            return 'scheme_not_probeable';
        }

        $host = strtolower((string) $iri->host);
        if ($host === '') {
            return 'no_host';
        }

        // An IP literal never carries a storefront we would connect, and is the
        // shape every SSRF attempt takes.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return 'ip_literal';
        }

        // Belt to the canonicaliser's braces: it refuses these too, but a
        // caller that hand-builds an Iri (a test, a future importer) must not
        // be able to slip past by skipping canonicalisation.
        foreach (SafeUrlFetcher::deniedHostSuffixes() as $suffix) {
            $suffix = strtolower(trim((string) $suffix));
            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.'.$suffix))) {
                return 'own-infra';
            }
        }

        // A URL that already matched a real surface needs no probe, and one
        // with a deep path is a page ON a storefront rather than the
        // storefront — the probe's endpoints hang off the origin.
        if (substr_count(trim($iri->path, '/'), '/') >= 2) {
            return 'not_a_storefront_root';
        }

        return null;
    }

    /**
     * The cached answer for this URL, or null when nothing is cached. Callers
     * use this BEFORE claiming budget so a repeat paste is free.
     *
     * @return array<string, mixed>|null
     */
    public function cachedAnswer(Iri $iri): ?array
    {
        $url = $iri->canonical ?? $iri->raw;
        $cached = Cache::get(CacheKeyGenerator::routingProbeUrl($url));

        return is_array($cached) ? $cached : null;
    }

    /**
     * Remember what a probe concluded — misses included. A miss that isn't
     * cached is a URL we re-probe on every scan of the same page.
     *
     * @param  array<string, mixed>  $answer
     */
    public function remember(Iri $iri, array $answer): void
    {
        Cache::put(
            CacheKeyGenerator::routingProbeUrl($iri->canonical ?? $iri->raw),
            $answer,
            now()->addMinutes((int) config('partna.routing.probe.cooldown_minutes', self::COOLDOWN_MINUTES)),
        );
    }
}
