<?php

namespace App\Services\Site;

use App\Models\Core\HandleChangeLog;
use App\Models\Core\Site\UserHandleAlias;
use App\Models\Core\User\User;
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

        // The outer pgsql transaction + the inner UpdateSiteAction transaction form a
        // nested savepoint pair on the same connection. The lockForUpdate on the alias
        // row serializes concurrent reclaim attempts for the same handle, so the
        // reclaim-window check (reclaim_until > now()) cannot be raced between two
        // requests arriving simultaneously.
        DB::connection('pgsql')->transaction(function () use ($professional, $handle, $context) {
            $alias = UserHandleAlias::query()
                ->where('user_id', $professional->id)
                ->whereRaw('lower(handle) = ?', [$handle])
                ->lockForUpdate()
                ->first();

            // 404 when alias isn't ours — never reveal why (enumeration risk).
            if (! $alias) {
                throw new NotFoundHttpException;
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
                    'reason' => HandleChangeLog::REASON_RECLAIM,
                ])
            );

            // The UpdateSiteAction collapse logic (Task 4) deletes matching alias rows.
        });
    }
}
