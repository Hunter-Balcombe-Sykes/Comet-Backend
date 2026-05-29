<?php

namespace App\Console\Commands\Moderation;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ModerationAuditQuarantineBucketCommand extends Command
{
    protected $signature = 'moderation:audit-quarantine-bucket';
    protected $description = 'Verify that the R2 quarantine bucket has no public access policy.';

    public function handle(): int
    {
        $accountId = config('services.cloudflare.account_id');
        $apiToken  = config('services.cloudflare.api_token');
        $bucket    = config('partna.moderation.csam.quarantine_bucket', 'partna-media-quarantine');

        $res = Http::withToken($apiToken)
            ->timeout(10)
            ->get("https://api.cloudflare.com/client/v4/accounts/{$accountId}/r2/buckets/{$bucket}");

        if (! $res->successful()) {
            $this->error("Bucket inspection failed: {$res->status()}");
            return self::FAILURE;
        }

        $publicAccess = $res->json('result.public_access', false);
        $corsOrigins  = $res->json('result.cors_origins', []);

        if ($publicAccess === true || ! empty($corsOrigins)) {
            Log::error('moderation.quarantine_bucket.public_drift', [
                'bucket'        => $bucket,
                'public_access' => $publicAccess,
                'cors_origins'  => $corsOrigins,
            ]);
            $this->error('CRITICAL: quarantine bucket has public access drift.');
            return self::FAILURE;
        }

        $this->info("OK: quarantine bucket {$bucket} is private.");
        return self::SUCCESS;
    }
}
