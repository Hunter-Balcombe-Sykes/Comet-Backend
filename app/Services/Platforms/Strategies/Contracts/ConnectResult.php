<?php

namespace App\Services\Platforms\Strategies\Contracts;

// Outcome of a platform's connect resolution. Two-stage failures (parse vs
// fetch) carry their own message + HTTP status; a null message falls back to
// the descriptor's connectErrorMessage (the parse-fail wording each platform
// froze into its API contract). accountKey is the canonical per-account
// identity for multi-account platforms whose key is not derivable from the
// selection's handle/input/url/link chain (e.g. Vimeo's apiPath).
final readonly class ConnectResult
{
    private function __construct(
        public ?array $selection,
        public ?string $accountKey,
        public ?string $error,
        public int $status,
    ) {}

    public static function ok(array $selection, ?string $accountKey = null): self
    {
        return new self($selection, $accountKey, null, 200);
    }

    public static function fail(?string $message = null, int $status = 422): self
    {
        return new self(null, null, $message, $status);
    }

    public function failed(): bool
    {
        return $this->selection === null;
    }
}
