<?php

namespace App\Services\Profile;

/**
 * One BioIntelligence pass, already through the anti-invention gates.
 *
 * A typed carrier rather than the raw array because this result is THREADED
 * across object boundaries (generator -> InstagramConnectionSeeder ->
 * InstagramIdentitySync) to stop the same paid model call being made twice per
 * build. An array shape drifting silently across three hops is exactly the bug
 * that thread is there to prevent.
 */
final readonly class BioIntel
{
    /** @param list<array{handle: string, label: string, type: string}> $mentions */
    public function __construct(
        public ?string $displayName = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $about = null,
        public ?string $email = null,
        public ?string $phone = null,
        public array $mentions = [],
        public bool $aiUsed = false,
    ) {}

    /** @param array<string, mixed> $raw the analyse() return shape */
    public static function fromArray(array $raw): self
    {
        return new self(
            displayName: $raw['displayName'] ?? null,
            firstName: $raw['firstName'] ?? null,
            lastName: $raw['lastName'] ?? null,
            about: $raw['about'] ?? null,
            email: $raw['email'] ?? null,
            phone: $raw['phone'] ?? null,
            mentions: is_array($raw['mentions'] ?? null) ? array_values($raw['mentions']) : [],
            aiUsed: (bool) ($raw['aiUsed'] ?? false),
        );
    }

    /** The no-model-call result: every gate's failure mode, and the unconfigured case. */
    public static function empty(): self
    {
        return new self;
    }
}
