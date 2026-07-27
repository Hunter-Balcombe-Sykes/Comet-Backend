<?php

namespace App\Ingest\Runtime;

/**
 * The ONLY way a connector touches the outside world. Every call is admitted
 * (host allow-list from the manifest), budgeted, and ledgered before a socket
 * opens — so those guarantees cannot be forgotten by a connector author,
 * because a connector has no other way to fetch anything.
 */
interface Io
{
    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string, headers: array<string, mixed>}
     */
    public function get(string $url, array $headers = []): array;

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string, headers: array<string, mixed>}
     */
    public function post(string $url, array $body = [], array $headers = []): array;

    /**
     * Concurrent GETs, chunked at the configured pool concurrency.
     *
     * @param  list<string>  $urls
     * @param  array<string, string>  $headers
     * @return array<string, array{status: int, body: string, headers: array<string, mixed>}|null>
     */
    public function getMany(array $urls, array $headers = []): array;

    /**
     * A billed non-HTTP effect (an actor run, an AI extraction). Wrapped in
     * the EffectLedger by the runtime, never by the connector.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function effect(string $kind, string $name, array $input): array;
}
