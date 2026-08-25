<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\UserSite\StaffAttachContactEmailRequest;
use App\Http\Requests\Api\Staff\UserSite\StaffBatchPreAccountBuildRequest;
use App\Http\Requests\Api\Staff\UserSite\StaffCreatePreAccountBuildRequest;
use App\Http\Resources\PreAccountBuildStatusResource;
use App\Http\Resources\StaffPreAccountBuildResource;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\ClaimNotifier;
use App\Services\PreAccount\ClaimTokenIssuer;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class StaffPreAccountBuildController extends ApiController
{
    public function __construct(
        private readonly PreAccountBuildService $builds,
        private readonly ClaimTokenIssuer $tokens,
    ) {}

    // POST /api/staff/builds — the ManyChat/marketing surface. Builds publish by
    // default (the site IS the pitch); the public endpoint never publishes pre-claim.
    public function store(StaffCreatePreAccountBuildRequest $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffCreate', PreAccountBuild::class);

        $data = $request->validated();

        try {
            $result = $this->builds->requestBuild(
                accountType: $data['account_type'],
                sourceType: $data['source_type'],
                rawSourceRef: $data['source_ref'],
                sourceName: $data['source_name'] ?? null,
                ipHash: null,
                staff: $staff,
                publish: (bool) ($data['publish'] ?? true),
                expiresDays: isset($data['expires_days']) ? (int) $data['expires_days'] : null,
                contactEmail: $data['contact_email'] ?? null,
                autoInvite: (bool) ($data['auto_invite'] ?? true),
            );
        } catch (PreAccountBuildException $e) {
            // The staff surface has no waitlist/IP-cap paths (requestBuild skips
            // the cap entirely when $staff is set), so SOURCE_PAIRING_INVALID
            // and SOURCE_REF_INVALID are the only bad-input codes here — 422 is
            // correct for those. CONTACT_EMAIL_CONFLICT is not bad input: it's
            // an existing row disagreeing with this request, which is a 409.
            $status = $e->errorCode === PreAccountBuildException::CONTACT_EMAIL_CONFLICT ? 409 : 422;

            return $this->error($e->getMessage(), $status, [], ['code' => $e->errorCode]);
        }

        $result['build']->loadMissing('user.site');

        return $this->success(
            (new PreAccountBuildStatusResource($result['build']))->resolve(),
            $result['reused'] ? 200 : 202,
        );
    }

    // PATCH /api/staff/builds/{build}/contact-email — attach or correct the
    // invited address.
    //
    // PREREQUISITE for the 2026-08-24 invite-gate, not a convenience: an
    // outreach build with no contact_email is now unclaimable
    // (ClaimSiteService -> CLAIM_NOT_INVITED). Without this endpoint every such
    // build would be permanently stranded, because the create path never
    // updates contact_email on an existing row (PreAccountBuildService returns
    // on the dedupe branch before the create block writes it).
    //
    // Deliberately allowed on an ALREADY-INVITED build: the common reason to
    // reach for this is that the first address was wrong. Re-pointing clears
    // invited_at so the invite can genuinely be re-sent — otherwise `invite`
    // would answer ALREADY_INVITED forever and the new address would never
    // hear from us.
    public function attachContactEmail(
        StaffAttachContactEmailRequest $request,
        PreAccountBuild $build
    ): JsonResponse {
        $staff = request()->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffAttachContactEmail', PreAccountBuild::class);

        if ($build->claimed_at !== null) {
            return $this->error(
                'This build has already been claimed — its owner controls the address now.',
                409,
                [],
                ['code' => 'ALREADY_CLAIMED']
            );
        }

        $email = mb_strtolower(trim($request->validated()['contact_email']));
        $changed = mb_strtolower(trim((string) $build->contact_email)) !== $email;

        // contact_email / invited_at are not fillable — this is a trusted
        // staff-only lifecycle write, so forceFill rather than widening $fillable.
        $build->forceFill([
            'contact_email' => $email,
            'invited_at' => $changed ? null : $build->invited_at,
        ])->save();

        Log::info('pre_account.contact_email.attached', [
            'build_id' => $build->id,
            'staff_id' => $staff?->id,
            'changed' => $changed,
            're_invitable' => $changed && $build->invited_at === null,
        ]);

        return $this->success((new StaffPreAccountBuildResource($build->fresh('user.site')))->resolve());
    }

    // POST /api/staff/builds/{build}/invite — manual send for auto_invite=false
    // builds staff wanted to eyeball first. Reuses ClaimNotifier (idempotent).
    public function invite(PreAccountBuild $build): JsonResponse
    {
        $staff = request()->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffCreate', PreAccountBuild::class);

        // user_id is a NOT NULL 1:1 FK and a site is created together with the
        // build, so user->site is non-null here; is_published is NOT NULL bool.
        $published = $build->user->site->is_published;
        if ($build->build_state !== PreAccountBuild::STATE_READY || ! $published) {
            return $this->error('Build is not ready to invite.', 409, [], ['code' => 'BUILD_NOT_READY']);
        }
        if ($build->contact_email === null || trim($build->contact_email) === '') {
            return $this->error('Build has no contact email.', 422, [], ['code' => 'NO_CONTACT_EMAIL']);
        }
        if ($build->invited_at !== null) {
            return $this->error('Build already invited.', 409, [], ['code' => 'ALREADY_INVITED']);
        }

        app(ClaimNotifier::class)->notify($build);

        // Re-read from DB (not loadMissing, which only fills relations) so the
        // response reflects the committed invited_at even on the race-loser
        // path, where ClaimNotifier synced the winner's model, not this one.
        $build = $build->fresh('user.site');

        // Staff variant so the caller can confirm invited_at was stamped —
        // the public resource deliberately hides outreach state (spec §8).
        return $this->success((new StaffPreAccountBuildResource($build))->resolve());
    }

    // POST /api/staff/builds/{build}/claim-token — mint a fresh claim link.
    //
    // For "the lead lost the DM" and for rotation after a suspected leak.
    // Deliberately NOT on the ManyChat webhook (spec §5.4): re-issuing against
    // an EXISTING build is exactly the capability a leaked webhook secret must
    // not confer, so it lives behind staff auth + AAL2 instead.
    public function reissueClaimToken(PreAccountBuild $build): JsonResponse
    {
        $staff = request()->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffCreate', PreAccountBuild::class);

        $build->loadMissing('user.site');
        $subdomain = $build->user?->site?->subdomain;
        if ($subdomain === null) {
            return $this->error('Build has no site.', 409, [], ['code' => 'BUILD_NOT_READY']);
        }

        if ($build->claimed_at !== null) {
            return $this->error('This build has already been claimed.', 409, [], ['code' => 'ALREADY_CLAIMED']);
        }

        $token = $this->tokens->issue($build);

        Log::info('pre_account.claim_token.reissued', [
            'build_id' => $build->id,
            'staff_id' => $staff?->id,
        ]);

        return $this->success([
            'build_id' => $build->id,
            'claim_url' => rtrim((string) config('app.frontend_url'), '/')
                .'/claim/'.$subdomain
                .'?t='.$token,
        ]);
    }

    // POST /api/staff/builds/batch — CSV loop over requestBuild. Per-row failures
    // are collected (row index + code), never fatal. Row cap logged if hit.
    public function batch(StaffBatchPreAccountBuildRequest $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffCreate', PreAccountBuild::class);

        $rows = $this->parseCsv($request->file('file'));

        $cap = 500;
        $truncated = false;
        if (count($rows) > $cap) {
            $rows = array_slice($rows, 0, $cap);
            $truncated = true;
            Log::warning('staff builds batch truncated to cap', ['cap' => $cap]);
        }

        $built = 0;
        $reused = 0;
        $failed = [];

        foreach ($rows as $i => $row) {
            $email = $row['contact_email'] ?? null;
            if ($email !== null && $email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed[] = ['row' => $i + 1, 'code' => 'INVALID_EMAIL', 'message' => "Invalid email: {$email}"];

                continue;
            }

            try {
                $result = $this->builds->requestBuild(
                    accountType: (string) ($row['account_type'] ?? ''),
                    sourceType: (string) ($row['source_type'] ?? ''),
                    rawSourceRef: (string) ($row['source_ref'] ?? ''),
                    sourceName: ($row['source_name'] ?? null) ?: null,
                    ipHash: null,
                    staff: $staff,
                    publish: true,
                    contactEmail: ($email ?: null),
                    autoInvite: filter_var($row['auto_invite'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
                );
                $result['reused'] ? $reused++ : $built++;
            } catch (PreAccountBuildException $e) {
                $failed[] = ['row' => $i + 1, 'code' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }

        return $this->success([
            'built' => $built,
            'reused' => $reused,
            'failed' => $failed,
            'truncated' => $truncated,
        ]);
    }

    /**
     * Parse an uploaded CSV into assoc rows keyed by the header line.
     *
     * @return array<int, array<string, string|null>>
     */
    private function parseCsv(UploadedFile $file): array
    {
        $content = (string) file_get_contents($file->getRealPath());
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
        if (count($lines) < 2) {
            return [];
        }

        $header = array_map('trim', str_getcsv((string) array_shift($lines)));
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line);
            $row = [];
            foreach ($header as $idx => $key) {
                $row[$key] = isset($values[$idx]) ? trim((string) $values[$idx]) : null;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
