<?php

namespace Tests\Authz;

/**
 * One executable case: a single HTTP method against a single URI pattern, with
 * each {param} resolved to a model FQCN where reflection could manage it.
 *
 * A null in $params means UNRESOLVED, not "not a resource". Unresolved params
 * are the ones whose controller fetches the row by hand, which is precisely
 * where an ownership clause gets forgotten — they must be mapped or exempted,
 * never dropped.
 */
final class RouteCase
{
    /**
     * @param  array<string, string|null>  $params  param name => model FQCN, or null when unresolved
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly string $action,
        public readonly array $params,
    ) {}

    public function key(): string
    {
        return $this->method.' '.$this->uri;
    }

    /** Pattern key without the method — how expectations.yaml addresses a route. */
    public function pattern(): string
    {
        return $this->uri;
    }

    public function group(): string
    {
        return match (true) {
            str_starts_with($this->uri, 'api/staff') => 'staff',
            str_starts_with($this->uri, 'api/public') => 'public',
            str_starts_with($this->uri, 'api/platforms') => 'platforms',
            default => 'user',
        };
    }

    public function hasParams(): bool
    {
        return $this->params !== [];
    }

    /** @return array<int, string> */
    public function unresolvedParams(): array
    {
        return array_keys(array_filter($this->params, static fn ($model) => $model === null));
    }
}
