<?php

namespace App\Console\Commands;

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Services\PreAccount\BuildProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test harness (Get Started rebuild, 2026-09-07): the read-side counterpart
 * to setup:reset. Reads a user's latest core.pre_account_builds row's ledger
 * (core.pre_account_build_events) and prints how long each discovery stage
 * took, so every later plan file in the Get Started chain can point at a
 * live number instead of "it felt fast".
 *
 * PAIRING (the tricky part — see BuildProgress's own docblock and
 * SetupPayload::openStages(), which implements the same head-per-stage/token
 * key but only to answer "is this stage open right now", not to keep BOTH
 * timestamps of a pair the way timing needs):
 *
 *   - PLAIN rows pair by stage alone: a STARTED is answered by the next
 *     terminal row for that same stage carrying no token.
 *   - TOKENED rows (payload.token) pair by stage + token: a STARTED with a
 *     token is answered only by a terminal carrying the SAME stage + token,
 *     so two concurrent producers on one stage (an Instagram scrape and a
 *     store probe, both on `platforms`) never close each other's row.
 *
 * Unlike openStages(), this command needs the FULL ordered history, not just
 * the newest row per key — so it queries core.pre_account_build_events
 * directly (never BuildProgressReader::events(), which caps at 50 rows and
 * is built for the live signup poll, not an accurate timing read) and walks
 * it chronologically, FIFO-pairing each STARTED with the next terminal row
 * that shares its exact key.
 */
class SetupTimingCommand extends Command
{
    protected $signature = 'setup:timing {user : id, handle or primary_email} {--watch : re-print every 3s until nothing is open} {--json= : append one JSON log line to this path on the final print}';

    protected $description = "Read a user's latest pre-account build ledger as a timing table";

    /**
     * Stages whose closure marks "all platforms.* ready" (Get Started plan
     * chain, file 00 pre-flight). connect|verify are not in
     * PreAccountBuildEvent::STAGES today and may never appear on a real
     * ledger — included per the brief so a future stage rename doesn't need
     * this command touched, and handled gracefully (see computeMarks()):a
     * stage that never appears contributes nothing rather than erroring.
     */
    private const READY_STAGES = ['platforms', 'listing', 'website', 'connect', 'verify'];

    /** --watch poll interval, seconds — matches the brief's "every 3s". */
    private const WATCH_INTERVAL_SECONDS = 3;

    /**
     * --watch safety ceiling: 200 * 3s = 10 minutes, the same clock
     * BuildProgressReader::CEILING_MINUTES and SetupPayload::openStages()
     * settle a build by — so this harness can never spin longer than the
     * build pipeline itself would still call "pending". Not in the brief;
     * added because an unbounded loop against a genuinely stuck/failed build
     * (a leaked STARTED row, exactly the leak openStages() guards against)
     * would otherwise run forever.
     */
    private const WATCH_MAX_ITERATIONS = 200;

