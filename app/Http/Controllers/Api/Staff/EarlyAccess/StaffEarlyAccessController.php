<?php

namespace App\Http\Controllers\Api\Staff\EarlyAccess;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Staff\EarlyAccess\StaffEarlyAccessApproveBulkRequest;
use App\Http\Requests\Api\Staff\EarlyAccess\StaffEarlyAccessInviteRequest;
use App\Http\Requests\Api\Staff\EarlyAccess\StaffEarlyAccessStoreRequest;
use App\Http\Requests\Api\Staff\EarlyAccess\StaffEarlyAccessUpdateRequest;
use App\Http\Resources\Staff\EarlyAccessSignupResource;
use App\Jobs\PreAccount\ApproveEarlyAccessBuildJob;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\User\PreAccountBuild;
use App\Services\EarlyAccess\EarlyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// OV-A: staff management of the early-access list — browse, manual add, edit,
// delete, and invite (bulk ids and/or manual entries). Invite emails ride the
// OTP template family; tokens are minted here and stored sha256.
class StaffEarlyAccessController extends ApiController
{
    use HandlesSearchQueries;
    use NormalizesPerPage;
    use ReturnsPaginatedResponse;

    public function __construct(private readonly EarlyAccessService $service) {}

    /** GET /staff/early-access?status=&q=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', EarlyAccessSignup::class);

        $query = EarlyAccessSignup::query()->orderByDesc('created_at');

        $status = $request->query('status');
        if (is_string($status) && in_array($status, ['waitlist', 'invited', 'signed_up'], true)) {
            $query->where('status', $status);
        }

        if ($searchLike = $this->prepareSearchLike($request, 'q')) {
            $query->where(function ($qq) use ($searchLike): void {
                $qq->whereRaw('email_lc LIKE ?', [mb_strtolower($searchLike)])
                    ->orWhereRaw('workplace_or_industry ILIKE ?', [$searchLike]);
            });
        }

        $page = $query->paginate($this->normalizePerPage($request, (int) config('partna.staff.pagination.per_page', 25), (int) config('partna.staff.pagination.per_page_max', 100)));
        $page->through(fn (EarlyAccessSignup $signup) => EarlyAccessSignupResource::make($signup)->resolve());

        return $this->success($this->paginatedResponse($page, 'signups'));
    }

    /** POST /staff/early-access — manual add (no invite yet). */
    public function store(StaffEarlyAccessStoreRequest $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', EarlyAccessSignup::class);

        $data = $request->validated();
        $emailLc = mb_strtolower(trim($data['email']));

        $existing = EarlyAccessSignup::query()->where('email_lc', $emailLc)->first();
        if ($existing !== null) {
            return $this->error('This email is already on the early access list.', 409, [
                'signup' => EarlyAccessSignupResource::make($existing)->resolve(),
            ]);
        }

        $signup = EarlyAccessSignup::query()->create([
            'email' => $emailLc,
            'email_lc' => $emailLc,
            'type' => $data['type'],
            'workplace_or_industry' => $data['workplace_or_industry'] ?? null,
            'platforms' => array_values($data['platforms'] ?? []),
            'source' => 'manual',
            'source_type' => $data['source_type'] ?? null,
            'source_ref' => $data['source_ref'] ?? null,
        ]);
        // status removed from $fillable (S4 Tier 2b) — create() would silently
        // drop it. DB DEFAULT already matches ('waitlist'), but set it directly
        // so the in-memory model (returned in the response below) is correct
        // without a round-trip refresh.
        $signup->status = EarlyAccessSignup::STATUS_WAITLIST;

        return $this->success(['signup' => EarlyAccessSignupResource::make($signup)->resolve()], 201);
    }

