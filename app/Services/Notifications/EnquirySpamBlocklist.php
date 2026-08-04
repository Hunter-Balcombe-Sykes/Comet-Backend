<?php

namespace App\Services\Notifications;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

class EnquirySpamBlocklist
{
    private const TTL_DAYS = 90;

    private const MAX_MEMBERS = 500;

    public function add(string $userId, string $email): void
    {
        $expiresAt = now()->addDays(self::TTL_DAYS)->timestamp;
        $this->addWithExpiry($userId, $email, $expiresAt);
    }

    public function addWithExpiry(string $userId, string $email, int $expiresAt): void
    {
        $key = $this->key($userId);
        $member = $this->hash($email);

        $this->redis()->zadd($key, $expiresAt, $member);
        // Evict already-expired members on each write.
        $this->redis()->zremrangebyscore($key, 0, now()->timestamp);
        // Cap set size by removing oldest beyond MAX_MEMBERS.
        $this->redis()->zremrangebyrank($key, 0, -1 - self::MAX_MEMBERS);
        $this->redis()->expire($key, self::TTL_DAYS * 86400);
    }

    public function contains(string $userId, string $email): bool
    {
        $score = $this->redis()->zscore($this->key($userId), $this->hash($email));

        // phpredis ZSCORE returns false (not null) when the member is absent;
        // guard on false so a missing entry short-circuits before the cast.
        return $score !== false && (int) $score >= now()->timestamp;
    }

    private function key(string $userId): string
    {
        return "enquiry_spam:{$userId}";
    }

    private function hash(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key'));
    }

    /**
     * Reached from PublicEnquiryController::store() and
     * UserEnquiryController::report() — both request path, no blocking
     * command. `app`, not the bare facade default, so it takes the 3.0s bound
     * instead of `default`'s 15.0s (reserved for queue workers' BLPOP). See
     * drill 03 (2026-08-05).
     */
    private function redis(): Connection
    {
        return Redis::connection('app');
    }
}