    public function handle(): int
    {
        $needle = (string) $this->argument('user');
        // Same three-way resolution as setup:reset (id / handle / email), but
        // against the REAL column: core.users has no `email` column, only
        // `primary_email`. Postgres type-checks every predicate's literal
        // regardless of OR precedence, so `id = 'a-handle'` throws
        // invalid-input-syntax-for-uuid for any non-UUID needle before
        // handle/primary_email are ever reached — found live running this
        // command (2026-09-07), the same root cause as SetupResetCommand's
        // sibling fix. Only add the `id` predicate when the needle is
        // actually UUID-shaped.
        $query = User::query()->where('handle', $needle)->orWhere('primary_email', $needle);
        if (Str::isUuid($needle)) {
            $query->orWhere('id', $needle);
        }
        $user = $query->first();
        if ($user === null) {
            $this->error("No user matches [$needle].");

            return self::FAILURE;
        }

        $build = PreAccountBuild::query()->where('user_id', $user->id)->latest('created_at')->first();
        if ($build === null) {
            $this->error("No pre_account_builds row for {$user->handle}.");

            return self::FAILURE;
        }

        $iteration = 0;
        $pairs = [];
        $stillOpen = true;
        $identitySeconds = null;
        $allReadySeconds = null;

        do {
            $iteration++;
            $rows = $this->loadRows($build);
            $pairs = $this->pair($rows);
            $stillOpen = $this->hasOpenPair($pairs);
            [$identitySeconds, $allReadySeconds] = $this->computeMarks($build, $rows, $pairs);
            $isLast = ! $this->option('watch') || ! $stillOpen || $iteration >= self::WATCH_MAX_ITERATIONS;

            $this->renderTable($pairs);
            $this->renderSummary($pairs, $identitySeconds, $allReadySeconds);

            if (! $isLast) {
                sleep(self::WATCH_INTERVAL_SECONDS);
            }
        } while (! $isLast);

        if ($iteration >= self::WATCH_MAX_ITERATIONS && $stillOpen) {
            $this->warn('--watch stopped at the 10-minute safety ceiling with stages still open.');
        }

        $jsonPath = $this->option('json');
        if (is_string($jsonPath) && $jsonPath !== '') {
            $this->appendJson($jsonPath, $user, $build, $pairs, $identitySeconds, $allReadySeconds);
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, \stdClass> Each row carries ->stage, ->status,
     *                                    ->label, ->payload, ->created_at (the columns selected below).
     */
    private function loadRows(PreAccountBuild $build): Collection
    {
        return DB::table('core.pre_account_build_events')
            ->where('build_id', $build->id)
            // The ordered-UUID id breaks ties in write order — created_at is
            // written to the second (see BaseModel::getDateFormat() and
            // SetupPayload::openStages()'s own comment), so two rows in the
            // same wall-clock second sort however Postgres happens to hand
            // them back without this.
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['stage', 'status', 'label', 'payload', 'created_at']);
    }

    private function tokenOf(mixed $payload): ?string
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : (array) $payload;
        $token = is_array($decoded) ? ($decoded[BuildProgress::TOKEN] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * FIFO-pairs each STARTED row with the next terminal row sharing its
     * exact stage+token key, walking the ledger in chronological order.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array{key: string, stage: string, token: ?string, started: ?Carbon, closed: ?Carbon, status: string}>
     */
    private function pair(Collection $rows): array
    {
        $pairs = [];
        /** @var array<string, list<int>> $openQueue key => FIFO list of $pairs indices still waiting on a terminal */
        $openQueue = [];

        foreach ($rows as $row) {
            $token = $this->tokenOf($row->payload);
            $key = $row->stage.($token === null ? '' : "\0".$token);
            $at = Carbon::parse($row->created_at);

            if ($row->status === PreAccountBuildEvent::STATUS_STARTED) {
                if (! empty($openQueue[$key])) {
                    // Already an unanswered STARTED for this exact key.
                    // BuildProgress::note() guards against writing this at
                    // insert time, but a reader must not assume that guard
                    // always held for every historical row — treat the
                    // duplicate as the same open pair rather than opening a
                    // second one that could only ever be answered by
                    // stealing the first pair's terminal.
                    continue;
                }
                $pairs[] = [
                    'key' => $key, 'stage' => $row->stage, 'token' => $token,
                    'started' => $at, 'closed' => null, 'status' => PreAccountBuildEvent::STATUS_STARTED,
                ];
                $openQueue[$key][] = array_key_last($pairs);

                continue;
            }

            // Terminal row (landed|skipped|failed).
            if (! empty($openQueue[$key])) {
                $idx = array_shift($openQueue[$key]);
                $pairs[$idx]['closed'] = $at;
                $pairs[$idx]['status'] = $row->status;

                continue;
            }

            // Orphan terminal: no STARTED ever opened this exact key (e.g. a
            // one-shot stage whose producer logs only the landed row).
            // Represented anyway so every ledger row is accounted for in the
            // table/JSON; started stays null rather than guessing a start.
            $pairs[] = [
                'key' => $key, 'stage' => $row->stage, 'token' => $token,
                'started' => null, 'closed' => $at, 'status' => $row->status,
            ];
        }

        return $pairs;
    }

    /** @param  list<array{key: string, stage: string, token: ?string, started: ?Carbon, closed: ?Carbon, status: string}>  $pairs */
    private function hasOpenPair(array $pairs): bool
    {
        foreach ($pairs as $p) {
            if ($p['started'] !== null && $p['closed'] === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @param  list<array{key: string, stage: string, token: ?string, started: ?Carbon, closed: ?Carbon, status: string}>  $pairs
     * @return array{0: ?int, 1: ?int} [identity_s, all_ready_s]
     */
    private function computeMarks(PreAccountBuild $build, Collection $rows, array $pairs): array
    {
        $t0 = Carbon::parse($build->created_at);

        // "identity landed": the first `landed` row for stage=identity, by
        // raw row scan — independent of the pairing key, per the brief.
        $identitySeconds = null;
        foreach ($rows as $row) {
            if ($row->stage === PreAccountBuildEvent::STAGE_IDENTITY && $row->status === PreAccountBuildEvent::STATUS_LANDED) {
                $identitySeconds = (int) $t0->diffInSeconds(Carbon::parse($row->created_at));

                break;
            }
        }

        // "all platforms.* ready": the moment after which no
        // platforms|listing|website|connect|verify head is still STARTED —
        // i.e. the LAST closure among any stage+token pair on one of those
        // stages. Null (not yet reached) while any such pair is still open;
        // also null when none of those stages ever appeared on this ledger
        // (nothing to time — not an error, per the brief).
        $allReadySeconds = null;
        $readyPairs = array_values(array_filter($pairs, static fn (array $p): bool => in_array($p['stage'], self::READY_STAGES, true)));
        if ($readyPairs !== []) {
            $anyOpen = false;
            $maxClosed = null;
            foreach ($readyPairs as $p) {
                if ($p['closed'] === null) {
                    $anyOpen = true;

                    break;
                }
                if ($maxClosed === null || $p['closed']->gt($maxClosed)) {
                    $maxClosed = $p['closed'];
                }
            }
            if (! $anyOpen && $maxClosed !== null) {
                $allReadySeconds = (int) $t0->diffInSeconds($maxClosed);
            }
        }

        return [$identitySeconds, $allReadySeconds];
    }

    /** @param  list<array{key: string, stage: string, token: ?string, started: ?Carbon, closed: ?Carbon, status: string}>  $pairs */
    private function renderTable(array $pairs): void
    {
        $rows = array_map(function (array $p): array {
            $elapsed = match (true) {
                $p['started'] !== null && $p['closed'] !== null => (string) (int) $p['started']->diffInSeconds($p['closed']),
                $p['started'] !== null => ((int) $p['started']->diffInSeconds(now())).' (so far)',
                default => 'n/a',
            };
            $status = $p['closed'] === null && $p['started'] !== null ? 'open' : $p['status'];

            return [
                $p['stage'],
                $p['token'] ?? '',
                $p['started']?->toIso8601String() ?? '—',
                $p['closed']?->toIso8601String() ?? 'open',
                $elapsed,
                $status,
            ];
        }, $pairs);

        $this->table(['Stage', 'Token', 'Started', 'Terminal', 'Elapsed(s)', 'Status'], $rows);
    }

    /**
     * @param  list<array{key: string, stage: string, token: ?string, started: ?Carbon, closed: ?Carbon, status: string}>  $pairs
     */
    private function renderSummary(array $pairs, ?int $identitySeconds, ?int $allReadySeconds): void
    {
        $started = array_values(array_filter(array_map(static fn (array $p) => $p['started'], $pairs)));
        $closed = array_values(array_filter(array_map(static fn (array $p) => $p['closed'], $pairs)));

        if ($started !== [] && $closed !== []) {
            $min = $started[0];
            foreach ($started as $s) {
                if ($s->lt($min)) {
                    $min = $s;
                }
            }
            $max = $closed[0];
            foreach ($closed as $c) {
                if ($c->gt($max)) {
                    $max = $c;
                }
            }
            $this->line('Total (first-open to last-close): '.((int) $min->diffInSeconds($max)).'s');
        } else {
            $this->line('Total (first-open to last-close): n/a');
        }

        $this->line('Identity landed: '.($identitySeconds !== null ? $identitySeconds.'s' : 'n/a'));
        $this->line('All platforms.* ready: '.($allReadySeconds !== null ? $allReadySeconds.'s' : 'n/a'));
    }

    /**
     * @param  list<array{key: string, stage: string, token: ?string, started: ?Carbon, closed: ?Carbon, status: string}>  $pairs
     */
    private function appendJson(string $path, User $user, PreAccountBuild $build, array $pairs, ?int $identitySeconds, ?int $allReadySeconds): void
    {
        $line = [
            'ts' => now()->toIso8601String(),
            'user' => $user->handle,
            'build' => (string) $build->id,
            'identity_s' => $identitySeconds,
            'all_ready_s' => $allReadySeconds,
            'stages' => $this->jsonStages($pairs),
        ];

        file_put_contents($path, json_encode($line, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * One entry per stage+token pair, keyed by a string that disambiguates
     * both kinds of repeat: a token suffixes the key ("platforms#<token>"),
     * and a SECOND plain (untokened) pair on the same stage — a stage that
     * closed and reopened, per BuildProgress's own docblock — gets an
     * ordinal suffix ("platforms#2", "platforms#3", ...) instead of
     * silently overwriting the first. Losslessly covers every pair the
     * table shows; the brief's own examples ("platforms" / "platforms#tok")
     * are the token case only, generalised here to the plain-repeat case too.
     *
     * @param  list<array{key: string, stage: string, token: ?string, started: ?Carbon, closed: ?Carbon, status: string}>  $pairs
     * @return array<string, array{started: ?string, closed: ?string, status: string}>
     */
    private function jsonStages(array $pairs): array
    {
        $out = [];
        $plainSeen = [];
        foreach ($pairs as $p) {
            if ($p['token'] !== null) {
                $jsonKey = $p['stage'].'#'.$p['token'];
            } else {
                $n = ($plainSeen[$p['stage']] ?? 0) + 1;
                $plainSeen[$p['stage']] = $n;
                $jsonKey = $n === 1 ? $p['stage'] : $p['stage'].'#'.$n;
            }

            $status = $p['closed'] === null && $p['started'] !== null ? PreAccountBuildEvent::STATUS_STARTED : $p['status'];

            $out[$jsonKey] = [
                'started' => $p['started']?->toIso8601String(),
                'closed' => $p['closed']?->toIso8601String(),
                'status' => $status,
            ];
        }

        return $out;
    }
}
