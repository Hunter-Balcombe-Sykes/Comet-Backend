<?php

use Illuminate\Support\Facades\Gate;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Policy Coverage Sweep
|--------------------------------------------------------------------------
| Every model under app/Models/ must either (a) have a Gate-registered
| Policy, or (b) appear in POLICY_EXEMPT below with a justification.
|
| Adding a new model? Either register a policy in AppServiceProvider::boot
| or add an entry below explaining why this model doesn't need one.
| Untracked models silently allow IDOR — this test prevents that.
*/

const POLICY_EXEMPT = [
    // Catalog & system tables — no tenant ownership; admin-only or read-only.
    \App\Models\Billing\Plan::class,
    \App\Models\Billing\WebhookEvent::class,
    \App\Models\Core\MediaVariant::class,           // owned via parent SiteMedia
    \App\Models\Core\Waitlist\WaitlistSignup::class, // public submission, no actor

    // Shared catalog — one Theme can be applied to many sites; read by public
    // site renderer, mutations are admin-only.
    \App\Models\Core\Site\Theme::class,

    // Public ingestion — write-only via public site endpoints; scoped by
    // ResolvesSiteFromRequest at write time. Reads happen via the analytics
    // API, gated by the parent Site/CommissionPolicy.
    \App\Models\Analytics\CartEvent::class,
    \App\Models\Analytics\LinkClick::class,
    \App\Models\Analytics\SectionView::class,
    \App\Models\Analytics\SiteVisit::class,

    // Nested under CommissionPayout — gated transitively by CommissionPolicy.
    \App\Models\Commerce\CommissionPayoutItem::class,

    // Nested under Commerce\Order — append-only audit log; access flows through
    // the parent Order's CommissionPolicy. Mirrors the CommissionPayoutItem pattern.
    \App\Models\Commerce\OrderEvent::class,

    // Nested under CommissionPayout — append-only Stripe-reversal log written
    // server-side by CommissionPayoutRefundService. No user-facing CRUD; reads
    // are gated by the parent CommissionPayout's CommissionPolicy (and at the
    // DB layer by RLS policy `clawbacks_party_select` in
    // 20260512200000_commission_clawbacks_enable_rls.sql).
    \App\Models\Commerce\CommissionClawback::class,

    // Append-only audit log for handle/subdomain renames; readable by staff only — no per-row tenant policy.
    \App\Models\Core\HandleChangeLog::class,

    // Handle alias table — read/write access flows through the parent Professional's policy.
    \App\Models\Core\Site\ProfessionalHandleAlias::class,

    // OPS-2: Append-only staff audit log. Never exposed over the API — support
    // queries via SQL only. No tenant ownership; staff actor and target professional
    // are FK metadata, not authorization keys. A Policy class would be meaningless
    // here because there is no controller action to gate.
    \App\Models\Core\Staff\StaffAuditEntry::class,

    // Async export audit row. Created by the professional's own export request;
    // read back only by the same professional via the export status endpoint.
    // Access is gated at the controller level (professional_id scoping) — there
    // is no cross-tenant resource access risk, and no policy-gated CRUD surface.
    \App\Models\Commerce\CommissionExportAudit::class,

    // Append-only audit log for brand signup code lifecycle events (generated, rotated,
    // claimed, failed_claim, etc.). Written only by BrandSignupCodeService — never
    // directly exposed over the API for write. Dashboard counts are aggregated via
    // BrandSignupCodeController which scopes all reads to the authenticated brand's
    // own profile. No per-row tenant policy is needed.
    \App\Models\Core\Professional\BrandSignupCodeAuditEntry::class,
];

it('every tenant-owned model has a registered policy', function () {
    $modelFiles = (new Finder)
        ->files()
        ->in(app_path('Models'))
        ->name('*.php')
        ->notName('BaseModel.php')
        ->notPath('Views') // read-only DB views are not policy-gated
        ->getIterator();

    $missing = [];

    foreach ($modelFiles as $file) {
        $relative = str_replace([app_path(), '/', '.php'], ['App', '\\', ''], $file->getRealPath());
        if (! class_exists($relative)) {
            continue;
        }

        if (in_array($relative, POLICY_EXEMPT, true)) {
            continue;
        }

        $policy = Gate::getPolicyFor($relative);
        if ($policy === null) {
            $missing[] = $relative;
        }
    }

    expect($missing)->toBe([], "Models without a registered Policy:\n  - ".implode("\n  - ", $missing)."\n\nEither register one in AppServiceProvider::boot() or add to POLICY_EXEMPT in this test with a justification.");
});

it('every POLICY_EXEMPT entry resolves to a real model class', function () {
    foreach (POLICY_EXEMPT as $class) {
        expect(class_exists($class))->toBeTrue("POLICY_EXEMPT entry {$class} does not resolve to an existing class.");
    }
});
