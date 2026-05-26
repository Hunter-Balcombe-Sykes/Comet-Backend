<?php

namespace App\Http\Middleware\Logging;

use App\Models\Core\User\User;
use App\Models\Core\Staff\PartnaStaff;
use App\Services\Audit\StaffAuditService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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

    public function __construct(private readonly StaffAuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! in_array($request->method(), self::WRITE_METHODS, true)) {
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
            // anticipated.
            Log::warning('staff.audit.middleware_failed', [
                'exception' => $e->getMessage(),
                'route' => $request->path(),
            ]);
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
