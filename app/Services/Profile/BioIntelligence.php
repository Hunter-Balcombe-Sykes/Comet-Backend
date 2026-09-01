<?php

namespace App\Services\Profile;

use App\Services\Cache\AiSpendBudget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * T5/T13/T14/T16 (2026-08-27 unclaimed-signup quality plan, D6/D8/D10): ONE
 * model call over an Instagram identity (handle, fullName, biography) that
 * yields the clean display/first/last names, the stitched-their-words About
 * paragraph, any literal contact details, and the classified @mentions for
 * the workplace/brand chains.
 *
 * Runs on the SAME DeepSeek chat lane MenuAiExtractor::structure() already
 * uses (D6 named Mistral assuming the chat plumbing was Mistral's — it is
 * OCR-only; the configured, proven JSON-structuring lane is DeepSeek, and
 * the cost is equally negligible at ~500 tokens a call).
 *
 * EVERY model output passes a mechanical gate before it is believed:
 *  - names: each token must exist in the user's own handle/fullName (their
 *    words only — invention is structurally impossible), length-capped,
 *    emoji/URL-free;
 *  - about: their-words overlap check (content words must appear in the
 *    biography), emoji/URL-free, length-capped — no-About beats a bad About;
 *  - email/phone: must appear literally in the bio (validated shapes);
 *  - mentions: handle-shaped, deduped, capped.
 * A gate failure nulls THAT field, never the whole result. When the model is
 * unavailable the deterministic PersonNameParser answer stands unchanged.
 */
class BioIntelligence
{
    private const DEEPSEEK_URL = 'https://api.deepseek.com/beta/chat/completions';

    private const DEEPSEEK_MODEL = 'deepseek-chat';

    private const MAX_TOKENS = 1200;

    private const MAX_MENTIONS = 5;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You extract identity facts from an Instagram profile for a professional's public page. Reply ONLY with a JSON object:
{"display_name":str|null,"first_name":str|null,"last_name":str|null,"about":str|null,"email":str|null,"phone":str|null,"mentions":[{"handle":str,"label":str,"type":"workplace"|"brand"|"other"}]}

Rules — follow every one:
- display_name: the person's or act's clean public name. Derive it FIRST from the username/handle when the handle reads as a name (e.g. "simondoylehair" → "Simon Doyle"); otherwise clean the full name. Strip role descriptors (Barber, Hair, Music, Studio, Educator…), emojis and tagline segments around | • — and similar separators — but CHOOSE the segment that reads as a person's name, whichever side it is on: in "Melbourne Barber | Thorton" the name is Thorton. Use ONLY words present in the handle or full name — never invent.
- first_name/last_name: the human's real name parts, only when clearly identifiable. A role/descriptor word (Barber, Music, Hair…) is NEVER a surname. null when unsure. For a business/venue/band name leave both null.
- about: rewrite the biography's OWN fragments and facts into 1-4 consistent plain sentences. Keep their wording and voice — you are stitching, not authoring. Use every meaningful fact (location, specialty, role); drop emojis, hashtags, links, booking calls-to-action. If the biography has fewer than ~5 meaningful words, or is only links/CTAs, return null. NEVER add a fact that is not in the biography.
- email/phone: only if literally present in the biography text; else null.
- mentions: every @handle in the biography, with the exact surrounding label text and a type: "workplace" when the text says they own/work at/cut at it OR the handle itself is venue-shaped (studio/salon/barbers/shop in its name) — several mentions may all be "workplace"; nominate every plausible one rather than guessing a single winner; "brand" for ambassador/sponsor/team wording; else "other".
PROMPT;

    /** Role/category words that are never a person's name part. */
    private const DESCRIPTOR_WORDS = [
        'barber', 'barbers', 'hair', 'hairdresser', 'stylist', 'music', 'studio',
        'salon', 'shop', 'store', 'official', 'the', 'educator', 'coach', 'mentor',
    ];

    public function __construct(private readonly AiSpendBudget $budget) {}

    public function configured(): bool
    {
        return (string) config('services.deepseek.key') !== '';
    }

    /**
     * @return array{displayName: ?string, firstName: ?string, lastName: ?string,
     *               about: ?string, email: ?string, phone: ?string,
     *               mentions: list<array{handle: string, label: string, type: string}>,
     *               aiUsed: bool}
     */
    public function analyse(string $handle, ?string $fullName, ?string $biography, ?string $businessCategory = null): array
    {
        $empty = [
            'displayName' => null, 'firstName' => null, 'lastName' => null,
            'about' => null, 'email' => null, 'phone' => null,
            'mentions' => [], 'aiUsed' => false,
        ];

        if (! $this->configured() || ! $this->budget->tryClaim('deepseek_bio')) {
            return $empty;
        }

        $raw = $this->callModel($handle, $fullName, $biography, $businessCategory);
        if ($raw === null) {
            return $empty;
        }

        $ownWords = $this->tokenSet($handle.' '.($fullName ?? ''));

        [$displayName, $firstName, $lastName] = $this->gateNames($raw, $ownWords);

        return [
            'displayName' => $displayName,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'about' => $this->gateAbout(is_string($raw['about'] ?? null) ? $raw['about'] : null, (string) $biography),
            'email' => $this->gateEmail(is_string($raw['email'] ?? null) ? $raw['email'] : null, (string) $biography),
            'phone' => $this->gatePhone(is_string($raw['phone'] ?? null) ? $raw['phone'] : null, (string) $biography),
            'mentions' => $this->gateMentions(is_array($raw['mentions'] ?? null) ? $raw['mentions'] : [], (string) $biography),
            'aiUsed' => true,
        ];
    }

