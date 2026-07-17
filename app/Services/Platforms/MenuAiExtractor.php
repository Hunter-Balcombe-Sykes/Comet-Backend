<?php

namespace App\Services\Platforms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// The two-stage AI menu pipeline, server-side — the same anatomy as the
// frontend's /api/menu-scan route (Task FE11), ported for the automatic
// Google-photos scan (GoogleMenuPhotoScanJob):
//   1. Mistral hosted OCR (POST /v1/ocr) turns a PUBLIC image URL into
//      markdown text. Real OCR — a dish photo yields ~nothing, a menu-board
//      photo yields hundreds of characters, which is exactly the signal the
//      job's density filter keys on.
//   2. DeepSeek (beta endpoint for the 8K max_tokens ceiling — same
//      rationale as the frontend route) structures the combined text into
//      menu items. This port ALSO extracts dietary markers (GF / V / VG …)
//      so the applier can badge matched items — the frontend route doesn't,
//      yet; keep the shapes compatible if it grows them.
//
// Keys: config('services.mistral.key') + config('services.deepseek.key').
// Both absent → configured() false and the job exits quietly (same
// "not configured" contract as the frontend route's 503).
//
// Privacy/logging: image URLs are public Google media links; API keys and
// response payloads are never logged — status codes only.
class MenuAiExtractor
{
    private const MISTRAL_OCR_URL = 'https://api.mistral.ai/v1/ocr';

    private const MISTRAL_MODEL = 'mistral-ocr-latest';

    private const DEEPSEEK_URL = 'https://api.deepseek.com/beta/chat/completions';

    private const DEEPSEEK_MODEL = 'deepseek-chat';

    private const DEEPSEEK_MAX_TOKENS = 8192;

    private const MAX_ITEMS = 180;

    private const NAME_MAX = 160;

    private const PRICE_MAX = 100000;

    private const MAX_OCR_TEXT_CHARS = 60000;

