<?php

namespace App\Services\Notifications;

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

        Redis::zadd($key, $expiresAt, $member);
        // Evict already-expired members on each write.
        Redis::zremrangebyscore($key, 0, now()->timestamp);
        // Cap set size by removing oldest beyond MAX_MEMBERS.
        Redis::zremrangebyrank($key, 0, -1 - self::MAX_MEMBERS);
        Redis::expire($key, self::TTL_DAYS * 86400);
    }

    public function contains(string $userId, string $email): bool
    {
        $score = Redis::zscore($this->key($userId), $this->hash($email));

        return $score !== null && (int) $score >= now()->timestamp;
    }

    private function key(string $userId): string
    {
        return "enquiry_spam:{$userId}";
    }

    private function hash(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key'));
    }
}
