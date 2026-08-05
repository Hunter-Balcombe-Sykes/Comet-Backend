<?php

namespace App\Http\Controllers\Api\User\Customers;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Http\Resources\EnquiryResource;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\Customer;
use App\Services\Notifications\EnquirySpamBlocklist;
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
            ->paginate((int) $request->integer('per_page', config('partna.limits.pagination.enquiries_per_page', 20)));

        // through() transforms items in place; the paginator's count metadata
        // is untouched so paginatedResponse() still emits the canonical
        // current_page/last_page/total/per_page block (#P2-34).
        $page->through(fn (Enquiry $e) => EnquiryResource::make($e)->resolve());

        return $this->success($this->paginatedResponse($page));
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