    /** Canonical dietary badge labels the structuring prompt may emit. */
    private const DIETARY_LABELS = [
        'Gluten free', 'Vegetarian', 'Vegan', 'Dairy free', 'Nut free', 'Halal', 'Spicy',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You extract menu items from OCR-extracted text of a restaurant menu (photos that have already been run through an OCR service and converted to text/markdown).

Respond with ONLY a strict JSON object — no markdown, no code fences, no commentary, compact one-line output — in exactly this shape:
{"items":[{"name":"string","description":"string or null","price":number or null,"category":"string or null","dietary":["string"] or null}]}

The text may contain OCR noise: misread characters, broken or joined words, inconsistent spacing or capitalization, multi-column layouts flattened out of reading order, and repeated page furniture (restaurant name/address/phone, headers, footers, page numbers, decorative text). Use judgment to reconstruct the actual items and ignore anything that isn't a dish, drink, or product.

Rules:
- One entry per distinct dish, drink, or product on the menu.
- name: the item's name as best read, at most 160 characters. Skip anything without a readable name.
- description: the item's printed description, or null when it has none.
- price: the numeric amount only — no currency symbols or text — or null when absent or unreadable. When an item lists multiple sizes or options, use the lowest printed price.
- category: the menu section heading the item appears under (e.g. "Starters", "Mains", "Drinks"), or null when there is none.
- dietary: the item's printed dietary markers, normalized to this exact vocabulary: "Gluten free" (gf/gfo), "Vegetarian" (v), "Vegan" (vg/vgo/pb), "Dairy free" (df/dfo), "Nut free" (nf), "Halal", "Spicy" (chilli marks). Use null when none are printed. Never guess from ingredients.
- Keep the menu's own order as best you can tell from the text. Include at most 180 items.
- If the text is not from a menu, or nothing readable resembles menu items, return {"items":[]}.
PROMPT;

    public function configured(): bool
    {
        return (string) config('services.mistral.key') !== ''
            && (string) config('services.deepseek.key') !== '';
    }

    /**
     * OCR one publicly-reachable image URL to markdown text.
     * Null = hard failure; '' = OCR ran but read nothing (not a menu photo).
     */
    public function ocrImageUrl(string $imageUrl, ?string $userId = null): ?string
    {
        try {
            $response = Http::withToken((string) config('services.mistral.key'))
                ->timeout(60)
                ->post(self::MISTRAL_OCR_URL, [
                    'model' => self::MISTRAL_MODEL,
                    'document' => ['type' => 'image_url', 'image_url' => $imageUrl],
                    'table_format' => 'markdown',
                ]);
        } catch (Throwable $e) {
            Log::warning('menu_ai.ocr.threw', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('menu_ai.ocr.not_ok', ['user_id' => $userId, 'status' => $response->status()]);

            return null;
        }

        $pages = $response->json('pages');
        if (! is_array($pages)) {
            return '';
        }

        $text = implode("\n\n", array_filter(array_map(
            static fn ($page) => is_array($page) && is_string($page['markdown'] ?? null) ? $page['markdown'] : '',
            $pages,
        )));

        return mb_substr($text, 0, self::MAX_OCR_TEXT_CHARS);
    }

    /**
     * Structure OCR text into validated menu items.
     * Null = upstream failure; [] = parsed fine, nothing menu-like found.
     *
     * @return list<array{name:string, description:?string, price:?float, category:?string, dietary:?list<string>}>|null
     */
    public function structure(string $text, ?string $userId = null): ?array
    {
        try {
            $response = Http::withToken((string) config('services.deepseek.key'))
                ->timeout(90)
                ->post(self::DEEPSEEK_URL, [
                    'model' => self::DEEPSEEK_MODEL,
                    'max_tokens' => self::DEEPSEEK_MAX_TOKENS,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        ['role' => 'user', 'content' => "Extract every menu item from this OCR-extracted text:\n\n".$text],
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning('menu_ai.structure.threw', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('menu_ai.structure.not_ok', ['user_id' => $userId, 'status' => $response->status()]);

            return null;
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content)) {
            return null;
        }

        return $this->parseItems($content);
    }

    /**
     * Model output → validated items. Direct parse first, then the
     * truncation repair (max_tokens can cut a dense menu mid-array — trim to
     * the last complete item and close the JSON), then give up to [].
     *
     * @return list<array{name:string, description:?string, price:?float, category:?string, dietary:?list<string>}>
     */
    public function parseItems(string $raw): array
    {
        $direct = $this->tryParse($raw);
        if ($direct !== null) {
            return $direct;
        }

        $repaired = $this->repairTruncated($raw);
        if ($repaired !== null) {
            $salvaged = $this->tryParse($repaired);
            if ($salvaged !== null) {
                return $salvaged;
            }
        }

        return [];
    }

    /** @return list<array{name:string, description:?string, price:?float, category:?string, dietary:?list<string>}>|null */
    private function tryParse(string $raw): ?array
    {
        $text = trim($raw);
        if (preg_match('/^```[a-zA-Z]*\s*([\s\S]*?)\s*```$/', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $parsed = json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($parsed) || ! is_array($parsed['items'] ?? null)) {
            return null;
        }

        $items = [];
        foreach ($parsed['items'] as $entry) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
            if (! is_array($entry)) {
                continue;
            }
            $name = $this->cleanString($entry['name'] ?? null);
            if ($name === null) {
                continue;
            }

            $items[] = [
                'name' => mb_substr($name, 0, self::NAME_MAX),
                'description' => $this->cleanString($entry['description'] ?? null),
                'price' => $this->cleanPrice($entry['price'] ?? null),
                'category' => ($cat = $this->cleanString($entry['category'] ?? null)) !== null
                    ? mb_substr($cat, 0, self::NAME_MAX)
                    : null,
                'dietary' => $this->cleanDietary($entry['dietary'] ?? null),
            ];
        }

        return $items;
    }

    private function repairTruncated(string $raw): ?string
    {
        $text = trim($raw);
        $itemsKey = strpos($text, '"items"');
        if ($itemsKey === false) {
            return null;
        }
        $arrStart = strpos($text, '[', $itemsKey);
        $lastClose = strrpos($text, '}');
        if ($arrStart === false || $lastClose === false || $lastClose < $arrStart) {
            return null;
        }

        return substr($text, 0, $lastClose + 1).']}';
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s !== '' ? $s : null;
    }

    private function cleanPrice(mixed $value): ?float
    {
        $num = is_numeric($value) ? (float) $value : null;
        if ($num === null && is_string($value)) {
            $stripped = preg_replace('/[^0-9.\-]/', '', $value) ?? '';
            $num = is_numeric($stripped) ? (float) $stripped : null;
        }
        if ($num === null || ! is_finite($num)) {
            return null;
        }

        return round(min(self::PRICE_MAX, max(0, $num)), 2);
    }

    /** @return list<string>|null Canonical dietary labels only, deduped. */
    private function cleanDietary(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $canonicalByLower = array_combine(
            array_map('strtolower', self::DIETARY_LABELS),
            self::DIETARY_LABELS,
        );
        $out = [];
        foreach ($value as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $canonical = $canonicalByLower[strtolower(trim($tag))] ?? null;
            if ($canonical !== null && ! in_array($canonical, $out, true)) {
                $out[] = $canonical;
            }
        }

        return $out !== [] ? $out : null;
    }
}
