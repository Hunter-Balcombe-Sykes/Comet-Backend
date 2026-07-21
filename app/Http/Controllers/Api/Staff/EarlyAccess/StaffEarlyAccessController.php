<?php

namespace App\Http\Controllers\Api\Staff\EarlyAccess;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Requests\Api\Staff\EarlyAccess\StaffEarlyAccessInviteRequest;
use App\Http\Requests\Api\Staff\EarlyAccess\StaffEarlyAccessStoreRequest;
use App\Http\Requests\Api\Staff\EarlyAccess\StaffEarlyAccessUpdateRequest;
use App\Http\Resources\Staff\EarlyAccessSignupResource;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
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
}
