<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use Closure;

// Validates a workplace upsert. One shape covers both flows:
//   - Google Places autofill — visitor picks a result in the dashboard; the
//     name + structured address + phone + website all land in one go.
//   - Manual entry           — visitor types whatever fields they want by hand.
// The stored record is identical either way — there's no `source` flag.
class UpsertWorkplaceRequest extends BaseFormRequest
{
    // #W2-SEC-7: the only keys IdentitySync::deriveOpeningHours() (Google
    // sync) or the workplace editor (manual entry) ever write — 3-letter
    // weekday slugs plus 'exceptions', matching Workplace::$opening_hours'
    // documented shape.
    private const OPENING_HOURS_DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    // Split-shift businesses (lunch + dinner service, etc.) need more than
    // one entry per day — generous bound, not a real-world constraint.
    private const OPENING_HOURS_MAX_ENTRIES_PER_DAY = 8;

    // 'exceptions' has no writer yet (see Workplace docblock) so its inner
    // shape isn't asserted — only a size cap, same reasoning as every other
    // "accept but bound" list in this codebase.
    private const OPENING_HOURS_MAX_EXCEPTIONS = 50;

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach ([
            'name', 'phone', 'website', 'contact_email',
            // Previous/old website (archive) + business category + editorial
            // description — also auto-filled from Google Business when empty.
            'previous_website', 'category', 'description',
            // Structured address components — populated either by Google
            // Places (parseAddressParts in /api/google/places/search) or
            // by manual entry in the workplace editor.
            'address_line1', 'city', 'state', 'postcode', 'country',
        ] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $value = trim($value);
                $trimmed[$key] = $value !== '' ? $value : null;
            }
        }

        $this->merge($trimmed);
    }

    public function rules(): array
    {
        return [
            // Business/workplace names cap at 80 chars — a sanity bound, not a
            // layout rule; display truncation is render-side (owner, 2026-08-27,
            // issue 10). Manual entry over the bound is rejected outright;
            // auto-adopted names are silently word-trimmed instead, see
            // App\Support\BusinessName::wordTrim.
            'name' => ['required', 'string', 'max:80'],
            // Structured fields are the only source of truth — no separate
            // freeform "display address" string is stored.
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postcode' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', ...$this->phoneRule()],
            'website' => ['nullable', 'url', 'max:2048'],
            'previous_website' => ['nullable', 'url', 'max:2048'],
            'category' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            // Public contact email — manual-only (Google Places never returns one).
            'contact_email' => ['nullable', 'email', 'max:255'],
            // #W2-SEC-7: structured per-day opening hours — keys, per-day entry
            // count, and each entry's {open,close} HHMM shape are all enforced
            // by openingHoursShapeRule() below (a single closure, not per-key
            // wildcard rules, because 'exceptions' is a sibling key that does
            // NOT share the {open,close} shape the weekday keys do).
            'opening_hours' => ['nullable', 'array', $this->openingHoursShapeRule()],
        ];
    }

    /** @return Closure(string, mixed, Closure): void */
    private function openingHoursShapeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return; // the sibling 'array' rule already reports this
            }

            foreach (array_keys($value) as $key) {
                if (! in_array($key, self::OPENING_HOURS_DAY_KEYS, true) && $key !== 'exceptions') {
                    $fail("The opening_hours key [{$key}] is not a recognized weekday.");

                    return;
                }
            }

            if (array_key_exists('exceptions', $value)) {
                $exceptions = $value['exceptions'];
                if (! is_array($exceptions) || count($exceptions) > self::OPENING_HOURS_MAX_EXCEPTIONS) {
                    $fail('opening_hours.exceptions must be a list of at most '.self::OPENING_HOURS_MAX_EXCEPTIONS.' entries.');

                    return;
                }
            }

            foreach (self::OPENING_HOURS_DAY_KEYS as $day) {
                if (! array_key_exists($day, $value)) {
                    continue;
                }

                $entries = $value[$day];
                if (! is_array($entries) || count($entries) > self::OPENING_HOURS_MAX_ENTRIES_PER_DAY) {
                    $fail("opening_hours.{$day} must be a list of at most ".self::OPENING_HOURS_MAX_ENTRIES_PER_DAY.' entries.');

                    return;
                }

                foreach ($entries as $entry) {
                    $valid = is_array($entry)
                        && array_diff(array_keys($entry), ['open', 'close']) === []
                        && isset($entry['open'], $entry['close'])
                        && preg_match('/^([01]\d|2[0-3])[0-5]\d$/', (string) $entry['open']) === 1
                        && preg_match('/^([01]\d|2[0-3])[0-5]\d$/', (string) $entry['close']) === 1;

                    if (! $valid) {
                        $fail("opening_hours.{$day} entries must be {open, close} HHMM strings (e.g. {\"open\":\"0900\",\"close\":\"1700\"}).");

                        return;
                    }
                }
            }
        };
    }
}
