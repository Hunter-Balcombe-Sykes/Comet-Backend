<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Stripe\StripeConnectService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// V2: Funding gate for brand-side affiliate-invite write endpoints. The
// brand can't push their first invite into the wild without a card on
// file, because every commission payout that affiliate generates becomes
// the brand's funding obligation. Without this gate, the platform absorbs
// the float for any brand who lapses on Stripe before the first payout
// settles.
//
// NOTE: This is an "on-file" check — it verifies our DB columns are
// non-null, not a live Stripe authorise. To keep this accurate, the
// payment_method.detached Stripe webhook nullifies stripe_payment_method_id
// proactively when a brand removes their card at Stripe. There is a small
// window between detachment and webhook delivery; acceptable given low
// invite frequency and Stripe's sub-second webhook delivery in practice.
//
// Returns 402 Payment Required with a structured payload so the
// dashboard can surface the funding-gate dialog without parsing prose.
// Non-brand callers pass through — only brand professional types own
// invite endpoints, so a 402 here would be misleading for staff or
// affiliate JWTs that hit the route in error.
//
// Apply via route middleware: `->middleware('brand-funding-gate')`.
class BrandFundingGate
{
    use ResolveCurrentProfessional;

    public function __construct(private StripeConnectService $connectService) {}

    public function handle(Request $request, Closure $next)
    {
        $professional = $this->currentProfessional($request);

        // Non-brand requests pass through. The route's own role check (or
        // policy / controller guard) is the right place to 403 for
        // non-brand traffic; double-rejecting here would mask the real
        // reason and make the response shape misleading.
        if (! $professional->isBrand()) {
            return $next($request);
        }

        if ($this->connectService->brandHasPaymentMethod($professional)) {
            return $next($request);
        }

        Log::warning('BrandFundingGate: denied — no payment method on file', [
            'brand_id' => $professional->id,
            'reason' => 'no_payment_method',
        ]);

        // 402 Payment Required is the correct semantic. The payload's
        // `code` field is what the dashboard reads to render the
        // funding-gate dialog vs a generic toast.
        return response()->json([
            'message' => 'A payment method is required before sending affiliate invites.',
            'code' => 'brand_funding_required',
            'data' => [
                'reason' => 'no_payment_method',
                'connect_path' => '/account/settings?section=payments',
            ],
        ], 402);
    }
}
