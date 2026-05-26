<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Resources\StaffEmailSubscriptionResource;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Staff inspector for a brand's marketing-list subscribers (#GDPR-1).
// Mirrors the brand-side UserEmailSubscriptionController so support
// can answer Article 15/20 requests routed via the platform inbox.
class StaffEmailSubscriberController extends ApiController
{
    use HandlesSearchQueries;
    use NormalizesPerPage;
    use ReturnsPaginatedResponse;

    /**
     * GET /staff/professionals/{professional}/email-subscribers
     * Any-staff. Same query + paging shape as the brand sees on /api/email-subscribers.
     */
    public function index(Request $request, User $professional): JsonResponse
    {
        $listKey = $request->query('list_key', 'marketing');
        $listKey = is_string($listKey) ? trim($listKey) : 'marketing';
        if ($listKey === '') {
            $listKey = 'marketing';
        }

        $status = $request->query('status', 'subscribed'); // subscribed|unsubscribed|all
        $status = is_string($status) ? trim($status) : 'subscribed';

        $perPage = $this->normalizePerPage($request, 50, 200);
        $search = $request->query('search');
        $searchLike = $this->prepareSearchLike($request, 'search');

        $query = EmailSubscription::query()
            ->where('user_id', $professional->id)
            ->where('list_key', $listKey)
            ->orderByDesc('subscribed_at')
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($searchLike) {
            $query->where(function ($q) use ($searchLike) {
                $q->where('email', 'ilike', $searchLike)
                    ->orWhere('full_name', 'ilike', $searchLike);
            });
        }

        $page = $query->paginate($perPage)->appends($request->query());
        // Audience-specific Resource so future staff-only fields (admin_notes,
        // suppression source, etc.) land cleanly without leaking to brands (#API-3).
        $page->through(fn (EmailSubscription $sub) => StaffEmailSubscriptionResource::make($sub)->resolve());

        return $this->success($this->paginatedResponse($page, 'subscriptions', [
            'filters' => [
                'list_key' => $listKey,
                'status' => $status,
                'search' => is_string($search) ? trim($search) : null,
            ],
        ]));
    }

    /**
     * GET /staff/professionals/{professional}/email-subscribers/export
     * Any-staff. CSV stream matching the brand-side export verbatim.
     */
    public function export(Request $request, User $professional): StreamedResponse
    {
        $listKey = $request->query('list_key', 'marketing');
        $listKey = is_string($listKey) ? trim($listKey) : 'marketing';
        if ($listKey === '') {
            $listKey = 'marketing';
        }

        $status = $request->query('status', 'subscribed');
        $status = is_string($status) ? trim($status) : 'subscribed';

        $query = EmailSubscription::query()
            ->where('user_id', $professional->id)
            ->where('list_key', $listKey)
            ->orderBy('email');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $filename = "email-subscribers-{$listKey}-{$status}.csv";

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'full_name', 'status', 'subscribed_at', 'unsubscribed_at']);

            foreach ($query->cursor() as $row) {
                fputcsv($out, [
                    $row->email,
                    $row->full_name,
                    $row->status,
                    optional($row->subscribed_at)->toISOString(),
                    optional($row->unsubscribed_at)->toISOString(),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
