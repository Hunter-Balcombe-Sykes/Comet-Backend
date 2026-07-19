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

        // Signup-path abuse cap: outstanding unclaimed builds per IP (F2).
        if ($staff === null && $ipHash !== null) {
            $cap = (int) config('partna.pre_account.max_unclaimed_per_ip', 3);
            $outstanding = PreAccountBuild::live()->where('created_ip_hash', $ipHash)->count();
            if ($outstanding >= $cap) {
                throw new PreAccountBuildException(
                    PreAccountBuildException::IP_BUILD_CAP,
                    'Too many unclaimed builds from this address. Claim one first.'
                );
            }
        }

        $expiresAt = now()->addDays($expiresDays ?? (int) config('partna.pre_account.expiry_days', 30));

        try {
            $build = DB::connection('pgsql')->transaction(function () use (
                $accountType, $sourceType, $ref, $refLc, $sourceName, $ipHash, $staff, $expiresAt
            ) {
                $seed = $this->generators->for($sourceType)->handleSeed($ref, $sourceName);
                $handle = $this->handles->allocate($seed);

                $user = new User([
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
                    'built_via' => $staff ? PreAccountBuild::VIA_STAFF : PreAccountBuild::VIA_SIGNUP,
                    // Set explicitly (not left to the DB DEFAULT 'pending') so the
                    // in-memory model matches the row immediately after save() —
                    // Eloquent doesn't re-fetch DB-computed column defaults post-insert.
                    'build_state' => PreAccountBuild::STATE_PENDING,
                    'created_ip_hash' => $staff ? null : $ipHash,
                    'expires_at' => $expiresAt,
                ]);
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

        GeneratePreAccountSiteJob::dispatch($build->id, $publish)->afterCommit();

        return ['build' => $build, 'reused' => false];
    }

    private function findLive(string $sourceType, string $refLc): ?PreAccountBuild
    {
        return PreAccountBuild::live()
            ->where('source_type', $sourceType)
            ->where('source_ref_lc', $refLc)
            ->first();
    }

    /** Re-serve a live build; a FAILED one resets and re-runs (F3). */
    private function reserve(PreAccountBuild $build): PreAccountBuild
    {
        if ($build->build_state === PreAccountBuild::STATE_FAILED) {
            $build->update(['build_state' => PreAccountBuild::STATE_PENDING, 'failure_code' => null]);
            GeneratePreAccountSiteJob::dispatch($build->id, false)->afterCommit();
        }

        return $build;
    }
}
