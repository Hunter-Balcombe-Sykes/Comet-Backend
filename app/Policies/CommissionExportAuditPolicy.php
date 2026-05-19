<?php

namespace App\Policies;

use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for CommissionExportAudit rows. One audit row per async
 * export request; only the owning Professional may view it.
 *
 * Cross-tenant denials return 404 (not 403) so the existence of an export
 * ID never leaks across accounts — matches the audit doctrine in CLAUDE.md.
 */
class CommissionExportAuditPolicy extends BasePolicy
{
    public function view(Professional $actor, CommissionExportAudit $audit): bool|Response
    {
        if ((string) $audit->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }
}
