<?php

namespace App\Services\Design;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Media\ImageVariantService;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The stored-candidate half of A.10's logo flow. On a sign-up business
 * build LogoAutoGrabber stores every slot-passing candidate here (bytes
 * mirrored to the media disk) instead of uploading the first passer; the
 * setup dialog's logo pass offers the rows and promote() turns the chosen
 * one into the real singleton via the standard upload pipeline. Bytes are
 * mirrored at store time because the source URL routinely rots between the
 * build and the person sitting down to set up.
 */
class LogoCandidates
{
    /** Enough choice without hoarding a brand's whole icon set. */
    public const MAX_PER_SLOT = 5;

    private const EXT_BY_MIME = [
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
        'image/gif' => 'gif', 'image/svg+xml' => 'svg',
    ];

    public function __construct(
        private readonly ImageVariantService $images,
        private readonly MediaUploadService $uploads,
    ) {}

    /** Proposed rows for one slot, most trusted first. @return list<object> */
    public function proposed(Site $site, string $slot): array
    {
        return DB::connection('pgsql')->table('site.logo_candidates')
            ->where('site_id', $site->id)
            ->where('slot', $slot)
            ->where('state', 'proposed')
            ->orderByDesc('trust')
            ->get()
            ->all();
    }

    /**
     * Mirror one passing candidate's bytes and record the row. Returns false
     * when the slot already holds MAX_PER_SLOT proposals (caller stops
     * collecting) or the mirror write fails.
     */
    public function store(Site $site, string $slot, ?string $sourceUrl, string $bytes, string $mime, int $trust, ?int $width, ?int $height): bool
    {
        $ext = self::EXT_BY_MIME[$mime] ?? null;
        if ($ext === null) {
            return false;
        }

        $existing = DB::connection('pgsql')->table('site.logo_candidates')
            ->where('site_id', $site->id)
            ->where('slot', $slot)
            ->where('state', 'proposed')
            ->count();
        if ($existing >= self::MAX_PER_SLOT) {
            return false;
        }

        $id = (string) Str::uuid();
        $path = "logo-candidates/{$site->id}/{$id}.{$ext}";
        if (! Storage::disk($this->images->resolvedDiskName())->put($path, $bytes, 'private')) {
            return false;
        }

        DB::connection('pgsql')->table('site.logo_candidates')->insert([
            'id' => $id,
            'site_id' => (string) $site->id,
            'slot' => $slot,
            'source_url' => $sourceUrl,
            'storage_path' => $path,
            'trust' => $trust,
            'width' => $width,
            'height' => $height,
            'state' => 'proposed',
            'created_at' => now(),
        ]);

        return true;
    }

    /**
     * Promote one proposed candidate to the slot's real singleton: mirrored
     * bytes → UploadedFile → uploadSingleton (the standard pipeline, WebP
     * variants and all). The promoted row is marked and its slot siblings
     * dismissed — the slot is decided; they are no longer offerable.
     * Returns false when the id is not a proposed candidate of this site.
     */
    public function promote(User $pro, Site $site, string $candidateId): bool
    {
        $row = DB::connection('pgsql')->table('site.logo_candidates')
            ->where('id', $candidateId)
            ->where('site_id', $site->id)
            ->where('state', 'proposed')
            ->first();
        if ($row === null) {
            return false;
        }

        $disk = Storage::disk($this->images->resolvedDiskName());
        $bytes = $disk->get((string) $row->storage_path);
        if ($bytes === null || $bytes === '') {
            Log::warning('logo_candidates.promote_bytes_missing', [
                'site_id' => (string) $site->id,
                'candidate_id' => $candidateId,
            ]);

            return false;
        }

        $ext = pathinfo((string) $row->storage_path, PATHINFO_EXTENSION);
        $mime = array_search($ext, self::EXT_BY_MIME, true) ?: 'image/png';

        $tmp = tempnam(sys_get_temp_dir(), 'partna_logocand_');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            return false;
        }

        try {
            $purpose = (string) $row->slot === 'square'
                ? SiteMedia::PURPOSE_LOGO_SQUARE
                : SiteMedia::PURPOSE_LOGO_FULL;
            $file = new UploadedFile($tmp, "logo-candidate.{$ext}", $mime, null, true);
            $this->uploads->uploadSingleton(pro: $pro, site: $site, file: $file, purpose: $purpose);
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        DB::connection('pgsql')->table('site.logo_candidates')
            ->where('id', $candidateId)
            ->update(['state' => 'promoted']);
        DB::connection('pgsql')->table('site.logo_candidates')
            ->where('site_id', $site->id)
            ->where('slot', (string) $row->slot)
            ->where('state', 'proposed')
            ->update(['state' => 'dismissed']);

        return true;
    }
}
