<?php

namespace App\Services\User;

use App\Models\Core\User\User;
use App\Models\Core\User\UserCredential;
use App\Models\Core\User\UserExperience;
use Illuminate\Support\Str;

// Syncs credentials and experience from a validated about payload to the child tables.
// Called from both UserSelfController::update() and StaffUserController::update() to keep
// the write path consistent regardless of which actor triggers the change (FOUND-5).
//
// Uses a delete-and-reinsert pattern (≤5 rows per section per validation limit) so
// sort_order is always a clean 0-indexed sequence without gaps or drift.
class SyncUserAboutService
{
    public function __construct(
        private readonly SectionVisibilityService $visibility,
    ) {}

    /**
     * Replace credentials and experience rows for the given user with the supplied arrays.
     * Both keys are optional in $about — omitting a key leaves that section untouched.
     *
     * @param  array{credentials?: list<array{title: string, issuer?: string|null, year?: string|int|null, description?: string|null}>, experience?: list<array{role: string, place?: string|null, start?: string|null, end?: string|null, description?: string|null}>}  $about
     */
    public function sync(User $user, array $about): void
    {
        if (array_key_exists('credentials', $about)) {
            $this->syncCredentials($user, (array) ($about['credentials'] ?? []));
        }

        if (array_key_exists('experience', $about)) {
            $this->syncExperience($user, (array) ($about['experience'] ?? []));
        }

        // Re-gate the section blocks so is_enabled reflects the new data immediately.
        $site = $user->site;
        if ($site !== null) {
            if (array_key_exists('credentials', $about)) {
                $this->visibility->reevaluateEnabled((string) $user->id, (string) $site->id, 'credentials');
            }
            if (array_key_exists('experience', $about)) {
                $this->visibility->reevaluateEnabled((string) $user->id, (string) $site->id, 'experience');
            }
        }
    }

    /** @param  list<array{title?: string, issuer?: string|null, year?: string|int|null, description?: string|null}>  $rows */
    private function syncCredentials(User $user, array $rows): void
    {
        UserCredential::query()->where('user_id', $user->id)->delete();

        $now = now()->toDateTimeString();
        $inserts = [];

        foreach ($rows as $i => $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $year = isset($row['year']) && $row['year'] !== '' && $row['year'] !== null
                ? (string) $row['year']
                : null;

            $inserts[] = [
                'id' => (string) Str::uuid(),
                'user_id' => (string) $user->id,
                'title' => $title,
                'issuer' => isset($row['issuer']) && $row['issuer'] !== '' ? trim((string) $row['issuer']) : null,
                'year' => $year,
                'description' => isset($row['description']) && $row['description'] !== '' ? trim((string) $row['description']) : null,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($inserts)) {
            UserCredential::query()->insert($inserts);
        }
    }

    /** @param  list<array{role?: string, place?: string|null, start?: string|null, end?: string|null, description?: string|null}>  $rows */
    private function syncExperience(User $user, array $rows): void
    {
        UserExperience::query()->where('user_id', $user->id)->delete();

        $now = now()->toDateTimeString();
        $inserts = [];

        foreach ($rows as $i => $row) {
            $role = trim((string) ($row['role'] ?? ''));
            if ($role === '') {
                continue;
            }

            $inserts[] = [
                'id' => (string) Str::uuid(),
                'user_id' => (string) $user->id,
                'role' => $role,
                // Legacy payload used "place"; store in organisation column.
                'organisation' => isset($row['place']) && $row['place'] !== '' ? trim((string) $row['place']) : null,
                'start_year' => isset($row['start']) && $row['start'] !== '' ? trim((string) $row['start']) : null,
                'end_year' => isset($row['end']) && $row['end'] !== '' ? trim((string) $row['end']) : null,
                'description' => isset($row['description']) && $row['description'] !== '' ? trim((string) $row['description']) : null,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($inserts)) {
            UserExperience::query()->insert($inserts);
        }
    }
}
