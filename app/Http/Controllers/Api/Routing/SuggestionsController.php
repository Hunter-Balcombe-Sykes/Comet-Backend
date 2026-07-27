<?php

namespace App\Http\Controllers\Api\Routing;

use App\Catalog\CompiledCatalog;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Review-suggestions inbox (plan §2/§3): everything the router recognised
 * but would not act on alone.
 *
 * This is the counterweight to the suggestion gate. Moving below-threshold
 * matches from silent writes to confirmable suggestions is only an
 * improvement if the suggestions are somewhere a person can actually see and
 * resolve them — otherwise it is just silent dropping with extra steps.
 *
 * Three states surface here:
 *   proposed — recognised, below the auto-apply bar. "Is this yours?"
 *   blocked/conflict — recognised, but something already owns that slot.
 *                      Keep or Replace.
 *   blocked/other — recognised, but a gate or cap refuses. Explained, not
 *                   actionable.
 */
class SuggestionsController extends ApiController
{
    use ResolveCurrentUser;

    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $intents = DB::table('routing.source_intents')
            ->where('user_id', $user->id)
            ->whereIn('state', ['proposed', 'blocked'])
            ->orderByDesc('first_seen_at')
            ->limit(100)
            ->get();

        $suggestions = $intents->map(function (object $intent): array {
            $surface = CompiledCatalog::surface($intent->surface_key);

            return [
                'id' => $intent->id,
                'state' => $intent->state,
                'blockReason' => $intent->block_reason,
                'surfaceKey' => $intent->surface_key,
                'displayName' => $surface['display_name'] ?? $intent->surface_key,
                'brandKey' => $surface['brand_key'] ?? null,
                'routingClass' => $intent->routing_class,
                'identifier' => $intent->identifier,
                'url' => $intent->canonical_url,
                'origin' => $intent->origin,
                'firstSeenAt' => $intent->first_seen_at,
                'conflictingConnectionId' => $intent->conflicting_connection_id,
                // What the user is being asked, in their words rather than the
                // reconciler's.
                'question' => $this->questionFor($intent, $surface),
                'actions' => $this->actionsFor($intent),
            ];
        })->all();

        return $this->success(['suggestions' => $suggestions]);
    }

    /** Accept a suggestion: apply the intent and create the connection. */
    public function accept(Request $request, string $intentId): JsonResponse
    {
        $user = $this->currentUser($request);
        $intent = $this->findIntent($user->id, $intentId);

        if ($intent === null) {
            return $this->error('That suggestion is no longer available.', 404);
        }

        $surface = CompiledCatalog::surface($intent->surface_key);
        if ($surface === null) {
            return $this->error('That platform is no longer supported.', 422);
        }

        return DB::transaction(function () use ($user, $intent, $surface) {
            // Replacing an incumbent: demote it rather than delete it. The
            // user asked for a different primary, not for their data to go.
            if ($intent->conflicting_connection_id !== null) {
                IntegrationConnection::query()
                    ->where('id', $intent->conflicting_connection_id)
                    ->where('user_id', $user->id)
                    ->update(['is_primary' => false]);
            }

            $connection = IntegrationConnection::query()
                ->where('user_id', $user->id)
                ->where('surface_key', $intent->surface_key)
                ->where('resource_id', $intent->identifier)
                ->whereNull('deleted_at')
                ->first();

            if ($connection === null) {
                $connection = new IntegrationConnection([
                    'user_id' => $user->id,
                    'surface_key' => $intent->surface_key,
                    'routing_class' => $intent->routing_class,
                    'resource_id' => $intent->identifier,
                    'payload' => ['url' => $intent->canonical_url, 'source' => 'suggestion'],
                    'is_active' => true,
                    'last_refresh_status' => 'pending',
                ]);
                $connection->save();
            }

            if ($intent->conflicting_connection_id !== null) {
                $connection->forceFill(['is_primary' => true])->save();
            }

            DB::table('routing.source_intents')->where('id', $intent->id)->update([
                'state' => 'applied',
                'block_reason' => null,
                'connection_id' => $connection->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->success([
                'connectionId' => $connection->id,
                'surfaceKey' => $intent->surface_key,
                'displayName' => $surface['display_name'],
            ]);
        });
    }

    /**
     * Dismiss a suggestion. Permanent by design: re-asking a question the
     * user already answered is how an inbox becomes noise people ignore.
     */
    public function dismiss(Request $request, string $intentId): JsonResponse
    {
        $user = $this->currentUser($request);
        $intent = $this->findIntent($user->id, $intentId);

        if ($intent === null) {
            return $this->error('That suggestion is no longer available.', 404);
        }

        DB::table('routing.source_intents')->where('id', $intent->id)->update([
            'state' => 'dismissed',
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        // A dismissal is also an instruction not to re-import this: without
        // the tombstone the next harvest would simply propose it again.
        DB::table('routing.item_tombstones')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'source_ref' => $intent->surface_key.':'.$intent->identifier,
            'scope' => 'this_source',
            'reason' => 'suggestion dismissed',
            'created_at' => now(),
        ]);

        return $this->success(['dismissed' => true]);
    }

    private function findIntent(string $userId, string $intentId): ?object
    {
        return DB::table('routing.source_intents')
            ->where('id', $intentId)
            ->where('user_id', $userId)
            ->whereIn('state', ['proposed', 'blocked'])
            ->first();
    }

    /** @param array<string, mixed>|null $surface */
    private function questionFor(object $intent, ?array $surface): string
    {
        $name = $surface['display_name'] ?? 'this platform';

        return match ($intent->block_reason) {
            'conflict' => "You already have a {$intent->routing_class} link. Use this {$name} one instead?",
            'cap_reached' => "You've reached the limit for {$name} accounts.",
            'gate' => "{$name} isn't available on your account type.",
            'below_threshold' => "Is this your {$name}?",
            default => "Add this {$name} link?",
        };
    }

    /** @return list<string> */
    private function actionsFor(object $intent): array
    {
        return match ($intent->block_reason) {
            'conflict' => ['replace', 'dismiss'],
            'gate', 'cap_reached' => ['dismiss'],
            default => ['accept', 'dismiss'],
        };
    }
}
