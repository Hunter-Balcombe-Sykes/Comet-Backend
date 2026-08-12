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
 * YOU". Nothing on THESE two surfaces renders them.
 *
 * ── Corrected by slice 1b (2026-08-12). Read this before widening or copying
 * the rule to a new surface. ──
 *
 * This class used to justify itself with "no attribution obligation attaches —
 * photo refs are not yet resolved to images". That is no longer true.
 * PlacesDetailsDriver now resolves each photo ref to a servable url inside the
 * same billed Details fetch, and the pool lane displays it. Wherever a Places
 * photo is DISPLAYED, the terms require the contributor credited — so the
 * credit travels with the photo on that lane, as
 * `content.media_assets.attribution` → `frames[]`.
 *
 * The two rules are not in conflict; they follow the two obligations, which
 * point in different directions:
 *
 *   - Public RENDER of a Google photo  → credit REQUIRED (Places terms).
 *     Carried on the pool lane, which does not pass through this class.
 *   - DSAR export                      → credit STRIPPED. Article 15 covers the
 *     subject's own data; a stranger's name is not the subject's data.
 *   - Legacy integration payload       → credit STRIPPED. Nothing renders it,
 *     so no obligation attaches and the original reasoning stands.
 *
 * So do NOT "resolve the inconsistency" by making these lanes agree. The
 * asymmetry is the decision.
 *
 * Keyed by parent key rather than by platform ON PURPOSE: a structural rule
 * covers a future platform that nests the same shape without anyone remembering
 * to register it. Note that as of slice 1b only GoogleBusinessService still
 * spells it `photos[].authors`; Ingest\Connectors\GoogleBusinessConnector now
 * emits a structured `attribution` block instead, and deliberately never
 * reaches either gate below — `streamContentSourceItems()` selects an explicit
 * column list with no doc column, and `content.media_assets` is not an export
 * section at all.
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
