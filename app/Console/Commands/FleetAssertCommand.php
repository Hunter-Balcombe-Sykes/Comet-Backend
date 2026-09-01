<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Estate invariants, asserted rather than printed.
 *
 * `fleet:verify` is the per-account DIAGNOSTIC — you name handles, it prints a
 * table, it always exits 0. This is its counterpart: it names no handles, runs
 * over the whole estate, and its EXIT CODE is the product. Every check below is
 * a defect class the 2026-08-31 cold-build audit or overnight discovery pass
 * found live; the citation sits on each one.
 *
 * Why this exists at all: the test suite proves the CODE handles the cases we
 * thought of. It cannot say the 273 real accounts are healthy right now, because
 * most of these failures come from scraped DATA or from infrastructure outside
 * the repo — an account whose Instagram bio yielded no usable name, a Cloudflare
 * cache rule nobody deployed. No unit test can ever catch those.
 *
 * TWO MODES, and the difference is the whole design:
 *
 *   ABSOLUTE — must be zero today and forever. Serving-plane exposure only.
 *              Deliberately has NO baseline: recording one would institutionalise
 *              the exposure it exists to catch.
 *   RATCHET  — compared against a recorded baseline, failing only when a count
 *              gets WORSE. The coverage backlogs are large and shrinking; a
 *              must-be-zero rule would be red every night from day one, and a
 *              check that is permanently red is a check nobody reads.
 *
 * Network checks are opt-in behind --http so the default run is pure DB.
 */
class FleetAssertCommand extends Command
{
    protected $signature = 'fleet:assert
        {--http : Also run the serving-plane checks (network: publish gate + edge TTL)}
        {--update-baseline : Rewrite the ratchet baseline from the current estate}
        {--baseline= : Path to the ratchet baseline (defaults to the checked-in one)}
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Assert estate-wide invariants; non-zero exit when one is breached.';

    private const BASELINE_PATH = 'scripts/launch-check/fleet-assert-baseline.json';

    private const MODE_ABSOLUTE = 'absolute';

    private const MODE_RATCHET = 'ratchet';

    /**
     * Ceiling for the edge's s-maxage. The app asks for ~30s; anything far above
     * it means something between the Worker and the visitor is rewriting the
     * header, which makes a deploy or a content edit invisible for that long and
     * silently promotes every purge failure into a user-visible stale page.
     */
    private const MAX_EDGE_S_MAXAGE = 300;

    /** Bound the nightly network fan-out; these two checks are samples, not sweeps. */
    private const HTTP_SAMPLE = 12;

