<?php

namespace App\Http\Middleware\Logging;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Audit\StaffAuditService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// OPS-2: append-only audit log middleware. Attached to both /staff/* route
// groups. Fires in terminate() so the audit insert never adds latency to the
// response, and swallows all errors — audit-log outages must not block staff.
//
// Captures actor, target, route, method, status, route bindings, IP, UA.
// Deliberately does NOT capture request body — body-detail forensics is opt-in
// per controller. Controllers wanting payload-summary enrichment should call
// StaffAuditService::record() directly with named parameters, e.g.:
//   $audit->record(
//       staff: $staff,
//       impersonator: null,
//       professional: $pro,
//       route: $request->route()?->getName() ?? 'unknown',
//       httpMethod: $request->method(),
//       statusCode: 200,
//       payloadSummary: ['changed_fields' => array_keys($changes)],
//       ip: $request->ip(),
//       userAgent: $request->userAgent(),
//   );
// payloadSummary is the 7th positional parameter, not a wrapper array bag.
class RecordStaffAuditEntry
{
    private const WRITE_METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];

    // PRIV-4: staff GET endpoints that return user PII get an audit row too —
    // writes alone left no trail of who *viewed* a customer list, an
    // enquiries inbox, or an email-subscriber export. Deliberately excludes
    // staff.professionals.index (PII is gated behind $showPii — plain staff
    // never see it) and non-PII endpoints (sites, workplaces, stats). Adding a
    // new PII-returning GET route means adding both a ->name() in
    // routes/api/staff.php and an entry here.
    private const PII_READ_ROUTE_NAMES = [
        'staff.professionals.show',
        'staff.customers.index',
        'staff.customers.show',
        'staff.email-subscribers.index',
        'staff.email-subscribers.export',
        'staff.enquiries.index',
    ];

    public function __construct(private readonly StaffAuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $isWrite = in_array($request->method(), self::WRITE_METHODS, true);
        $isAllowlistedPiiRead = $request->method() === 'GET'
            && in_array($request->route()?->getName(), self::PII_READ_ROUTE_NAMES, true);

        if (! $isWrite && ! $isAllowlistedPiiRead) {
            return;
        }

        try {
            $staff = $request->attributes->get('partna_staff');
            $staff = $staff instanceof PartnaStaff ? $staff : null;

            $professionalParam = $request->route()?->parameter('professional');
            $professional = $professionalParam instanceof User ? $professionalParam : null;
            $userIdFromString = (is_string($professionalParam) && $professionalParam !== '')
                ? $professionalParam
                : null;

            // When no route-model binding resolved, construct a bare Professional
            // so the service can still record the user_id FK. Handle is null
            // because we only have the UUID — snapshot will be null, which is fine.
            if ($professional === null && $userIdFromString !== null) {
                $professional = new User;
                $professional->id = $userIdFromString;
            }

            $this->audit->record(
                staff: $staff,
                impersonator: null,
                professional: $professional,
                route: $request->route()?->getName() ?? $request->route()?->uri() ?? $request->path() ?: 'unknown',
                httpMethod: $request->method(),
                statusCode: $response->getStatusCode(),
                payloadSummary: $this->summariseBindings($request, $userIdFromString),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (Throwable $e) {
            // Belt-and-suspenders — StaffAuditService already catches DB errors,
            // but this guards against parameter-resolution issues we haven't
            // anticipated. Re-derive staff/request_id fresh from $request rather
            // than reusing the try-block locals above — those may be unset if
            // the exception fired before they were assigned.
            $staffOnFailure = $request->attributes->get('partna_staff');
            $staffId = $staffOnFailure instanceof PartnaStaff ? $staffOnFailure->id : null;

            Log::warning('staff.audit.middleware_failed', [
                'exception' => $e->getMessage(),
                'route' => $request->path(),
                'staff_id' => $staffId,
                'request_id' => $request->header('X-Request-Id'),
            ]);

            // OBS-3: Log::warning above is breadcrumb-only — surface to Nightwatch
            // too, throttled to one per minute (same idiom as
            // IdempotencyKey::logFailOpen) so a sustained failure mode can't flood
            // it. Report unconditionally if the throttle layer itself is down.
            try {
                $lock = Cache::lock('staff.audit.middleware_failed-reported', 60);
                if ($lock->get()) {
                    report($e);
                }
            } catch (Throwable) {
                report($e);
            }
        }
    }

    /**
     * Reduce route parameters to a scalar map: Eloquent models become their ID,
     * scalars pass through. Non-scalar, non-Model values are dropped.
     *
     * @return array<string, string|int|bool|float>
     */
    private function summariseBindings(Request $request, ?string $userIdFromString): array
    {
        $params = $request->route()?->parameters() ?? [];
        $summary = [];

        foreach ($params as $key => $value) {
            if ($value instanceof Model) {
                $summary[$key] = (string) $value->getKey();
            } elseif (is_scalar($value)) {
                $summary[$key] = $value;
            }
        }

        // Backstop: if the professional came through as a raw string we
        // already captured it for the FK field, but route()->parameters()
        // returns the same thing so this is usually a no-op.
        if ($userIdFromString !== null && ! isset($summary['professional'])) {
            $summary['professional'] = $userIdFromString;
        }

        return $summary;
    }
}
