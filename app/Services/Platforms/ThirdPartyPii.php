<?php

namespace App\Services\Platforms;

/**
 * Nested third-party identity, stripped at the READ boundary.
 *
 * Both payload gates — PublicIntegrationConnectionResource::filterPayload() and
 * DsarPayloadFilter::filter() — are a top-level `array_intersect_key`. That
 * decides membership on FIRST-LEVEL keys only and copies each matched value
 * wholesale, nested contents included. So an allowlist is a privacy boundary
 * only at the depth it inspects, and an allowlisted parent key silently drags
 * whatever it nests onto the wire.
 *
 * `photos` is the live case (271-PRIV-2, nested leg): it is allowlisted on both
 * surfaces for legitimate reasons — the sitepage background image, and the
 * owner's Article 15 entitlement to their own listing photos — but each element
 * carries `authors`, a list of Google CONTRIBUTOR display names. Those are real
 * people who never signed up to Partna, so the names belong on neither surface:
 * not the public wire, and not an export whose whole premise is "data about
 * YOU". Nothing renders them (no frontend reads the key on either surface), and
 * no attribution obligation attaches — the Places terms require attribution on
 * DISPLAYED reviews and photos, and photo refs are not yet resolved to images.
 *
 * Keyed by parent key rather than by platform ON PURPOSE: the two producers
 * spell the surrounding payload differently (GoogleBusinessService camelCases,
 * Ingest\Connectors\GoogleBusinessConnector snake_cases) but both nest under
 * `photos` as `authors`, and a structural rule covers a future platform that
 * nests the same shape without anyone remembering to register it.
 */
final class ThirdPartyPii
{
    /**
     * Parent key => keys unset on every element beneath it.
     *
     * @var array<string, list<string>>
     */
    public const NESTED_KEYS = [
        'photos' => ['authors'],
    ];

    /**
     * Remove nested third-party identity from an already-allowlisted payload.
     *
     * Deliberately narrow: it only unsets the registered nested keys and never
     * touches the parent, so an owner's own photo (ref/dimensions/url) survives.
     * Over-stripping is a real failure mode here — a DSAR export that drops the
     * subject's own data breaches Article 15 just as under-stripping breaches a
     * third party.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function stripNested(array $payload): array
    {
        foreach (self::NESTED_KEYS as $parent => $keys) {
            if (! is_array($payload[$parent] ?? null)) {
                continue;
            }

            $payload[$parent] = array_map(function ($element) use ($keys) {
                if (! is_array($element)) {
                    return $element;
                }

                foreach ($keys as $key) {
                    unset($element[$key]);
                }

                return $element;
            }, $payload[$parent]);
        }

        return $payload;
    }
}
