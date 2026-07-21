<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One ordered pick in a site's "Content Selection" — up to 15 heterogeneous
// references the user chooses as sitepage background content. NOTHING is copied:
// each row points at media that is resolved live at read time.
//   entry_type='upload'       → media_id → a site.site_media row (pool='content')
//   entry_type='google-photo' → external_ref → a Google photo `ref` (stable name)
//   entry_type='ig-reel'      → latest Instagram reel  (resolved live; auto slot 1)
//   entry_type='ig-post'      → latest Instagram post  (resolved live; auto slot 2)
// The DB CHECK content_selection_ref_shape enforces which of media_id/external_ref
// is set per entry_type; UNIQUE(site_id, position) enforces 1..15 ordering.
// Ownership is gated in ContentSelectionPolicy (RLS is off), resolved via site.
class ContentSelection extends BaseModel
{
    use HasUuids;

    protected $table = 'site.content_selection';

    public $incrementing = false;

    protected $keyType = 'string';

    // Entry-type discriminator values — mirror the DB CHECK constraint.
    public const TYPE_UPLOAD = 'upload';

    public const TYPE_GOOGLE_PHOTO = 'google-photo';

    public const TYPE_IG_REEL = 'ig-reel';

    public const TYPE_IG_POST = 'ig-post';

    // The Instagram auto-slot entry types (the two reserved when auto is enabled).
    public const IG_TYPES = [self::TYPE_IG_REEL, self::TYPE_IG_POST];

    public const ALL_TYPES = [
        self::TYPE_UPLOAD,
        self::TYPE_GOOGLE_PHOTO,
        self::TYPE_IG_REEL,
        self::TYPE_IG_POST,
    ];

    // Hard cap on the selection size (DB CHECK position 1..15).
    public const MAX_POSITION = 15;

    // site_id is the tenancy FK (NOT NULL, no default) — excluded from
    // mass-assignment. The sole writer, ContentSelectionService::persist(),
    // uses forceCreate() to set it from the server-resolved Site, never from
    // client input.
    protected $fillable = [
        'position',
        'entry_type',
        'media_id',
        'external_ref',
    ];

    protected $casts = [
        'position' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(SiteMedia::class, 'media_id');
    }
}
