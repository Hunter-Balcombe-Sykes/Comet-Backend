<?php

namespace App\Services\Design;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Media\MediaDiskResolver;
use App\Services\Media\MediaUploadService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * T17 (owner, 2026-08-27 — D13): seed the `headshot` design singleton from
 * the Instagram profile picture at build time. The mirror image of
 * LogoAutoGrabber: that lane fills the BRAND slots and is gated ON
 * workplace_brand_is_site_identity; this one fills the person's own photo
 * and is gated OFF it — a business's identity is its logo, a partna's is
 * their face. Fill-empty only: a slot the owner (or an earlier build)
 * populated is never replaced. The source bytes are ALREADY OURS —
 * InstagramConnectionSeeder mirrored the IG picture to the media disk at
 * `{payload._folder}/profile.jpg` — so this reads the disk directly; no
 * network fetch, no SSRF surface.
 */
class HeadshotAutoSeeder
{
    private const ALLOWED_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly MediaUploadService $uploads,
    ) {}

    public function seedFromInstagram(User $user, Site $site): void
    {
        if (AccountCapabilities::for($user)->workplace_brand_is_site_identity) {
            return; // Brand-identity accounts wear their logo, not a headshot.
        }

        $occupied = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('usage', SiteMedia::USAGE_DESIGN)
            ->where('purpose', SiteMedia::PURPOSE_HEADSHOT)
            ->where('is_active', true)
            // Same occupancy convention as LogoAutoGrabber: a FAILED row never
            // finished processing, so its slot counts as empty.
            ->where('processing_state', '!=', SiteMedia::PROCESSING_STATE_FAILED)
            ->exists();
        if ($occupied) {
            return;
        }

        // ->first()?->payload (NOT ->value('payload')): value() bypasses the
        // model's array cast and would hand back the raw JSON string.
        $payload = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', 'instagram')
            ->first()?->payload;
        $folder = is_array($payload) ? trim((string) ($payload['_folder'] ?? '')) : '';
        $picUrl = is_array($payload) ? trim((string) ($payload['profilePicUrl'] ?? '')) : '';
        if ($folder === '' || $picUrl === '') {
            return; // No mirrored profile picture to seed from.
        }

        $disk = Storage::disk(MediaDiskResolver::resolve());
        $path = "{$folder}/profile.jpg";
        $bytes = $disk->exists($path) ? $disk->get($path) : null;
        if (! is_string($bytes) || $bytes === '') {
            return;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = self::ALLOWED_MIMES[$mime] ?? null;
        if ($ext === null) {
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'partna_headshot_');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            return;
        }

        try {
            $file = new UploadedFile($tmp, "headshot.{$ext}", $mime, null, true);
            $this->uploads->uploadSingleton(pro: $user, site: $site, file: $file, purpose: SiteMedia::PURPOSE_HEADSHOT);

            Log::info('pre_account.headshot_seeded', [
                'user_id' => (string) $user->id,
                'site_id' => (string) $site->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Benign lost race — another writer filled the slot between the
            // occupancy check and this insert.
        } catch (\Throwable $e) {
            report($e);
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