    /** @return array<string, mixed>|null */
    private function callModel(string $handle, ?string $fullName, ?string $biography, ?string $businessCategory): ?array
    {
        $input = json_encode([
            'username' => $handle,
            'full_name' => $fullName,
            'biography' => $biography,
            'business_category' => $businessCategory,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::withToken((string) config('services.deepseek.key'))
                ->timeout(60)
                ->post(self::DEEPSEEK_URL, [
                    'model' => self::DEEPSEEK_MODEL,
                    'max_tokens' => self::MAX_TOKENS,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        ['role' => 'user', 'content' => (string) $input],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning('bio_intelligence.threw', ['handle' => $handle, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('bio_intelligence.not_ok', ['handle' => $handle, 'status' => $response->status()]);

            return null;
        }

        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Names may only be assembled from the user's OWN words (handle +
     * fullName tokens) — the anti-invention gate — and a descriptor word is
     * never a surname.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, true>  $ownWords
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function gateNames(array $raw, array $ownWords): array
    {
        $display = $this->cleanShort($raw['display_name'] ?? null, 40);
        if ($display !== null) {
            foreach ($this->tokens($display) as $token) {
                if (mb_strlen($token) >= 3 && ! isset($ownWords[$token])) {
                    $display = null; // invented a word that is not theirs
                    break;
                }
            }
        }

        $first = $this->cleanShort($raw['first_name'] ?? null, 30);
        $last = $this->cleanShort($raw['last_name'] ?? null, 30);
        foreach (['first', 'last'] as $var) {
            $value = $$var;
            if ($value === null) {
                continue;
            }
            $token = mb_strtolower($value);
            if (str_contains($value, ' ')
                || in_array($token, self::DESCRIPTOR_WORDS, true)
                || (mb_strlen($token) >= 3 && ! isset($ownWords[$token]))) {
                $$var = null;
            }
        }
        // A surname without a first name is not a name.
        if ($first === null) {
            $last = null;
        }

        return [$display, $first, $last];
    }

    private function gateAbout(?string $about, string $biography): ?string
    {
        $about = $about !== null ? trim($about) : null;
        if ($about === null || $about === '') {
            return null;
        }
        if (mb_strlen($about) > 500
            || preg_match('~https?://|www\.~i', $about) === 1
            || preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $about) === 1) {
            return null;
        }

        // Their-words overlap: the about's content words must come from the
        // biography (or be trivial connectives) — the mechanical invention catch.
        $bioWords = $this->tokenSet($biography);
        $content = array_filter($this->tokens($about), static fn (string $t) => mb_strlen($t) >= 4);
        if ($content === []) {
            return null;
        }
        $found = count(array_filter($content, static fn (string $t) => isset($bioWords[$t])));
        if ($found / count($content) < 0.6) {
            return null;
        }

        return $about;
    }

    private function gateEmail(?string $email, string $biography): ?string
    {
        $email = $email !== null ? trim($email) : null;
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return stripos($biography, $email) !== false ? $email : null;
    }

    private function gatePhone(?string $phone, string $biography): ?string
    {
        $phone = $phone !== null ? trim($phone) : null;
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (mb_strlen($digits) < 8 || mb_strlen($digits) > 15) {
            return null;
        }
        $bioDigits = preg_replace('/\D+/', '', $biography) ?? '';

        return str_contains($bioDigits, $digits) ? $phone : null;
    }

    /**
     * @param  list<mixed>  $mentions
     * @return list<array{handle: string, label: string, type: string}>
     */
    private function gateMentions(array $mentions, string $biography): array
    {
        $out = [];
        $seen = [];
        foreach ($mentions as $mention) {
            if (! is_array($mention)) {
                continue;
            }
            $handle = mb_strtolower(ltrim(trim((string) ($mention['handle'] ?? '')), '@'));
            $type = (string) ($mention['type'] ?? 'other');
            if ($handle === '' || isset($seen[$handle])
                || preg_match('/^[a-z0-9._]{2,30}$/', $handle) !== 1
                || ! in_array($type, ['workplace', 'brand', 'other'], true)) {
                continue;
            }
            // The mention must actually be in the bio — no conjured handles.
            if (stripos($biography, $handle) === false) {
                continue;
            }
            $seen[$handle] = true;
            $out[] = [
                'handle' => $handle,
                'label' => mb_substr(trim((string) ($mention['label'] ?? '')), 0, 120),
                'type' => $type,
            ];
            if (count($out) >= self::MAX_MENTIONS) {
                break;
            }
        }

        return $out;
    }

    private function cleanShort(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max
            || preg_match('~https?://~i', $value) === 1
            || preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $value) === 1) {
            return null;
        }

        return $value;
    }

    /** @return list<string> lowercase word tokens */
    private function tokens(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        return array_values(array_filter($tokens, static fn (string $t) => $t !== ''));
    }

    /** @return array<string, true> */
    private function tokenSet(string $text): array
    {
        return array_fill_keys($this->tokens($text), true);
    }
}
