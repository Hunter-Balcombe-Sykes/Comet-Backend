<?php

namespace App\Ingest\Runtime;

use App\Ingest\Manifest\StreamSpec;

/** What a connector is being asked to fetch this run. */
final readonly class Pull
{
    /**
     * @param  array<string, mixed>  $cursor  last Bookmark for this stream
     * @param  array<string, mixed>  $config  per-source options (scope, scope_n)
     */
    public function __construct(
        public string $identifier,
        public StreamSpec $stream,
        public array $cursor = [],
        public array $config = [],
        public bool $isClaimed = true,
    ) {}

    public function scopeLimit(): ?int
    {
        $scope = $this->config['scope'] ?? 'all';

        return $scope === 'latest_n' ? (int) ($this->config['scope_n'] ?? 12) : null;
    }
}
