<?php

namespace App\Services\Site;

use App\Services\Site\UpdateSiteAction;
use App\Models\Core\HandleChangeLog;
use App\Models\Core\Professional\User;
use App\Models\Core\Site\ProfessionalHandleAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Lets the original owner take back an aliased handle within the grace window
// without burning the 30-day rename cooldown.
//
// Returns 404 when alias doesn't belong to this professional — never 403.
// Surfacing ownership info (403) exposes the alias table to enumeration.
class ReclaimHandleAction
{
    public function __construct(private readonly UpdateSiteAction $updateSiteAction) {}

    public function execute(User $professional, string $handle, array $context = []): void
    {
        $handle = strtolower($handle);

        DB::transaction(function () use ($professional, $handle, $context) {
            $alias = ProfessionalHandleAlias::query()
                ->where('professional_id', $professional->id)
                ->whereRaw('lower(handle) = ?', [$handle])
                ->lockForUpdate()
                ->first();

            // 404 when alias isn't ours — never reveal why (enumeration risk).
            if (! $alias) {
                throw new NotFoundHttpException();
            }

            if (! $alias->reclaim_until || $alias->reclaim_until->isPast()) {
                throw ValidationException::withMessages([
                    'handle' => ['This handle can no longer be reclaimed.'],
                ]);
            }

            $this->updateSiteAction->execute(
                $professional->fresh(),
                ['subdomain' => $handle],
                array_merge($context, [
                    'allow_subdomain_override' => true,
                    'reason'                   => HandleChangeLog::REASON_RECLAIM,
                ])
            );

            // The UpdateSiteAction collapse logic (Task 4) deletes matching alias rows.
        });
    }
}
