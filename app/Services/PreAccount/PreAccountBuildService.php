<?php

namespace App\Services\PreAccount;

use App\Enums\AccountType;
use App\Exceptions\Site\SubdomainUnavailableException;
use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Services\Profile\NameShapeGate;
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

        // Which lane is this request? Signup builds NEVER dedupe (owner,
        // 2026-09-03): the same source may start any number of independent
        // sign-ups, each with its own build, user, site and claim token —
        // no re-serve, no redirect to an existing build or account. The
        // OUTREACH lanes (staff / early_access) keep one live build per
        // source: CSV re-import idempotency, ManyChat webhook retries and
        // the mint-only-on-NEW-build token rule all depend on it.
        $via = $builtVia ?? ($staff !== null ? PreAccountBuild::VIA_STAFF : PreAccountBuild::VIA_SIGNUP);

        // Outreach dedupe. Failed live builds retry (F3). Checked BEFORE the
        // account_type/source_type pairing so re-serving an existing build
        // never re-validates the pairing — the re-served build keeps its
        // ORIGINAL account_type regardless of what this caller passed
        // (spec §4.1).
        if ($via !== PreAccountBuild::VIA_SIGNUP && ($existing = $this->findLive($sourceType, $refLc))) {
            $existing = $this->reconcileContactEmail($existing, $staff, $contactEmail);

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
                $accountType, $sourceType, $ref, $refLc, $sourceName, $ipHash, $staff, $expiresAt, $contactEmail, $via, $autoInvite
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

                // Item 1a (2026-09-01): NO identity is allocated here any more.
                // The scrape verifies the source first (in the job), and only a
                // verified source materializes a user, handle, site and KV
                // route — so a typo'd handle can never squat a name again (the
                // bydannydixon class), and the handle can be seeded from the
                // CLEANED display name the scrape produces (Item 1c). The two
                // request-time facts identity creation needs ride the row.
                $build = new PreAccountBuild([
                    'source_type' => $sourceType,
                    'source_ref' => $ref,
                    'source_ref_lc' => $refLc,
                    'built_via' => $via,
                    'created_ip_hash' => $staff ? null : $ipHash,
                    'expires_at' => $expiresAt,
                    'contact_email' => $contactEmail,
                    'auto_invite' => $autoInvite,
                    'account_type' => $accountType,
                    'source_name' => $sourceName,
                ]);
                // SEC-4: build_state is no longer fillable. Set explicitly (not left to
                // the DB DEFAULT 'pending') so the in-memory model matches the row
                // immediately after save() — Eloquent doesn't re-fetch DB-computed
                // column defaults post-insert.
                $build->build_state = PreAccountBuild::STATE_PENDING;
                if ($staff) {
                    $build->builtByStaff()->associate($staff);
                }
                $build->save();

                return $build;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the race on pre_account_builds_live_source_unique — the other
            // request's build is the canonical one; re-serve it (spec §4.1).
            //
            // SEM-4: this branch reaches the SAME outcome as the dedupe branch
            // above and must reconcile contact_email the same way. Losing the
            // insert race is a timing accident, not a different request: before
            // this call, a staff CSV row that happened to lose it had its address
            // dropped with no exception, no log, and no field in
            // PreAccountBuildStatusResource to notice it by — so staff believed
            // they had gated a site that was in fact still first-come claimable.
            $existing = $this->findLive($sourceType, $refLc);
            if ($existing) {
                $existing = $this->reconcileContactEmail($existing, $staff, $contactEmail);

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

    /**
     * Reconcile a staff-supplied contact_email against an EXISTING live build,
     * returning the (possibly updated) row.
     *
     * A staff request carrying an address for an existing live build must not
     * have it silently dropped. Both re-serve paths sit ABOVE/AROUND the create
     * block that writes contact_email, so before 2026-08-24 a CSV row naming an
     * already-built source left staff believing they had gated a site that was
     * in fact wide open — and after the invite-gate landed, believing they had
     * UNBLOCKED one that was still stranded.
     *
     * Staff only. A public caller must never be able to write contact_email onto
     * an existing row: that is the field deciding who may claim it, so an
     * anonymous write would be a takeover primitive.
     *
     * ONE implementation deliberately shared by the dedupe branch and the
     * unique-violation race-loser branch (SEM-4) — the two differ only in how
     * they discovered the existing row.
     *
     * @throws PreAccountBuildException CONTACT_EMAIL_CONFLICT
     */
    private function reconcileContactEmail(PreAccountBuild $existing, ?PartnaStaff $staff, ?string $contactEmail): PreAccountBuild
    {
        if ($staff === null || $contactEmail === null || trim($contactEmail) === '') {
            return $existing;
        }

        $incoming = mb_strtolower(trim($contactEmail));

        // Locked read-modify-write: without lockForUpdate, two concurrent
        // requests for the same source (e.g. two CSV rows, or a retry racing a
        // CSV import) can both read an empty contact_email and both write, one
        // silently overwriting the other with no conflict ever detected.
        return DB::connection('pgsql')->transaction(function () use ($existing, $incoming) {
            /** @var PreAccountBuild $locked */
            $locked = PreAccountBuild::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
            $current = mb_strtolower(trim((string) $locked->contact_email));

            if ($current === '') {
                // Self-serve builds have contact_email NULL by construction — an
                // empty current value on a build that is NOT outreach means a
                // real person is mid-signup on their own source. A staff
                // CSV/import naming the same source must not attach its address
                // here: that would silently hand the build's invite-gate to
                // staff's address and lock the actual signer-upper out.
                if ($locked->isOutreach()) {
                    // contact_email is not fillable — trusted staff lifecycle write.
                    $locked->forceFill(['contact_email' => $incoming])->save();
                }
            } elseif ($current !== $incoming) {
                // Loudly, not silently: two different addresses claiming the same
                // business is a human question, and picking either one
                // automatically can hand the site to the wrong person.
                throw new PreAccountBuildException(
                    PreAccountBuildException::CONTACT_EMAIL_CONFLICT,
                    "A build for this source already exists with a different contact email. Use PATCH /staff/builds/{$locked->id}/contact-email to change it deliberately."
                );
            }

            return $locked;
        });
    }

    private function findLive(string $sourceType, string $refLc): ?PreAccountBuild
    {
        // Mirrors pre_account_builds_live_source_unique's predicate: signup
        // builds are invisible to the outreach dedupe (many can coexist per
        // source), so a staff/webhook build for a source someone also
        // self-signed-up with creates its OWN row — a staff contact_email
        // can never land on, and a ManyChat token can never bind to, a
        // self-serve build.
        return PreAccountBuild::live()
            ->where('source_type', $sourceType)
            ->where('source_ref_lc', $refLc)
            ->where('built_via', '!=', PreAccountBuild::VIA_SIGNUP)
            ->first();
    }

    /**
     * LIFE-3: allocate + create the provisional user with savepoint-isolated
     * retry. HandleAllocator::allocate() checks core.users for a free handle_lc
     * BEFORE this INSERT — a genuine TOCTOU when two concurrent builds land on
     * the same seed. Each attempt runs in its own nested transaction (mirrors
     * SiteProvisioningService::tryCreateSite() / UserBootstrapService) so a
     * core_users_handle_lc_unique violation only rolls back to the SAVEPOINT,
     * leaving the outer transaction healthy for the retry to re-allocate (now
     * seeing the just-committed colliding row) instead of poisoning it.
     *
     * C2 (2026-09-06): "the outer transaction" used to mean nothing — this
     * method's caller, materializeIdentity(), ran it standalone, so an attempt
     * committed the user row for real the moment it returned. If
     * createSiteForHandle() then threw (a genuine handle/subdomain race), the
     * build was marked failed with user_id still null and the user row
     * survived forever, permanently squatting its handle_lc —
     * PruneExpiredPreAccountBuilds only hard-deletes the BUILD, and a
     * null-user_id failed build reads as having no footprint to clean up.
     * materializeIdentity() now opens the real outer transaction this
     * docblock always assumed existed, so a later createSiteForHandle()
     * failure rolls the just-created user back too — reopening the exact
     * handle-squatting class Item 1a's scrape-first redesign closed, in the
     * one seam where user-create and site-create weren't yet in the same
     * transaction.
     */
    /**
     * Item 1a: materialize the identity AFTER the scrape verified the source.
     * Called by GeneratePreAccountSiteJob with phase one's bundle — the seed
     * is the CLEANED display name when the source yielded one (Item 1c), the
     * generator's legacy seed otherwise, and the untrimmed name rides as the
     * allocator's ladder fallback (Item 1b's multi-location case). Creates
     * user + site and binds the build, exactly what requestBuild() used to do
     * in-request — now that it is earned.
     */
    public function materializeIdentity(PreAccountBuild $build, SourcePrefetch $prefetch): User
    {
        $accountType = (string) ($build->account_type ?: 'partna');
        $sourceName = $prefetch->displayName
            ?? ($build->source_name !== null && trim((string) $build->source_name) !== '' ? (string) $build->source_name : null);
        // Both handleSeed() implementations are pure returns (the IG one hands
        // back the normalized ref, the GB one the listing name), so evaluating
        // it eagerly instead of through ?? costs nothing and buys the username
        // as a value the rule below can read.
        $generatorSeed = $this->generators->for($build->source_type)->handleSeed($build->source_ref, $build->source_name);
        $seed = $prefetch->displayName ?? $generatorSeed;
        $untrimmedSeed = $prefetch->untrimmedName;

        // 2026-09-02, owner-approved: an Instagram username carrying no part of
        // the person's own name is a chosen brand, not noise around the name —
        // themetapunter/"Joe Osborne" keeps its handle where
        // ryanfitzsimonshair/"Ryan Fitzsimons" still trims. The cleaned name
        // becomes the ladder fallback, so a taken brand handle lands on
        // 'joeosborne' rather than 'themetapunter1'. Instagram only: a Google
        // listing has no username to prefer.
        if ($build->source_type === 'instagram'
            && $prefetch->displayName !== null
            && ! NameShapeGate::handleCarriesName($generatorSeed, $prefetch->displayName)) {
            $seed = $generatorSeed;
            $untrimmedSeed = $prefetch->displayName;
        }

        // C2 (2026-09-06): user-create and site-create now share ONE
        // transaction — see the LIFE-3 docblock on createProvisionalUserWithRetry()
        // above for why. A createSiteForHandle() failure rolls the just-created
        // user back too, instead of leaving it committed and permanently
        // squatting its handle_lc under a build stuck at user_id=null.
        $user = DB::connection('pgsql')->transaction(function () use ($build, $seed, $accountType, $sourceName, $untrimmedSeed) {
            $user = $this->createProvisionalUserWithRetry($seed, $accountType, $sourceName, $untrimmedSeed);

            try {
                $this->siteProvisioning->createSiteForHandle(
                    $user->id,
                    (string) $user->handle_lc,
                    published: false,
                );
            } catch (SubdomainUnavailableException $e) {
                // HandleAllocator already required the handle to be free as a
                // subdomain — a refusal means that guarantee broke: alarm, not input.
                report($e);

                throw $e;
            }

            $build->user()->associate($user);
            $build->save();

            return $user;
        });

        // Setup progress (2026-09-02): the first thing the signup feed says.
        // Deliberately AFTER the transaction: the identity is only "found" once
        // it actually committed, and this is a plain informational event row —
        // nothing downstream depends on it being atomic with the create.
        BuildProgress::note(
            (string) $build->id,
            PreAccountBuildEvent::STAGE_IDENTITY,
            PreAccountBuildEvent::STATUS_LANDED,
            $build->source_type === 'google_business'
                ? 'Found your Google listing'
                : 'Found your Instagram',
            [
                'handle' => (string) $user->handle_lc,
                // Sign-up preview (2026-09-02, A.5): the identity scene shows a
                // name and the source's mark before any media lands.
                'displayName' => $user->display_name,
                'sourcePlatform' => (string) $build->source_type,
                // A business opens on its listing with the stars (2026-09-02):
                // the Places details are already on the connection row the
                // generator wrote, so the rating rides the first event instead
                // of waiting ~30s for the enrich.
                ...$this->listingStars($user),
            ],
        );

        return $user;
    }

    /**
     * @return array{rating?: float, reviews?: int}
     */
    private function listingStars(User $user): array
    {
        try {
            $payload = (array) IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('platform', 'google-business')
                ->whereNull('deleted_at')
                ->value('payload');
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        $rating = $payload['rating'] ?? $payload['totalScore'] ?? null;
        $reviews = $payload['reviewCount'] ?? $payload['reviewsCount'] ?? $payload['userRatingCount'] ?? null;
        if (is_numeric($rating)) {
            $out['rating'] = (float) $rating;
        }
        if (is_numeric($reviews)) {
            $out['reviews'] = (int) $reviews;
        }

        return $out;
    }

    private function createProvisionalUserWithRetry(string $seed, string $accountType, ?string $sourceName, ?string $untrimmedSeed = null): User
    {
        for ($i = 0; $i < 5; $i++) {
            $handle = $this->handles->allocate($seed, $untrimmedSeed);
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
