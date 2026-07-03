<?php

namespace App\Http\Resources;

use App\Models\Core\Site\Workplace;
use Illuminate\Http\Request;

/**
 * The workplace-card shape returned by UserWorkplaceController's show/upsert.
 *
 * `previous_website_analysis` (WebsiteStyleAnalyzer output, system-written —
 * see Workplace model) is deliberately excluded: it is internal brand-signal
 * detail, not part of the public workplace-card contract.
 */
class WorkplaceResource extends ApiResource
{
    /**
     * @return array{name: string, address: ?string, address_line1: ?string, city: ?string, state: ?string, postcode: ?string, country: ?string, latitude: ?float, longitude: ?float, phone: ?string, website: ?string, previous_website: ?string, category: ?string, description: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => self::trimOrNull($this->name),
            'address' => self::trimOrNull($this->address),
            'address_line1' => self::trimOrNull($this->address_line1),
            'city' => self::trimOrNull($this->city),
            'state' => self::trimOrNull($this->state),
            'postcode' => self::trimOrNull($this->postcode),
            'country' => self::trimOrNull($this->country),
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'phone' => self::trimOrNull($this->phone),
            'website' => self::trimOrNull($this->website),
            'previous_website' => self::trimOrNull($this->previous_website),
            'category' => self::trimOrNull($this->category),
            'description' => self::trimOrNull($this->description),
        ];
    }

    /**
     * Build the resource, or null when the row has no identity yet — a
     * nameless workplace (blank name after trimming, or no row at all) is
     * not a live card. Callers pass the result straight into `success()`.
     */
    public static function forWorkplace(?Workplace $workplace): ?self
    {
        if (! $workplace || self::trimOrNull($workplace->name) === null) {
            return null;
        }

        return new self($workplace);
    }

    private static function trimOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