    public function handle(): int
    {
        $results = $this->runChecks();

        if ($this->option('update-baseline')) {
            return $this->writeBaseline($results);
        }

        $baseline = $this->readBaseline();
        $rows = [];
        $breached = 0;

        foreach ($results as $r) {
            $limit = $r['mode'] === self::MODE_ABSOLUTE ? 0 : ($baseline[$r['key']] ?? 0);
            $ok = $r['actual'] <= $limit;
            $breached += $ok ? 0 : 1;
            $rows[] = $r + ['limit' => $limit, 'ok' => $ok];
        }

        $this->option('json') ? $this->renderJson($rows, $breached) : $this->renderTable($rows, $breached);

        return $breached === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<array{key:string,mode:string,actual:int,detail:string}> */
    private function runChecks(): array
    {
        $checks = [
            $this->noSector(),
            $this->partnaWithoutHeadshot(),
            $this->unusableName(),
            $this->duplicatePlaceId(),
            $this->unclaimableBuilds(),
            $this->claimedWithoutPublishFlag(),
        ];

        if ($this->option('http')) {
            $checks[] = $this->publishGate();
            $checks[] = $this->edgeTtl();
        }

        return $checks;
    }

    // ── Ratchet checks — DB only ────────────────────────────────────────────

    /**
     * #F5 / #F22. A null sector silently revokes menu/reservations/ordering
     * capability, and the OCR menu scan bails without logging. 98 of 273 at the
     * time this check was written.
     */
    private function noSector(): array
    {
        $n = User::query()->whereNull('deleted_at')->whereNull('sector')->count();

        return $this->result('no_sector', self::MODE_RATCHET, $n, 'accounts with sector NULL');
    }

    /**
     * #A3 / #E9. HeadshotAutoSeeder runs at build time only — no claim hook, no
     * refresh hook, no backfill — so an account that missed it never gets one,
     * and the favicon and share image both fall back to a generated mark.
     */
    private function partnaWithoutHeadshot(): array
    {
        // Estate-scale (hundreds), not web-scale: two plucks beat a cross-schema
        // join that SQLite and Postgres would spell differently. Revisit past ~50k.
        $siteIds = SiteMedia::query()
            ->where('purpose', SiteMedia::PURPOSE_HEADSHOT)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('site_id')
            ->all();

        $userIds = Site::query()->whereIn('id', $siteIds)->pluck('user_id')->all();

        $n = User::query()
            ->whereNull('deleted_at')
            ->where('account_type', 'partna')
            ->whereNotIn('id', $userIds)
            ->count();

        return $this->result('partna_without_headshot', self::MODE_RATCHET, $n, 'partna accounts with no ready headshot');
    }

    /**
     * #F3. Instagram name derivation writes descriptors, emoji and raw handles
     * into first_name. This is also the hard dependency of the reviews
     * person-filter — matching reviews against a name of "✨" admits nothing and
     * against "Melbourne" admits everything.
     */
    private function unusableName(): array
    {
        $bad = 0;
        $examples = [];

        User::query()
            ->whereNull('deleted_at')
            ->select(['id', 'first_name', 'handle_lc'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$bad, &$examples) {
                foreach ($rows as $row) {
                    if (! self::nameIsUnusable((string) $row->first_name, (string) $row->handle_lc)) {
                        continue;
                    }
                    $bad++;
                    if (count($examples) < 3) {
                        $examples[] = $row->handle_lc;
                    }
                }
            });

        $detail = 'accounts whose first_name is blank, equal to the handle, or emoji';

        return $this->result('unusable_name', self::MODE_RATCHET, $bad, $detail, $examples);
    }

    /**
     * A name is unusable when it carries no person in it.
     *
     * Accents are NOT a failure and the estate proves why: of the three
     * non-ASCII first names on dev, "Biànca Restaurant" is an ordinary accented
     * name that must pass, while "🍎PLAYLUNCH" and "ʙᴇɴ" must not. So this tests
     * for two specific classes — emoji, and the styled Latin lookalikes
     * Instagram bios are full of (small caps, fullwidth, maths alphanumerics) —
     * rather than for non-ASCII, which would reject real people.
     */
    public static function nameIsUnusable(string $firstName, string $handleLc): bool
    {
        $name = trim($firstName);

        if ($name === '') {
            return true;
        }

        if ($handleLc !== '' && mb_strtolower($name) === mb_strtolower(trim($handleLc))) {
            return true;
        }

        // Emoji + pictographs.
        if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', $name)) {
            return true;
        }

        // Styled Latin lookalikes: IPA small caps (ʙᴇɴ), phonetic extensions,
        // fullwidth forms, and the maths alphanumeric block. Stops short of
        // U+02B0 so the ʻokina and similar real orthographic marks still pass.
        return (bool) preg_match('/[\x{0250}-\x{02AF}\x{1D00}-\x{1D7F}\x{FF01}-\x{FF5E}\x{1D400}-\x{1D7FF}]/u', $name);
    }

    /**
     * #E4. Two google_business connections for one place id leave two rating
     * aggregates and the wrong one wins — which is how a coffee shop published a
     * hair salon's "5/5 · Based on 174 reviews".
     */
    private function duplicatePlaceId(): array
    {
        // Counted as a subquery, not by hydrating the groups: ->count() on a
        // GROUP BY builder counts rows PER GROUP rather than the groups, so the
        // aggregate has to sit outside it. deleted_at is spelled out rather than
        // left to the SoftDeletes scope, which toBase() would drop silently.
        $duplicates = IntegrationConnection::query()
            ->select('place_id')
            ->whereNotNull('place_id')
            ->whereNull('deleted_at')
            ->groupBy('place_id')
            ->havingRaw('count(*) > 1')
            ->toBase();

        $n = DB::query()->fromSub($duplicates, 'duplicated_place_ids')->count();

        return $this->result('duplicate_place_id', self::MODE_RATCHET, $n, 'place_ids carrying more than one live connection');
    }

    /**
     * Wave 7. A build with neither a claim token nor a contact email has no
     * route to an owner at all: the token is the invite proof and the email is
     * the OTP target, so with both absent the site can never be claimed.
     */
    private function unclaimableBuilds(): array
    {
        $n = PreAccountBuild::query()
            ->whereNull('claimed_at')
            ->whereNull('claim_token_hash')
            ->whereNull('contact_email')
            ->count();

        return $this->result('unclaimable_builds', self::MODE_RATCHET, $n, 'unclaimed builds with no token and no contact email');
    }

    /**
     * Wave 7 / issue 22. published_by_claim records that publication was the
     * CLAIMER's act. A claimed build left false means releasing it re-publishes
     * a site the owner never chose to publish.
     */
    private function claimedWithoutPublishFlag(): array
    {
        $n = PreAccountBuild::query()
            ->whereNotNull('claimed_at')
            ->where('published_by_claim', false)
            ->count();

        return $this->result('claimed_without_publish_flag', self::MODE_RATCHET, $n, 'claimed builds still flagged published_by_claim=false');
    }

    // ── Absolute checks — serving plane, --http only ────────────────────────

