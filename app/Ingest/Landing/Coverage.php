<?php

namespace App\Ingest\Landing;

/**
 * What a run can honestly claim to have seen. This is the entire basis for
 * ever deleting anything (C5), so its cases are deliberately few and their
 * meanings narrow.
 *
 * `dominates($key, $orderValue)` answers one question: "if this key were
 * still there, would I have seen it?" Only a yes makes absence meaningful.
 */
abstract readonly class Coverage
{
    abstract public function dominates(string $key, mixed $orderValue): bool;

    /** @return array<string, mixed> */
    abstract public function toArray(): array;

    public static function exhaustive(): self
    {
        return new ExhaustiveCoverage;
    }

    public static function unknown(): self
    {
        return new UnknownCoverage;
    }

    /** Everything ordered at or after $from was seen (a reverse-chron prefix). */
    public static function prefix(mixed $from, int $count): self
    {
        return new PrefixCoverage($from, $count);
    }

    /** Everything whose order value falls inside [$from, $to] was seen. */
    public static function window(mixed $from, mixed $to): self
    {
        return new WindowCoverage($from, $to);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return match ($data['type'] ?? 'unknown') {
            'exhaustive' => self::exhaustive(),
            'prefix' => self::prefix($data['from'] ?? null, (int) ($data['count'] ?? 0)),
            'window' => self::window($data['from'] ?? null, $data['to'] ?? null),
            default => self::unknown(),
        };
    }
}

/** The whole set was enumerated. Any key not seen is genuinely gone. */
final readonly class ExhaustiveCoverage extends Coverage
{
    public function dominates(string $key, mixed $orderValue): bool
    {
        return true;
    }

    public function toArray(): array
    {
        return ['type' => 'exhaustive'];
    }
}

/**
 * We cannot say what we saw. NOTHING is ever dominated, so nothing is ever
 * deleted — the safe default, and what a stream with no order field always
 * emits.
 */
final readonly class UnknownCoverage extends Coverage
{
    public function dominates(string $key, mixed $orderValue): bool
    {
        return false;
    }

    public function toArray(): array
    {
        return ['type' => 'unknown'];
    }
}

/**
 * A reverse-chronological prefix: everything ordered >= $from was seen. A key
 * ordered inside that range but absent is genuinely gone; one ordered older
 * is simply beyond what we fetched.
 */
final readonly class PrefixCoverage extends Coverage
{
    public function __construct(public mixed $from, public int $count) {}

    public function dominates(string $key, mixed $orderValue): bool
    {
        if ($orderValue === null || $this->from === null) {
            return false;
        }

        return $this->compare($orderValue, $this->from) >= 0;
    }

    private function compare(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) {
            return $a <=> $b;
        }

        return strcmp((string) $a, (string) $b);
    }

    public function toArray(): array
    {
        return ['type' => 'prefix', 'from' => $this->from, 'count' => $this->count];
    }
}

/** A bounded window (a calendar month, a page range). */
final readonly class WindowCoverage extends Coverage
{
    public function __construct(public mixed $from, public mixed $to) {}

    public function dominates(string $key, mixed $orderValue): bool
    {
        if ($orderValue === null || $this->from === null || $this->to === null) {
            return false;
        }
        $v = (string) $orderValue;

        return strcmp($v, (string) $this->from) >= 0 && strcmp($v, (string) $this->to) <= 0;
    }

    public function toArray(): array
    {
        return ['type' => 'window', 'from' => $this->from, 'to' => $this->to];
    }
}
