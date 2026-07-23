<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// V2: Base form request with shared input sanitization helpers — trim, lowercase, and email normalization.
abstract class BaseFormRequest extends FormRequest
{
    /**
     * Authorization is enforced entirely by route middleware (VerifySupabaseJwt,
     * LoadCurrentUser, staff guards) and resource Policies invoked from
     * controllers via `authorizeForUser($pro, ...)`. This method is intentionally
     * `final` — Supabase JWT means `Auth::user()` is always null, so any logic
     * here would gate against a null user and create a second, inconsistent
     * authorization surface. Do NOT override.
     */
    final public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim string inputs.
     */
    protected function trimStrings(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $data[$key] = trim($value);
            }
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Lowercase string inputs.
     */
    protected function lowercaseStrings(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $data[$key] = strtolower(trim($value));
            }
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Sanitize email inputs (trim and lowercase).
     */
    protected function sanitizeEmails(array $keys): void
    {
        $this->lowercaseStrings($keys);
    }

    /**
     * Canonical phone validation rule — shared `string + max` constraints.
     * Callers prepend required/nullable/sometimes for their own semantics:
     *   'phone' => ['required', ...$this->phoneRule()]
     */
    protected function phoneRule(): array
    {
        return ['string', 'max:50'];
    }

    /**
     * Normalize phone-like inputs to digits and a leading `+` only.
     * Strips whitespace, parens, dashes, and any other punctuation. Empty
     * results coerce to null so downstream code never has to distinguish
     * '' from null. Skips keys that are absent or not strings.
     */
    protected function normalizePhones(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = $this->input($key);
            if (! is_string($value)) {
                continue;
            }
            $normalized = preg_replace('/[^\d+]/', '', trim($value));
            $data[$key] = $normalized === '' ? null : $normalized;
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Value-level twin of `cleanText`. Strips HTML tags, ASCII control chars,
     * and surrounding whitespace from a single string; returns null for empty
     * results AND for non-string input. Lifted out of cleanText so request
     * classes and nested-array loops (settings.*) can share the exact same
     * transform without re-implementing it.
     */
    protected static function cleanString(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        // strip_tags() leaves content inside <script>/<style> blocks — remove those blocks entirely first.
        $noScripts = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $value) ?? $value;
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', strip_tags($noScripts));
        $cleaned = trim((string) $stripped);

        return $cleaned === '' ? null : $cleaned;
    }

    /**
     * Clean user-authored text inputs: strip HTML tags, ASCII control chars,
     * and surrounding whitespace; coerce empty results to null. Defense-in-depth
     * against stored XSS for fields that are echoed by the frontend. Skips keys
     * that are absent or not strings.
     */
    protected function cleanText(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = $this->input($key);
            if (! is_string($value)) {
                continue;
            }
            $data[$key] = static::cleanString($value);
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Closure rule that rejects redirect URLs pointing outside the app's own
     * origin allow-list (frontend, backend, localhost). Use on any `success_url`,
     * `cancel_url`, `return_url`, or `refresh_url` field whose value is later
     * forwarded to a third-party hosted flow (Stripe Checkout, Connect Express,
     * SetupIntent return). Open redirects via a freshly completed Stripe page
     * are unusually convincing social-engineering targets — the user is in a
     * high-trust visual state and a bounce to attacker.example.com looks
     * indistinguishable from the real return.
     *
     * Empty / null values short-circuit so callers can stack this after
     * `nullable` / `required_unless` without forcing presence.
     */
    protected function allowedRedirectRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (! $value) {
                return;
            }

            $host = parse_url($value, PHP_URL_HOST);
            $frontendHost = parse_url(config('app.frontend_url', ''), PHP_URL_HOST);
            $appHost = parse_url(config('app.url', ''), PHP_URL_HOST);

            $allowed = array_filter([$frontendHost, $appHost, 'localhost', '127.0.0.1']);

            if (! $host || ! in_array($host, $allowed, true)) {
                $fail('The redirect URL domain is not allowed.');
            }
        };
    }

    /**
     * Lowercase email-like inputs and coerce empty strings to null. Use this
     * for fields backed by a unique index where the difference between '' and
     * null matters (e.g. professionals.primary_email). For looser semantics
     * use `sanitizeEmails`.
     */
    protected function lowercaseEmails(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = $this->input($key);
            if (! is_string($value)) {
                continue;
            }
            $normalized = mb_strtolower(trim($value));
            $data[$key] = ($normalized === '') ? null : $normalized;
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Upper-case ISO 3166-1 alpha-2 country_code-like inputs so the format
     * validator accepts lower-case input from older clients. Empty strings
     * coerce to null. Skips keys that are absent or not strings.
     */
    protected function normalizeCountryCode(array $keys): void
    {
        $data = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = $this->input($key);
            if (! is_string($value)) {
                continue;
            }
            $normalized = strtoupper(trim($value));
            $data[$key] = $normalized === '' ? null : $normalized;
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }
}
