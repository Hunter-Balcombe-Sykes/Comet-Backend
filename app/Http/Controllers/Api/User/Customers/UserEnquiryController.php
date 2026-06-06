<?php

namespace App\Http\Controllers\Api\User\Customers;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Resources\EnquiryDetailResource;
use App\Http\Resources\EnquiryResource;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\Customer;
use App\Services\Notifications\EnquirySpamBlocklist;
use App\Services\Notifications\NotificationListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Dashboard inbox for visitor-submitted enquiries. Read-only list + mark read/unread + soft-delete, scoped to the current professional.
class UserEnquiryController extends ApiController
{
    use ResolveCurrentUser;
    use ReturnsPaginatedResponse;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);

        $query = Enquiry::query()->where('user_id', $pro->id);

        // If a valid status is supplied, filter to that status only.
        // Otherwise default to the inbox view: exclude archived and spam.
        $status = $request->string('status')->toString();
        if ($status !== '' && in_array($status, ['new', 'read', 'replied', 'archived', 'spam'], true)) {
            $query->where('status', $status);
        } else {
            $query->whereNotIn('status', ['archived', 'spam']);
        }

        $page = $query->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        // through() transforms items in place; the paginator's count metadata
        // is untouched so paginatedResponse() still emits the canonical
        // current_page/last_page/total/per_page block (#P2-34).
        $page->through(fn (Enquiry $e) => EnquiryResource::make($e)->resolve());

        return $this->success($this->paginatedResponse($page));
    }

    /**
     * Return the full enquiry detail payload for a single enquiry.
     *
     * Auto-transitions status from 'new' → 'read' and marks the linked
     * notification receipt read so the inbox bell count stays accurate.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        $enquiry = Enquiry::query()
            ->where('user_id', $user->id)
            ->with('customer')
            ->find($id);

        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $this->authorizeForUser($user, 'view', $enquiry);

        // Auto-read transition + notification receipt update.
        // Must happen before setting historyForDetailView — Eloquent would try
        // to persist any dynamic property set on the model before markRead() runs.
        if ($enquiry->status?->value === 'new') {
            $enquiry->markRead();

            if ($enquiry->notification_id) {
                $notification = Notification::find($enquiry->notification_id);
                if ($notification) {
                    app(NotificationListingService::class)
                        ->markRead($notification, (string) $user->id);
                }
            }
        }

        // Last 10 enquiries from the same contact (excludes this one + redacted/deleted).
        // Set after all DB writes to avoid Eloquent treating it as a dirty column.
        $enquiry->historyForDetailView = $enquiry->customer_id
            ? Enquiry::query()
                ->where('user_id', $user->id)
                ->where('customer_id', $enquiry->customer_id)
                ->where('id', '!=', $enquiry->id)
                ->whereNull('redacted_at')
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->limit(10)
                ->get(['id', 'subject', 'created_at', 'status'])
            : collect();

        return $this->success(['data' => (new EnquiryDetailResource($enquiry))->resolve()]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pro = $this->currentUser($request);

        $enquiry = Enquiry::query()
            ->where('user_id', $pro->id)
            ->find($id);

        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $this->authorizeForUser($pro, 'update', $enquiry);

        $request->validate([
            'read' => ['required', 'boolean'],
        ]);

        // Sync both status and read_at together so they never diverge.
        if ($request->boolean('read')) {
            $enquiry->markRead();
        } else {
            $enquiry->update(['status' => 'new', 'read_at' => null]);
        }

        return $this->success([
            'enquiry' => (new EnquiryResource($enquiry->fresh()))->resolve(),
        ]);
    }

    /**
     * Return a count per status for the current professional's enquiries.
     *
     * All five statuses are always present in the response (zero-filled),
     * so the client can render unread badges without null-checking.
     *
     * Response shape: { new: int, read: int, replied: int, archived: int, spam: int }
     */
    public function counts(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // toBase() skips Eloquent model hydration so the status key comes back
        // as a plain string rather than an EnquiryStatus enum instance.
        $rows = Enquiry::query()
            ->where('user_id', $user->id)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->toBase()
            ->pluck('c', 'status');

        return $this->success([
            'new' => (int) ($rows['new'] ?? 0),
            'read' => (int) ($rows['read'] ?? 0),
            'replied' => (int) ($rows['replied'] ?? 0),
            'archived' => (int) ($rows['archived'] ?? 0),
            'spam' => (int) ($rows['spam'] ?? 0),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $pro = $this->currentUser($request);

        $enquiry = Enquiry::query()
            ->where('user_id', $pro->id)
            ->find($id);

        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $enquiry->delete();

        return $this->success(['ok' => true]);
    }

    /**
     * Mark an enquiry as spam, add the sender's email to the blocklist,
     * and soft-delete the Customer if it has no other touchpoints.
     *
     * All side-effects run inside a transaction with a row-level lock on
     * the Customer to prevent concurrent spam actions from double-deleting.
     */
    public function markSpam(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $enquiry = Enquiry::query()->where('user_id', $user->id)->find($id);
        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $this->authorizeForUser($user, 'update', $enquiry);

        DB::transaction(function () use ($enquiry, $user) {
            $enquiry->markSpam();

            if ($enquiry->customer_id) {
                $customer = Customer::whereKey($enquiry->customer_id)
                    ->lockForUpdate()
                    ->first();

                if ($customer && $customer->source === 'enquiry') {
                    $hasOtherEnquiries = Enquiry::query()
                        ->where('customer_id', $customer->id)
                        ->where('id', '!=', $enquiry->id)
                        ->exists();

                    $hasSubscription = EmailSubscription::query()
                        ->where('user_id', (string) $user->id)
                        ->whereRaw('lower(email) = ?', [strtolower((string) $customer->email)])
                        ->exists();

                    // Only remove the customer record when the spammer has no
                    // other known presence — no other enquiries, no email subscription,
                    // and no external POS/booking ID tying them to real customer data.
                    if (! $hasOtherEnquiries && ! $hasSubscription && empty($customer->external_id)) {
                        $customer->delete();
                    }
                }
            }

            app(EnquirySpamBlocklist::class)
                ->add((string) $user->id, (string) $enquiry->email);
        });

        return $this->success(['enquiry' => (new EnquiryResource($enquiry->fresh()))->resolve()]);
    }

    // Status transition endpoints — idempotent POST actions that move an enquiry
    // through its lifecycle. Each delegates to the shared transition() helper
    // which handles ownership check, policy gate, and resource serialisation.

    public function markRead(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, fn (Enquiry $e) => $e->markRead());
    }

    public function markReplied(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, fn (Enquiry $e) => $e->markReplied());
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, fn (Enquiry $e) => $e->archive());
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, fn (Enquiry $e) => $e->restoreToNew());
    }

    /**
     * Shared transition helper: resolve ownership, gate, apply, return fresh resource.
     *
     * Returns 404 when the enquiry doesn't exist or belongs to another user
     * (consistent with the 403-vs-404 doctrine — existence must not be revealed).
     */
    private function transition(Request $request, string $id, \Closure $apply): JsonResponse
    {
        $user = $this->currentUser($request);
        $enquiry = Enquiry::query()->where('user_id', $user->id)->find($id);
        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }

        $this->authorizeForUser($user, 'update', $enquiry);
        $apply($enquiry);

        return $this->success(['enquiry' => (new EnquiryResource($enquiry->fresh()))->resolve()]);
    }
}
