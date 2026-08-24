<?php

namespace App\Routing;

use App\Catalog\CatalogNotCompiled;
use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The bridge between the legacy `payload.syncFindings` ledger and the intent
 * ledger — now pointing the other way.
 *
 * B4 (p8 deletion readiness) built it to fold new-pipeline Hold intents INTO
 * the per-platform synced modal, so migrating a scan path would not silently
 * stop telling users about conflicts. The owner retired that modal on
 * 2026-08-19 — two places to answer the same question ("we found this, what do
 * you want to do?") is one place too many — so the direction inverts: the
 * suggestions inbox is the sink, and the legacy payload findings fold INTO it.
 *
 * Both directions live here while the legacy ledger still exists. Neither ever
 * writes a fold into stored state: one source of truth, nothing to un-ship.
 * When InstagramAutoSync and GoogleBusinessAutoSync move onto the new router
 * there are no payload findings left, and this class goes with them.
 */
class SyncFindingsBridge
{
    /** Origins whose scans surface in the Instagram synced modal. */
    public const BIO_ORIGINS = ['bio_harvest', 'link_in_bio'];

    /** The two platforms whose payloads carry a syncFindings ledger. */
    public const FINDING_HOLDERS = ['instagram', 'google-business'];

    /** Prefix marking a suggestion id that addresses a payload finding, not an intent. */
    public const PAYLOAD_ID_PREFIX = 'sync:';

    /**
     * Hold-intent conflicts shaped as legacy findings ({platform, category,
     * label, status:'conflict', foundUrl, removePath}).
     *
     * @return list<array<string, mixed>>
     */
    public function conflictFindings(User $user): array
    {
        return $this->conflicts($user)
            ->map(fn (object $intent): array => $this->shape($intent))
            ->all();
    }

    /** The Hold intent behind a folded finding, looked up by its legacy platform slug. */
    public function findConflict(User $user, string $legacyPlatform): ?object
    {
        return $this->conflicts($user)
            ->first(fn (object $intent) => LegacyPlatformMap::legacyFor($intent->surface_key) === $legacyPlatform);
    }

    /**
     * The legacy ledger's unresolved conflicts, shaped as SUGGESTION rows for
     * the inbox (2026-08-19, replacing the retired synced modal).
     *
     * Only `conflict` findings cross over. A `seeded` finding says "we
     * connected this for you", which the Platforms page already shows — an
     * inbox is for what still needs an answer, and re-listing settled work is
     * how an inbox becomes something people stop reading.
     *
     * The id is `sync:{holder}:{platform}` — the address of a finding inside a
     * payload, since a payload finding has no id of its own. accept/dismiss
     * route on that prefix.
     *
     * @return list<array<string, mixed>>
     */
    public function payloadSuggestions(User $user): array
    {
        $rows = [];

        foreach ($this->findingHolders($user) as $holder => $connection) {
            foreach ($this->findingsOf($connection) as $finding) {
                if (($finding['outcome'] ?? null) !== 'conflict') {
                    continue;
                }

                $platform = (string) ($finding['platform'] ?? '');
                if ($platform === '') {
                    continue;
                }

                $surfaceKey = LegacyPlatformMap::surfaceFor($platform) ?? $platform;
                $label = (string) ($finding['label'] ?? $platform);

                $rows[] = [
                    'id' => self::PAYLOAD_ID_PREFIX.$holder.':'.$platform,
                    'state' => 'blocked',
                    'blockReason' => 'conflict',
                    'surfaceKey' => $surfaceKey,
                    'displayName' => $label,
                    'brandKey' => LegacyPlatformMap::legacyFor($surfaceKey),
                    'routingClass' => (string) ($finding['category'] ?? ''),
                    'identifier' => null,
                    'url' => is_string($finding['foundUrl'] ?? null) ? $finding['foundUrl'] : null,
                    'origin' => $holder === 'instagram' ? 'bio_harvest' : 'google_business',
                    'firstSeenAt' => null,
                    'conflictingConnectionId' => null,
                    'question' => "You already have a {$finding['category']} link. Use this {$label} one instead?",
                    'actions' => ['replace', 'dismiss'],
                ];
            }
        }

        return $rows;
    }

    /** Resolve a `sync:` suggestion id to its holder connection and finding index. */
    public function locatePayloadFinding(User $user, string $suggestionId): ?array
    {
        [$holder, $platform] = array_pad(explode(':', substr($suggestionId, strlen(self::PAYLOAD_ID_PREFIX)), 2), 2, null);
        if (! is_string($holder) || ! is_string($platform) || ! in_array($holder, self::FINDING_HOLDERS, true)) {
            return null;
        }

        $connection = $this->findingHolders($user)[$holder] ?? null;
        if ($connection === null) {
            return null;
        }

        foreach ($this->findingsOf($connection) as $index => $finding) {
            if (($finding['platform'] ?? null) === $platform && ($finding['outcome'] ?? null) === 'conflict') {
                return ['holder' => $holder, 'connection' => $connection, 'index' => $index, 'finding' => $finding];
            }
        }

        return null;
    }

    /**
     * Settle one payload finding in place — 'seeded' once its swap is applied,
     * 'dismissed' when the owner said no. Both stop it being re-offered, which
     * is the whole job: an inbox that re-asks an answered question is noise.
     */
    public function settlePayloadFinding(IntegrationConnection $connection, int $index, string $outcome): void
    {
        $payload = $connection->payload;
        $findings = $this->findingsOf($connection);
        if (! isset($findings[$index])) {
            return;
        }

        $findings[$index]['outcome'] = $outcome;
        $findings[$index]['apply'] = null;

        $connection->forceFill(['payload' => [...$payload, 'syncFindings' => array_values($findings)]])->saveQuietly();
    }

    /** @return array<string, IntegrationConnection> */
    private function findingHolders(User $user): array
    {
        return $user->integrationConnections()
            ->whereIn('platform', self::FINDING_HOLDERS)
            ->get()
            ->keyBy(fn (IntegrationConnection $row) => (string) $row->platform)
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function findingsOf(IntegrationConnection $connection): array
    {
        $findings = $connection->payload['syncFindings'] ?? [];

        return is_array($findings)
            ? array_values(array_filter($findings, 'is_array'))
            : [];
    }

    /** @return Collection<int, \stdClass> */
    private function conflicts(User $user): Collection
    {
        return DB::table('routing.source_intents')
            ->where('user_id', $user->id)
            ->where('state', 'blocked')
            ->where('block_reason', 'conflict')
            ->whereIn('origin', self::BIO_ORIGINS)
            ->orderByDesc('first_seen_at')
            ->get()
            ->values();
    }

    /** @return array<string, mixed> */
    private function shape(object $intent): array
    {
        try {
            $surface = CompiledCatalog::surface($intent->surface_key);
        } catch (CatalogNotCompiled) {
            $surface = null;
        }

        return [
            'platform' => LegacyPlatformMap::legacyFor($intent->surface_key),
            'category' => $this->legacyCategory((string) $intent->routing_class),
            'label' => $surface['display_name'] ?? $intent->surface_key,
            'status' => 'conflict',
            'foundUrl' => $intent->canonical_url,
            'removePath' => null,
        ];
    }

    /** The modal speaks the legacy category vocabulary, not routing classes. */
    private function legacyCategory(string $routingClass): string
    {
        return match ($routingClass) {
            'ordering' => 'online-ordering',
            'events' => 'event',
            'content', 'link' => 'other',
            default => $routingClass,
        };
    }
}
