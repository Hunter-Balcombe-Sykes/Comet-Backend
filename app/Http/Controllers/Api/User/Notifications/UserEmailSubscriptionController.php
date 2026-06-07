<?php

namespace App\Http\Controllers\Api\User\Notifications;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HandlesSearchQueries;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Resources\UserEmailSubscriptionResource;
use App\Models\Core\Notifications\EmailSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

// V2: Lists and exports email subscribers to a professional's marketing lists.
class UserEmailSubscriptionController extends ApiController
{
    use HandlesSearchQueries;
    use NormalizesPerPage;
    use ResolveCurrentUser;
    use ReturnsPaginatedResponse;

    // GET /api/email-subscribers?list_key=marketing&status=subscribed&search=...
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);

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
            ->where('user_id', $pro->id)
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
        // Route each row through the audience-specific Resource so the field
        // set is an explicit allowlist (#API-3). $hidden on the model still
        // strips token/IP/UA defensively, but the Resource is the contract.
        $page->through(fn (EmailSubscription $sub) => UserEmailSubscriptionResource::make($sub)->resolve());

        return $this->success($this->paginatedResponse($page, 'subscriptions', [
            'filters' => [
                'list_key' => $listKey,
                'status' => $status,
                'search' => is_string($search) ? trim($search) : null,
            ],
        ]));
    }

    // GET /api/email-subscribers/export?list_key=marketing&status=subscribed
    public function export(Request $request): StreamedResponse
    {
        $pro = $this->currentUser($request);

        $listKey = $request->query('list_key', 'marketing');
        $listKey = is_string($listKey) ? trim($listKey) : 'marketing';
        if ($listKey === '') {
            $listKey = 'marketing';
        }

        $status = $request->query('status', 'subscribed');
        $status = is_string($status) ? trim($status) : 'subscribed';

        $query = EmailSubscription::query()
            ->where('user_id', $pro->id)
            ->where('list_key', $listKey)
            ->orderBy('email');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $filename = "email-subscribers-{$listKey}-{$status}.csv";

        // P2-56: cap the stream so one export can't hold a PHP-FPM worker
        // unbounded. Truncation is detectable before streaming (one row past the
        // cap) so the response can advertise it in a header.
        $cap = (int) config('partna.export.max_rows', 50_000);
        $truncated = (clone $query)->offset($cap)->limit(1)->exists();
        $query->limit($cap);

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
        }, $filename, array_filter([
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Truncated' => $truncated ? '1' : null,
        ]));
    }
}
