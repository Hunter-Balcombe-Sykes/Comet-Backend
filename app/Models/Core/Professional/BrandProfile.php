<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use App\Services\Professional\Brand\BrandSignupCodeService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Brand business details (ABN, industries, affiliate visibility). brand_status gates activation in V2.
class BrandProfile extends BaseModel
{
    use HasUuids;

    protected static function booted(): void
    {
        // Auto-generate a unique signup code for every new BrandProfile so that all
        // brands have a sharable partner-onboarding code from day one.
        // NOTE: this hook does NOT fire on createQuietly() — that is intentional.
        // The Artisan backfill command (brand:backfill-signup-codes) assigns codes
        // to existing rows by calling generate() explicitly then saveQuietly().
        static::creating(static function (BrandProfile $model): void {
            if ($model->signup_code === null) {
                $model->signup_code = app(BrandSignupCodeService::class)->generate();
            }
        });
    }

    protected $table = 'brand.brand_profiles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'abn',
        'acn',
        'legal_business_name',
        'business_type',
        'industries',
        'estimated_annual_income',
        'business_website',
        'affiliate_visibility',
        'brand_status',
        'setup_complete',
        'signup_code',
        'signup_code_active',
        'signup_code_rotated_at',
    ];

    protected $casts = [
        'industries' => 'array',
        'setup_complete' => 'boolean',
        'signup_code_active' => 'boolean',
        'signup_code_rotated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    /**
     * First-is-primary convention: the first non-empty string entry in
     * industries is the primary. Convenience for brand-side callers that
     * already have a BrandProfile loaded; affiliate-side code should use
     * Professional::primaryIndustry() instead.
     */
    public function primaryIndustry(): ?string
    {
        $industries = is_array($this->industries) ? $this->industries : [];

        foreach ($industries as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
