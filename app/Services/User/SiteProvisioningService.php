<?php

namespace App\Services\User;

use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

// V2: Provisions new professional sites with unique subdomains.
class SiteProvisioningService
{
    public function createSiteWithRetry(string $userId, string $base): Site
    {
        $reserved = array_map('strtolower', config('partna.reserved_subdomains', []));
        $base = strtolower($base);
        $baseIsReserved = in_array($base, $reserved, true);

        if ($baseIsReserved) {
            for ($i = 1; $i <= 20; $i++) {
                $candidate = $this->buildCandidate($base, (string) $i);
                $site = $this->tryCreateSite($userId, $candidate);
                if ($site) {
                    return $site;
                }
            }
        } else {
            for ($i = 0; $i < 20; $i++) {
                $suffix = $i === 0 ? null : (string) $i;
                $candidate = $this->buildCandidate($base, $suffix);
                $site = $this->tryCreateSite($userId, $candidate);
                if ($site) {
                    return $site;
                }
            }
        }

        for ($i = 0; $i < 10; $i++) {
            $rand = Str::lower(Str::random(6));
            $candidate = $this->buildCandidate($base, $rand);
            $site = $this->tryCreateSite($userId, $candidate);
            if ($site) {
                return $site;
            }
        }

        throw new RuntimeException('Could not allocate a unique subdomain.');
    }

    public function subdomainBaseFromHandle(string $handle): string
    {
        $v = mb_strtolower(trim($handle));
        $v = preg_replace('/[^a-z0-9]+/', '-', $v);
        $v = trim($v, '-');

        if ($v === '') {
            $v = 'user-'.substr(Str::uuid()->toString(), 0, 8);
        }

        return $v;
    }

    private function buildCandidate(string $base, ?string $suffix): string
    {
        if ($suffix === null) {
            return $base;
        }

        $base = $this->limitSubdomainBase($base, '-'.$suffix);

        return $base.'-'.$suffix;
    }

    private function limitSubdomainBase(string $base, string $suffixIncludingHyphen): string
    {
        $max = 63 - strlen($suffixIncludingHyphen);
        if ($max < 1) {
            return substr($base, 0, 1);
        }

        return substr($base, 0, $max);
    }

    private function tryCreateSite(string $userId, string $candidate): ?Site
    {
        try {
            // skeleton_id defaults to 'skeleton-1' at the DB level (TEXT CHECK
            // enum DEFAULT 'skeleton-1'). New sites pick up the default
            // automatically; no need to set it explicitly.
            $site = new Site([
                'subdomain' => $candidate,
                'is_published' => true,
                'settings' => [],
            ]);

            $site->user_id = $userId;
            $site->save();

            return $site;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505';
    }
}
