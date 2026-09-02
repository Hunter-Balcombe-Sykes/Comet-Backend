<?php

namespace App\Routing;

use App\Models\Core\User\User;
use App\Services\Platforms\FreshaWorkplaceLinker;
use Illuminate\Support\Facades\DB;

/**
 * Settling a workplace candidate (A.5/A.9): adopt connects it and
 * supersedes the siblings — the question was "which one is yours", so one
 * answer settles all of them. Shared by the suggestions inbox and the
 * setup dialog's batch accept.
 */
class WorkplaceCandidates
{
    public function __construct(private readonly FreshaWorkplaceLinker $linker) {}

    /**
     * @return array{connectionId: ?string, name: string}|null null = no such proposed candidate for this user
     *
     * @throws \RuntimeException CANDIDATE_CONNECT_FAILED when the connect write fails
     */
    public function adopt(User $user, string $candidateId): ?array
    {
        $row = DB::table('site.workplace_candidates')
            ->where('id', $candidateId)
            ->where('user_id', $user->id)
            ->where('state', 'proposed')
            ->first();
        if ($row === null) {
            return null;
        }

        $result = $this->linker->connect($user, [
            'id' => (string) $row->place_id,
            'name' => (string) $row->name,
            'address' => $row->address,
            'lat' => $row->lat !== null ? (float) $row->lat : null,
            'lng' => $row->lng !== null ? (float) $row->lng : null,
        ]);
        if ($result['outcome'] !== 'connected') {
            throw new \RuntimeException('CANDIDATE_CONNECT_FAILED');
        }

        DB::table('site.workplace_candidates')->where('id', $row->id)->update(['state' => 'adopted']);
        DB::table('site.workplace_candidates')
            ->where('user_id', $user->id)
            ->where('state', 'proposed')
            ->update(['state' => 'superseded']);

        return ['connectionId' => $result['connectionId'] ?? null, 'name' => (string) $row->name];
    }

    public function dismiss(User $user, string $candidateId): bool
    {
        return DB::table('site.workplace_candidates')
            ->where('id', $candidateId)
            ->where('user_id', $user->id)
            ->where('state', 'proposed')
            ->update(['state' => 'dismissed']) > 0;
    }
}
