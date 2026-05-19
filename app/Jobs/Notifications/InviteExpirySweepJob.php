<?php

namespace App\Jobs\Notifications;

use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Marks expired affiliate invites and publishes expiry notifications to brand managers.
// Scheduled: daily at 08:00 UTC via routes/console.php.
class InviteExpirySweepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Surface deterministic failures fast — fail after 2 consecutive throws
    // instead of burning the full backoff window before Horizon alerts.
    public int $maxExceptions = 2;

    public int $backoff = 60;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(NotificationPublisher $publisher): void
    {
        $now = now();

        DB::table('brand.brand_affiliate_invites')
            ->where('status', 'pending')
            ->where('expires_at', '<', $now)
            ->select(['id', 'brand_professional_id', 'email', 'first_name'])
            ->chunkById(500, function ($chunk) use ($publisher, $now) {
                $ids = $chunk->pluck('id')->all();

                // Bulk update reduces N×UPDATE round-trips to one per chunk;
                // status='pending' still guards against concurrent expiry.
                DB::table('brand.brand_affiliate_invites')
                    ->whereIn('id', $ids)
                    ->where('status', 'pending')
                    ->update(['status' => 'expired', 'updated_at' => $now]);

                foreach ($chunk as $invite) {
                    try {
                        $brandId = trim((string) ($invite->brand_professional_id ?? ''));
                        if ($brandId === '') {
                            continue;
                        }

                        $label = trim((string) ($invite->first_name ?? ''));
                        if ($label === '') {
                            $label = trim((string) ($invite->email ?? ''));
                        }
                        if ($label === '') {
                            $label = 'An invitee';
                        }

                        // No capability gate: §9's `receives_invite_notifications`
                        // describes the *invitee* side ("you got invited"), but this
                        // job notifies the *sender* (brand) that an invite they sent
                        // has expired — a brand-management notification. Brand-only
                        // semantics are inherent in the data (only brands create
                        // affiliate invites) and brand is a terminal account_type,
                        // so a defence-in-depth gate is structurally unnecessary.

                        $publisher->publish(
                            professionalId: $brandId,
                            frontendType: 'Warning',
                            category: 'invites',
                            title: 'Invite expired',
                            body: "Your invite to {$label} has expired.",
                            dedupeKey: "invite.expired.{$invite->id}",
                            ctaUrl: '/account/affiliates',
                            retentionConfigKey: 'invite',
                        );
                    } catch (\Throwable $e) {
                        Log::warning('InviteExpirySweepJob failed for invite', [
                            'invite_id' => $invite->id,
                            'brand_professional_id' => $brandId,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    public function failed(\Throwable $e): void
    {
        // Sweep is brand-agnostic (iterates every pending invite) so there is no
        // single tenant to attach — instead, attach the sweep date + job name so
        // a Nightwatch alert can be cross-referenced against the database state
        // at the moment of failure.
        report($e);
        Log::error('Invite expiry sweep job failed', [
            'job' => 'InviteExpirySweepJob',
            'sweep_date' => now()->toDateString(),
            'message' => $e->getMessage(),
        ]);
    }
}
