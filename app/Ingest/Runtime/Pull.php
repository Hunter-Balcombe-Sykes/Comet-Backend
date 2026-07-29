<?php

namespace App\Ingest\Runtime;

use App\Ingest\Manifest\StreamSpec;

/** What a connector is being asked to fetch this run. */
final readonly class Pull
{
    /**
     * @param  array<string, mixed>  $cursor  last Bookmark for this stream
     * @param  array<string, mixed>  $config  per-source options (scope, scope_n)
     * @param  bool  $isClaimed  drives Manifest::redactionsFor() — a PII gate,
     *   so the default must fail CLOSED. RunExecutor:75 is the only production
     *   construction site today and always passes this explicitly; the default
     *   only matters to a future second call site that forgets the argument,
     *   and "forgot to pass it" must silently over-redact, never under-redact.
     */
    public function __construct(
        public string $identifier,
        public StreamSpec $stream,
        public array $cursor = [],
        public array $config = [],
        public bool $isClaimed = false,
    ) {}

    public function scopeLimit(): ?int
    {
        $scope = $this->config['scope'] ?? 'all';

        return $scope === 'latest_n' ? (int) ($this->config['scope_n'] ?? 12) : null;
    }
}