    /** PATCH /staff/early-access/{signup} */
    public function update(StaffEarlyAccessUpdateRequest $request, EarlyAccessSignup $signup): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $signup);

        $signup->fill($request->validated())->save();

        return $this->success(['signup' => EarlyAccessSignupResource::make($signup->fresh())->resolve()]);
    }

    /** DELETE /staff/early-access/{signup} */
    public function destroy(Request $request, EarlyAccessSignup $signup): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $signup);

        $signup->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /staff/early-access/invite — send invites for existing rows (ids[])
     * and/or manual entries (entries[]). Manual entries upsert their signup row
     * first (source=manual, invite_meta = integrations/architecture prefills).
     * Already-signed-up rows are skipped and reported.
     */
    public function invite(StaffEarlyAccessInviteRequest $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', EarlyAccessSignup::class);

        $data = $request->validated();
        $invited = [];
        $skipped = [];

        foreach ($data['ids'] ?? [] as $id) {
            $signup = EarlyAccessSignup::query()->find($id);
            if ($signup === null) {
                continue; // exists-rule raced a delete — treat as skipped
            }
            $this->service->invite($signup, $staff?->id) !== null
                ? $invited[] = $signup->id
                : $skipped[] = $signup->id;
        }

        foreach ($data['entries'] ?? [] as $entry) {
            $emailLc = mb_strtolower(trim($entry['email']));

            $signup = EarlyAccessSignup::query()->firstOrNew(['email_lc' => $emailLc]);
            $signup->fill([
                'email' => $emailLc,
                'type' => $entry['type'],
                'workplace_or_industry' => $entry['workplace_or_industry'] ?? $signup->workplace_or_industry,
                'platforms' => array_values($entry['platforms'] ?? ($signup->platforms ?? [])),
            ]);
            if (! $signup->exists) {
                $signup->source = 'manual';
                $signup->status = EarlyAccessSignup::STATUS_WAITLIST;
            }

            // Prefill hints for the invited account (consumed post-OTP by signup).
            $meta = array_filter([
                'integrations' => $entry['integrations'] ?? null,
                'architecture' => $entry['architecture'] ?? null,
            ]);
            if ($meta !== []) {
                $signup->invite_meta = $meta;
            }
            $signup->save();

            $this->service->invite($signup, $staff?->id) !== null
                ? $invited[] = $signup->id
                : $skipped[] = $signup->id;
        }

        return $this->success([
            'invited_count' => count($invited),
            'invited_ids' => $invited,
            'skipped_ids' => $skipped, // already signed up
        ]);
    }

    // POST /api/staff/early-access/{signup}/approve — allow this lead to claim.
    public function approve(EarlyAccessSignup $signup): JsonResponse
    {
        $staff = request()->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', $signup);

        ApproveEarlyAccessBuildJob::dispatch($signup->id, $this->buildSourceType($signup));

        return $this->success(['ok' => true], 202);
    }

    // POST /api/staff/early-access/approve-bulk — {ids?:[], all_waitlisted?:bool}.
    public function approveBulk(StaffEarlyAccessApproveBulkRequest $request): JsonResponse
    {
        $staff = request()->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManage', EarlyAccessSignup::class);

        $data = $request->validated();
        $dispatched = [];
        $skipped = [];

        if (! empty($data['ids'])) {
            // Explicit selection: reconcile it honestly. A selected row with no
            // linked build (user_id null — a manual add, or a build that
            // collided/failed) has nothing to approve. Report it as skipped
            // instead of silently dropping it from the count, so the dashboard
            // can say exactly which rows were left out. Mirrors invite()'s
            // invited_ids/skipped_ids split.
            $rows = EarlyAccessSignup::query()
                ->whereIn('id', $data['ids'])
                ->get(['id', 'user_id'])
                ->keyBy('id');

            // Dedupe the selection so a double-clicked / retried request dispatches
            // (and reports) each row once — the previous whereIn() deduped implicitly.
            foreach (array_unique($data['ids']) as $id) {
                $row = $rows->get($id);
                if ($row === null || $row->user_id === null) {
                    $skipped[] = $id; // missing (raced a delete) or no build to approve

                    continue;
                }
                ApproveEarlyAccessBuildJob::dispatch($row->id, $this->buildSourceType($row));
                $dispatched[] = $row->id;
            }
        } elseif (! empty($data['all_waitlisted'])) {
            // No explicit selection to reconcile against — dispatch every
            // build-linked waitlisted row; unbuilt waitlist rows are simply out
            // of scope here, not a per-row "skip".
            EarlyAccessSignup::query()
                ->whereNotNull('user_id')
                ->where('status', EarlyAccessSignup::STATUS_WAITLIST)
                ->lazyById()
                ->each(function (EarlyAccessSignup $s) use (&$dispatched) {
                    ApproveEarlyAccessBuildJob::dispatch($s->id, $this->buildSourceType($s));
                    $dispatched[] = $s->id;
                });
        } else {
            return $this->error('Provide ids[] or all_waitlisted.', 422);
        }

        return $this->success([
            'dispatched' => count($dispatched), // kept: existing callers read the count
            'dispatched_ids' => $dispatched,
            'skipped_ids' => $skipped, // no linked build to approve
        ], 202);
    }

    // The build's source_type is authoritative for the scrape rate limiter (the
    // signup column is nullable). A user has one build source per account_type, so
    // this is stable. Defaults to 'instagram' when no build resolves — conservative
    // (throttles the paid-Apify red path), and the job early-returns with no build
    // anyway. Keyed at dispatch so the limiter middleware has it before handle().
    private function buildSourceType(EarlyAccessSignup $signup): string
    {
        return PreAccountBuild::query()
            ->where('user_id', $signup->user_id)
            ->value('source_type') ?? 'instagram';
    }
}
