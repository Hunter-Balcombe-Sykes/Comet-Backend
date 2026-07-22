<?php

namespace App\Services\PreAccount;

use App\Enums\AccountType;
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
            return ['build' => $this->reserve($existing), 'reused' => true];
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
                $this->siteProvisioning->createSiteWithRetry(
                    $user->id,
                    $this->siteProvisioning->subdomainBaseFromHandle($seed),
                    published: false,
                );

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
                return ['build' => $this->reserve($existing), 'reused' => true];
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
    private function reserve(PreAccountBuild $build): PreAccountBuild
    {
        $stuckSla = (int) config('partna.pre_account.stuck_build_sla_minutes', 30);
        $isStuck = in_array($build->build_state, [PreAccountBuild::STATE_PENDING, PreAccountBuild::STATE_BUILDING], true)
            && $build->updated_at->lt(now()->subMinutes($stuckSla));

        if ($build->build_state === PreAccountBuild::STATE_FAILED || $isStuck) {
            // SEC-4: build_state/failure_code are no longer fillable — forceFill so
            // this re-serve write isn't a silent no-op (a dropped write here would
            // leave the build stuck in 'failed' forever).
            $build->forceFill(['build_state' => PreAccountBuild::STATE_PENDING, 'failure_code' => null])->save();
            GeneratePreAccountSiteJob::dispatch($build->id, $build->source_type, false)->afterCommit();
        }

        return $build;
    }
}
