<?php

namespace Tests\Schema\Concerns;

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single fix for the dominant blocker across the schema lane's seeding
 * tests: core.users.auth_user_id is a real FK onto the auth.users shim
 * (users_auth_user_id_fkey, created by scripts/db/apply-migrations.sh's
 * ci-supabase-shim.sql). SQLite's test schema for core.users has no such FK,
 * so `User::factory()->create()` — which fabricates a random auth_user_id —
 * "just works" there and is rejected by real Postgres. Generalises the
 * seed-the-parent-row-first pattern tests/Authz/Fixtures.php::makeUser()
 * already used for the identical reason.
 *
 * Schema-lane only. The bare tests/Postgres/ lane runs against an unmigrated
 * container with no `auth` schema at all — this trait has nothing to attach
 * to there and must not be used outside tests/Schema/.
 *
 * Neither SchemaTestCase nor the lane wraps tests in a transaction (see its
 * docblock) — the database is persistent and shared across the whole run, so
 * every row this seeds must be torn down explicitly. Pair every
 * seedAuthUser() call with cleanupSeededUser() (typically in the test's own
 * try/finally, or an afterEach if a file seeds more than one).
 */
trait SeedsAuthUsers
{
    /**
     * Insert the auth.users parent row, then a core.users row that FKs onto
     * it via the model factory.
     *
     * @param  array<string, mixed>  $overrides  Extra core.users attributes/overrides,
     *                                           merged over the auth_user_id default.
     */
    protected function seedAuthUser(array $overrides = []): User
    {
        $authUserId = (string) Str::uuid();

        DB::connection('pgsql')->table('auth.users')->insert([
            'id' => $authUserId,
            'email' => $authUserId.'@schema-lane.test',
        ]);

        return User::factory()->create(array_merge([
            'auth_user_id' => $authUserId,
        ], $overrides));
    }

    /**
     * Undo seedAuthUser(). forceDelete (not delete — User uses SoftDeletes)
     * so the row actually leaves core.users and the real FK cascade rules
     * fire: site.sites CASCADEs on user_id, moderation.cases/partna_staff
     * SET NULL on their owner/staff FKs. The auth.users row has no cascade
     * back from core.users (ON DELETE SET NULL runs the other way), so it is
     * removed explicitly.
     */
    protected function cleanupSeededUser(User $user): void
    {
        $authUserId = $user->auth_user_id;

        $user->forceDelete();

        if ($authUserId !== null) {
            DB::connection('pgsql')->table('auth.users')->where('id', $authUserId)->delete();
        }
    }
}