    /**
     * The publish toggle must bind for a real owner.
     *
     * An UNCLAIMED pre-account build serving while unpublished is by design —
     * the pre-claim demo is the product pitch, and the 2026-08-25 owner ruling
     * reverted the gate that closed it. This check is therefore scoped to
     * accounts that are NOT unclaimed: someone who owns their site and switched
     * publishing off. For them, is_published=false must mean not readable.
     */
    private function publishGate(): array
    {
        $sites = Site::query()
            ->where('is_published', false)
            ->orderBy('subdomain')
            ->limit(self::HTTP_SAMPLE)
            ->get(['id', 'user_id', 'subdomain']);

        $ownerIds = User::query()
            ->whereNull('deleted_at')
            ->where('status', '<>', 'unclaimed')
            ->whereIn('id', $sites->pluck('user_id')->all())
            ->pluck('id')
            ->all();

        $exposed = [];
        foreach ($sites as $site) {
            if (! in_array($site->user_id, $ownerIds, true)) {
                continue;
            }
            if ($this->probeStatus((string) $site->subdomain) === 200) {
                $exposed[] = (string) $site->subdomain;
            }
        }

        return $this->result('publish_gate_exposed', self::MODE_ABSOLUTE, count($exposed), 'owned + unpublished sites still serving 200', $exposed);
    }

    /**
     * #E11. The served s-maxage must stay near what the app asks for. Found at
     * 86400 — and 604800 on two sites — against an application edgeTtl of 30.
     */
    private function edgeTtl(): array
    {
        $subdomains = Site::query()
            ->where('is_published', true)
            ->orderBy('subdomain')
            ->limit(self::HTTP_SAMPLE)
            ->pluck('subdomain');

        $over = [];
        foreach ($subdomains as $subdomain) {
            $ttl = $this->probeEdgeTtl((string) $subdomain);
            if ($ttl !== null && $ttl > self::MAX_EDGE_S_MAXAGE) {
                $over[] = $subdomain.'='.$ttl;
            }
        }

        $detail = 's-maxage above '.self::MAX_EDGE_S_MAXAGE.'s';

        return $this->result('edge_ttl_over_ceiling', self::MODE_ABSOLUTE, count($over), $detail, $over);
    }

    // ── HTTP probes ────────────────────────────────────────────────────────

    /** A transport fault is reported as unknown (null), never as a silent pass. */
    private function probeStatus(string $subdomain): ?int
    {
        try {
            return Http::timeout(10)->withoutRedirecting()->get($this->siteUrl($subdomain))->status();
        } catch (\Throwable $e) {
            $this->warn("probe failed for {$subdomain}: ".$e->getMessage());

            return null;
        }
    }

    private function probeEdgeTtl(string $subdomain): ?int
    {
        try {
            $header = Http::timeout(10)->get($this->siteUrl($subdomain))->header('cache-control');
        } catch (\Throwable $e) {
            $this->warn("probe failed for {$subdomain}: ".$e->getMessage());

            return null;
        }

        return preg_match('/s-maxage=(\d+)/i', (string) $header, $m) ? (int) $m[1] : null;
    }

    /** Apex from config; the label is our own handle_lc column, never user input. */
    private function siteUrl(string $subdomain): string
    {
        return 'https://'.$subdomain.'.'.config('partna.public_domain').'/';
    }

    // ── Baseline + output ──────────────────────────────────────────────────

    private function baselinePath(): string
    {
        return (string) ($this->option('baseline') ?: base_path(self::BASELINE_PATH));
    }

    /** @return array<string,int> */
    private function readBaseline(): array
    {
        $path = $this->baselinePath();
        if (! is_file($path)) {
            $this->warn('No baseline at '.$path.' — every ratchet check is being held at zero.');

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? array_map('intval', array_filter($decoded, 'is_numeric')) : [];
    }

    private function writeBaseline(array $results): int
    {
        $baseline = [];
        foreach ($results as $r) {
            if ($r['mode'] === self::MODE_RATCHET) {
                $baseline[$r['key']] = $r['actual'];
            }
        }

        ksort($baseline);
        file_put_contents(
            $this->baselinePath(),
            json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );

        $this->info('Baseline written to '.$this->baselinePath().' ('.count($baseline).' ratchet checks).');
        $this->line('Absolute checks are never baselined — they must be zero.');

        return self::SUCCESS;
    }

    private function renderTable(array $rows, int $breached): void
    {
        $this->table(
            ['check', 'mode', 'actual', 'limit', 'result', 'detail'],
            array_map(fn ($r) => [
                $r['key'],
                $r['mode'],
                $r['actual'],
                $r['limit'],
                $r['ok'] ? 'ok' : 'BREACH',
                $r['detail'].($r['examples'] ? ' — e.g. '.implode(', ', $r['examples']) : ''),
            ], $rows)
        );

        $breached === 0
            ? $this->info('All '.count($rows).' invariants hold.')
            : $this->error($breached.' of '.count($rows).' invariants BREACHED.');
    }

    private function renderJson(array $rows, int $breached): void
    {
        $this->line((string) json_encode([
            'breached' => $breached,
            'checks' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function result(string $key, string $mode, int $actual, string $detail, array $examples = []): array
    {
        return compact('key', 'mode', 'actual', 'detail', 'examples');
    }
}
