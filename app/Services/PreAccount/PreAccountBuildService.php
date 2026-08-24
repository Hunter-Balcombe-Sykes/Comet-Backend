<?php

namespace App\Services\PreAccount;

use App\Enums\AccountType;
use App\Exceptions\Site\SubdomainUnavailableException;
use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\User\HandleAllocator;
use App\Services\User\SiteProvisioningService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PreAccountBuildService
{
    public function __construct(
        private readonly SourceGeneratorRegistry $generators,
        private readonly HandleAllocator $handles,
        private readonly SiteProvisioningService $siteProvisioning,
    ) {}

    /**
     * Create (or re-serve) a pre-account build for a typed source ref.
     *
     * @return array{build: PreAccountBuild, reused: bool}
     *
     * @throws PreAccountBuildException
     */
    public function requestBuild(
        string $accountType,
        string $sourceType,
        string $rawSourceRef,
        ?string $sourceName,
        ?string $ipHash,
        ?PartnaStaff $staff = null,
        bool $publish = false,
        ?int $expiresDays = null,
        ?string $contactEmail = null,
        ?string $builtVia = null,
        bool $autoInvite = true,
    ): array {
        // A source type unknown to the registry (never configured, or configured
        // but its generator class doesn't exist yet — e.g. GoogleBusinessSourceGenerator
        // pre-Task-9) can never build for ANY account type; surface it as the same
        // pairing error rather than leaking the registry's raw InvalidArgumentException.
        try {
            $generator = $this->generators->for($sourceType);
        } catch (\InvalidArgumentException) {
            throw new PreAccountBuildException(
                PreAccountBuildException::SOURCE_PAIRING_INVALID,
                "Source '{$sourceType}' is not available for '{$accountType}' accounts."
            );
        }

        try {
            $ref = $generator->normalizeRef($rawSourceRef);
        } catch (\InvalidArgumentException $e) {
            throw new PreAccountBuildException(PreAccountBuildException::SOURCE_REF_INVALID, $e->getMessage());
        }
        $refLc = $generator->dedupeKey($ref);

        // Dedupe: one LIVE build per source. Failed live builds retry (F3).
        // Checked BEFORE the account_type/source_type pairing so re-serving an
        // existing build never re-validates the pairing — the re-served build
        // keeps its ORIGINAL account_type regardless of what this caller passed
        // (spec §4.1); e.g. a claim-flow retry can legitimately arrive with a
        // different (even mismatched) account_type than the build was created with.
        if ($existing = $this->findLive($sourceType, $refLc)) {
            // A staff request carrying an address for an existing live build must
            // not have it silently dropped. This early return sits ABOVE the
            // create block that writes contact_email, so before 2026-08-24 a CSV
            // row naming an already-built source left staff believing they had
            // gated a site that was in fact wide open — and after the invite-gate
            // landed, believing they had UNBLOCKED one that was still stranded.
            //
            // Staff only. A public caller must never be able to write
            // contact_email onto an existing row: that is the field deciding who
            // may claim it, so an anonymous write would be a takeover primitive.
            if ($staff !== null && $contactEmail !== null && trim($contactEmail) !== '') {
                $incoming = mb_strtolower(trim($contactEmail));

                // Locked read-modify-write: without lockForUpdate, two concurrent
                // requests for the same source (e.g. two CSV rows, or a retry
                // racing a CSV import) can both read an empty contact_email and
                // both write, one silently overwriting the other with no conflict
                // ever detected.
                $existing = DB::connection('pgsql')->transaction(function () use ($existing, $incoming) {
                    /** @var PreAccountBuild $locked */
                    $locked = PreAccountBuild::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
                    $current = mb_strtolower(trim((string) $locked->contact_email));

                    if ($current === '') {
                        // Self-serve builds have contact_email NULL by
                        // construction — an empty current value on a build that
                        // is NOT outreach means a real person is mid-signup on
                        // their own source. A staff CSV/import naming the same
                        // source must not attach its address here: that would
                        // silently hand the build's invite-gate to staff's
                        // address and lock the actual signer-upper out.
                        if ($locked->isOutreach()) {
                            // contact_email is not fillable — trusted staff
                            // lifecycle write.
                            $locked->forceFill(['contact_email' => $incoming])->save();
                        }
                    } elseif ($current !== $incoming) {
                        // Loudly, not silently: two different addresses claiming
                        // the same business is a human question, and picking
                        // either one automatically can hand the site to the
                        // wrong person.
                        throw new PreAccountBuildException(
                            PreAccountBuildException::CONTACT_EMAIL_CONFLICT,
                            "A build for this source already exists with a different contact email. Use PATCH /staff/builds/{$locked->id}/contact-email to change it deliberately."
                        );
                    }

                    return $locked;
                });
            }

            return ['build' => $this->reserve($existing, $staff, $ipHash), 'reused' => true];
        }

        // ONE config map decides which account type may build from which source —
        // only enforced from here on, i.e. when actually creating a NEW build.
        $allowed = config("partna.pre_account.sources.{$accountType}", []);
        if (! in_array($sourceType, $allowed, true)) {
            throw new PreAccountBuildException(
                PreAccountBuildException::SOURCE_PAIRING_INVALID,
                "Source '{$sourceType}' is not available for '{$accountType}' accounts."
            );
        }

        // Early-access builds have no expiry until a staff approval opens the
        // 30-day claim window (spec §3.5); every other build expires from creation.
        $expiresAt = $builtVia === PreAccountBuild::VIA_EARLY_ACCESS
            ? null
            : now()->addDays($expiresDays ?? (int) config('partna.pre_account.expiry_days', 30));

        try {
            $build = DB::connection('pgsql')->transaction(function () use (
                $accountType, $sourceType, $ref, $refLc, $sourceName, $ipHash, $staff, $expiresAt, $contactEmail, $builtVia, $autoInvite
            ) {
                // LIFE-2: signup-path abuse cap (F2), re-checked INSIDE the transaction
                // under an advisory lock. The previous pre-transaction check-then-act let
                // concurrent same-IP requests all read the same pre-commit count and all
                // pass — the lock serializes builders for one IP so each sees the prior
                // one's committed row before counting.
                if ($staff === null && $ipHash !== null) {
                    DB::connection('pgsql')->select('select pg_advisory_xact_lock(hashtext(?))', ["pre_account_build_ip:{$ipHash}"]);
                    $cap = (int) config('partna.pre_account.max_unclaimed_per_ip', 3);
                    $outstanding = PreAccountBuild::live()->where('created_ip_hash', $ipHash)->count();
                    if ($outstanding >= $cap) {
                        throw new PreAccountBuildException(
                            PreAccountBuildException::IP_BUILD_CAP,
                            'Too many unclaimed builds from this address. Claim one first.'
                        );
                    }
                }

                $seed = $this->generators->for($sourceType)->handleSeed($ref, $sourceName);
                $user = $this->createProvisionalUserWithRetry($seed, $accountType, $sourceName);

                // Real subdomain at build time, unpublished for signup builds; the
                // staff publish knob flips AFTER generation succeeds (in the job).
                //
                // SIGNUP-1: the subdomain is the handle the user was ACTUALLY
                // allocated on the line above — not the raw $seed re-normalised.
                // subdomainBaseFromHandle() replaces dots/apostrophes with hyphens
                // where Str::slug() drops them, and suffixes '-2' where
                // HandleAllocator suffixes '2', so re-deriving from $seed produced
                // a subdomain that disagreed with the handle and a site_url that
                // 404'd. handle_lc is the honest target: it is the routing key
                // (SyncSubdomainToKvJob lowercases the handle to key KV).
                // The handle here is machine-allocated by HandleAllocator, which
                // already required it to be free as a subdomain — so a refusal means
                // that guarantee broke, which is an alarm, not bad input. Report at
                // this call site (the bootstrap path deliberately does not) and
                // re-throw so the outer transaction still rolls back.
                try {
                    $this->siteProvisioning->createSiteForHandle(
                        $user->id,
                        (string) $user->handle_lc,
                        published: false,
                    );
                } catch (SubdomainUnavailableException $e) {
                    report($e);

                    throw $e;
                }

                $build = new PreAccountBuild([
                    'source_type' => $sourceType,
                    'source_ref' => $ref,
                    'source_ref_lc' => $refLc,
                    'built_via' => $builtVia ?? ($staff ? PreAccountBuild::VIA_STAFF : PreAccountBuild::VIA_SIGNUP),
                    'created_ip_hash' => $staff ? null : $ipHash,
                    'expires_at' => $expiresAt,
                    'contact_email' => $contactEmail,
                    'auto_invite' => $autoInvite,
                ]);
                // SEC-4: build_state is no longer fillable. Set explicitly (not left to
                // the DB DEFAULT 'pending') so the in-memory model matches the row
                // immediately after save() — Eloquent doesn't re-fetch DB-computed
                // column defaults post-insert.
                $build->build_state = PreAccountBuild::STATE_PENDING;
                $build->user()->associate($user);
                if ($staff) {
                    $build->builtByStaff()->associate($staff);
                }
                $build->save();

                return $build;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the race on pre_account_builds_live_source_unique — the other
            // request's build is the canonical one; re-serve it (spec §4.1).
            $existing = $this->findLive($sourceType, $refLc);
            if ($existing) {
                return ['build' => $this->reserve($existing, $staff, $ipHash), 'reused' => true];
            }
            throw new PreAccountBuildException(
                PreAccountBuildException::SOURCE_REF_INVALID,
                'Could not create the build. Try again.'
            );
        }

        GeneratePreAccountSiteJob::dispatch($build->id, $build->source_type, $publish)->afterCommit();

        return ['build' => $build, 'reused' => false];
    }

    private function findLive(string $sourceType, string $refLc): ?PreAccountBuild
    {
        return PreAccountBuild::live()
            ->where('source_type', $sourceType)
            ->where('source_ref_lc', $refLc)
            ->first();
    }

    /**
     * LIFE-3: allocate + create the provisional user with savepoint-isolated
     * retry. HandleAllocator::allocate() checks core.users for a free handle_lc
     * BEFORE this INSERT — a genuine TOCTOU when two concurrent builds land on
     * the same seed. Each attempt runs in its own nested transaction (mirrors
     * SiteProvisioningService::tryCreateSite() / UserBootstrapService) so a
     * core_users_handle_lc_unique violation only rolls back to the SAVEPOINT,
     * leaving the outer build transaction healthy for the retry to re-allocate
     * (now seeing the just-committed colliding row) instead of poisoning it or
     * surfacing to the outer catch (UniqueConstraintViolationException), which
     * assumes any such violation is pre_account_builds_live_source_unique.
     */
    private function createProvisionalUserWithRetry(string $seed, string $accountType, ?string $sourceName): User
    {
        for ($i = 0; $i < 5; $i++) {
            $handle = $this->handles->allocate($seed);
            if ($user = $this->tryCreateProvisionalUser($handle, $accountType, $sourceName)) {
                return $user;
            }
        }

        // Exhaustion is anomalous (5 handle collisions in a row) — surface it to
        // Nightwatch rather than letting it fail silently as a generic 4xx.
        report(new \RuntimeException("PreAccountBuildService: exhausted handle allocation retries for seed '{$seed}'"));

        throw new PreAccountBuildException(
            PreAccountBuildException::SOURCE_REF_INVALID,
            'Could not allocate a unique handle. Try again.'
        );
    }

    /** @param array{handle: string, handle_lc: string} $handle */
    private function tryCreateProvisionalUser(array $handle, string $accountType, ?string $sourceName): ?User
    {
        try {
            return DB::connection('pgsql')->transaction(function () use ($handle, $accountType, $sourceName) {
                // SEC-2: handle/handle_lc/status are no longer fillable — forceFill so
                // this live signup-path write doesn't silently drop them (a drop here
                // 23502s on Postgres, since handle/handle_lc are NOT NULL).
                $user = new User;
                $user->forceFill([
                    'handle' => $handle['handle'],
                    'handle_lc' => $handle['handle_lc'],
                    // Placeholder identity until the generator writes scraped values;
                    // first_name/display_name are NOT NULL on live Postgres.
                    'display_name' => $sourceName ?: $handle['handle'],
                    'first_name' => $sourceName ?: $handle['handle'],
                    'account_type' => AccountType::tryFrom($accountType) ?? AccountType::Partna,
                    'status' => 'unclaimed',
                    'onboarding_step' => 0,
                ]);
                // auth_user_id stays NULL — that IS the provisional-user model.
                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Handle taken by a concurrent build that committed first — caller's
            // retry loop re-allocates and tries again.
            return null;
        }
    }

    /**
     * Re-serve a live build. A FAILED build resets and re-runs (F3); LIFE-4:
     * so does a build stuck in pending/building past the SLA — a worker crash
     * (OOM/SIGKILL) mid-scrape never reaches GeneratePreAccountSiteJob::failed(),
     * so without this a stuck build only recovers via the hourly
     * builds:reconcile-stuck watchdog flipping it to failed first. SLA default
     * (30min) is deliberately well past the job's 300s timeout and 600s
     * ShouldBeUnique window, so a fresh dispatch here is never dropped by the
     * unique lock nor races a still-legitimately-running job.
     */
    private function reserve(PreAccountBuild $build, ?PartnaStaff $staff = null, ?string $ipHash = null): PreAccountBuild
    {
        $stuckSla = (int) config('partna.pre_account.stuck_build_sla_minutes', 30);
        $isStuck = in_array($build->build_state, [PreAccountBuild::STATE_PENDING, PreAccountBuild::STATE_BUILDING], true)
            && $build->updated_at->lt(now()->subMinutes($stuckSla));

        if ($build->build_state !== PreAccountBuild::STATE_FAILED && ! $isStuck) {
            return $build;
        }

        // F4: a re-serve dispatches the SAME paid Apify scrape a new build would
        // (GeneratePreAccountSiteJob), and this method is reached from the dedupe
        // early-return that sits ABOVE the per-IP cap guarding new builds — so a
        // public caller repeatedly re-serving a failed/stuck build never counted
        // against anything. Meter it with the identical cap: a caller already at
        // their outstanding-unclaimed-build limit may not consume another paid
        // scrape via re-serve either. Staff are exempt, same as new-build creation
        // (requestBuild skips the cap entirely when $staff is set).
        DB::connection('pgsql')->transaction(function () use ($build, $staff, $ipHash) {
            if ($staff === null && $ipHash !== null) {
                DB::connection('pgsql')->select('select pg_advisory_xact_lock(hashtext(?))', ["pre_account_build_ip:{$ipHash}"]);
                $cap = (int) config('partna.pre_account.max_unclaimed_per_ip', 3);
                $outstanding = PreAccountBuild::live()->where('created_ip_hash', $ipHash)->count();
                if ($outstanding >= $cap) {
                    throw new PreAccountBuildException(
                        PreAccountBuildException::IP_BUILD_CAP,
                        'Too many unclaimed builds from this address. Claim one first.'
                    );
                }
            }

            // SEC-4: build_state/failure_code are no longer fillable — forceFill so
            // this re-serve write isn't a silent no-op (a dropped write here would
            // leave the build stuck in 'failed' forever).
            $build->forceFill(['build_state' => PreAccountBuild::STATE_PENDING, 'failure_code' => null])->save();
        });

        GeneratePreAccountSiteJob::dispatch($build->id, $build->source_type, false)->afterCommit();

        return $build;
    }
}
