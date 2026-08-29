<?php

namespace App\Services\Platforms;

use App\Exceptions\Platforms\VendorAccountFaultException;
use App\Services\Cache\AiSpendBudget;
use App\Support\ThrottledReport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// The two-stage AI menu pipeline — THE single implementation since 2026-08-26,
// when the dashboard's duplicate /api/menu-scan Vercel route was deleted.
// Serves the automatic scans (GoogleMenuPhotoScanJob, WebsiteMenuPdfScanJob,
// WebsiteMenuHtmlScanJob) and the manual upload endpoint
// (MenuController::scan(), which passes base64 data URIs — Mistral accepts
// them in the same image_url/document_url fields as hosted URLs):
//   1. Mistral hosted OCR (POST /v1/ocr) turns a PUBLIC image URL into
//      markdown text. Real OCR — a dish photo yields ~nothing, a menu-board
//      photo yields hundreds of characters, which is exactly the signal the
//      job's density filter keys on.
//   2. DeepSeek (beta endpoint for the 8K max_tokens ceiling a dense menu
//      can exceed) structures the combined text into menu items, including
//      dietary markers (GF / V / VG …) so the applier can badge matched items.
//
// Keys: config('services.mistral.key') + config('services.deepseek.key').
// Both absent → configured() false and the job exits quietly (same
// "not configured" contract as MenuController::scan()'s 503).
//
// Privacy/logging: callers can pass a real hosted URL (a Google Places photo
// URL carrying a scoped access key, or a scraped website's PDF link) as well
// as a manual-upload base64 data URI — API keys and response payloads are
// never logged, and (#W1-SEC-4) neither is a caught exception's raw message,
// because Laravel's HTTP client embeds the full request URL in it. Log
// payloads carry the exception CLASS + HTTP status only; report($e) below
// still gets the full exception object, so Nightwatch keeps full detail.
class MenuAiExtractor
{
    use CleansScrapedStrings;

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

The menu has a two-level structure: CATEGORY headings (e.g. "STARTERS", "COCKTAILS", "RED WINES" — large, bold, all-caps, or otherwise visually set apart, with no price of their own) followed by the ITEMS inside that section (each with a name and usually a price). Every priced or clearly-named line belongs to exactly one item, filed under the nearest preceding category heading — a heading is NEVER itself an item.

The text may contain OCR noise: misread characters, broken or joined words, inconsistent spacing or capitalization, multi-column layouts flattened or interleaved out of reading order (columns can end up merged line-by-line), and repeated page furniture (restaurant name/address/phone, social handles, headers, footers, page numbers, decorative text). Use judgment to reconstruct the actual items: group lines under the nearest preceding category heading regardless of raw reading order, and treat every repeating "short name phrase followed by a price" as a separate item even when line breaks between items are missing or inconsistent.

Rules:
- One entry per distinct dish, drink, or product on the menu. A category heading is structure, not a product — never emit one as an item's name, even when it's the only clearly-readable text in its section (when a heading has no legible item text under it, omit that section rather than inventing an item or reporting the heading itself).
- Page furniture (restaurant name, address, phone, social handles, website, page numbers, footers) is never an item — skip it, even if it's the cleanest text on the page.
- name: the item's name as best read, at most 160 characters. Skip anything without a readable name. Never include a region, appellation, vintage, or producer here — see description.
- description: the item's printed description, or null when it has none. Fold in anything that isn't the core name but is printed with the item: tasting notes, a region/appellation (e.g. "DOC", "DOCG", "Veneto, IT"), a producer or vintage, or a second price (see below).
- price: the numeric amount only — no currency symbols or text — or null when absent or unreadable. When an item lists multiple sizes or prices (e.g. a wine's glass/bottle, or small/large), use the lowest printed price for this field and note the other price(s) in description (e.g. "Bottle $58").
- category: the menu section heading the item appears under (e.g. "Starters", "Mains", "Drinks"), or null when there is genuinely none.
- dietary: the item's printed dietary markers, normalized to this exact vocabulary: "Gluten free" (gf/gfo), "Vegetarian" (v), "Vegan" (vg/vgo/pb), "Dairy free" (df/dfo), "Nut free" (nf), "Halal", "Spicy" (chilli marks). Use null when none are printed. Never guess from ingredients.
- Keep the menu's own order as best you can tell from the text. Include at most 180 items.
- If the text is not from a menu, or nothing readable resembles menu items, return {"items":[]}.

Example — input:
ACME BAR & GRILL   123 Main St

COCKTAILS
Negroni Gin Campari vermouth $14
Old Fashioned Bourbon bitters $15

RED WINES
Chianti DOCG Tuscany IT Glass $12 / Bottle $48

Example — output:
{"items":[{"name":"Negroni","description":"Gin, Campari, vermouth","price":14,"category":"COCKTAILS","dietary":null},{"name":"Old Fashioned","description":"Bourbon, bitters","price":15,"category":"COCKTAILS","dietary":null},{"name":"Chianti","description":"DOCG, Tuscany, IT. Bottle $48.","price":12,"category":"RED WINES","dietary":null}]}
PROMPT;

    public function __construct(private readonly AiSpendBudget $budget) {}

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
        return $this->ocr(['type' => 'image_url', 'image_url' => $imageUrl], $userId);
    }

    /**
     * OCR one publicly-reachable PDF document URL to markdown text — same
     * Mistral endpoint, 'document_url' document type instead of 'image_url'.
     * Null = hard failure; '' = OCR ran but read nothing (not a menu PDF).
     */
    public function ocrDocumentUrl(string $documentUrl, ?string $userId = null): ?string
    {
        return $this->ocr(['type' => 'document_url', 'document_url' => $documentUrl], $userId);
    }

    /** @param  array{type:string, image_url?:string, document_url?:string}  $document */
    private function ocr(array $document, ?string $userId): ?string
    {
        if (! $this->budget->tryClaim('mistral_ocr')) {
            Log::info('menu_ai.ocr.budget_exhausted', ['user_id' => $userId]);

            return null;
        }

        try {
            $response = Http::withToken((string) config('services.mistral.key'))
                ->timeout(60)
                ->post(self::MISTRAL_OCR_URL, [
                    'model' => self::MISTRAL_MODEL,
                    'document' => $document,
                    'table_format' => 'markdown',
                ]);
        } catch (Throwable $e) {
            // #W1-SEC-4: log the exception CLASS, not getMessage() — Laravel's HTTP
            // client can embed the full request URL (a signed Google Places photo
            // link) in a transport exception's message. report($e) below keeps the
            // full exception for Nightwatch; only the log payload is reduced.
            Log::warning('menu_ai.ocr.threw', ['user_id' => $userId, 'error' => $e::class]);
            ThrottledReport::once('menu_ai:fault:mistral:threw', $e);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('menu_ai.ocr.not_ok', ['user_id' => $userId, 'status' => $response->status()]);
            $this->reportVendorFault('mistral', $response->status());

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
        if (! $this->budget->tryClaim('deepseek_structure')) {
            Log::info('menu_ai.structure.budget_exhausted', ['user_id' => $userId]);

            return null;
        }

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
            // #W1-SEC-4: exception CLASS only in the log payload — see ocr()'s catch.
            Log::warning('menu_ai.structure.threw', ['user_id' => $userId, 'error' => $e::class]);
            ThrottledReport::once('menu_ai:fault:deepseek:threw', $e);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('menu_ai.structure.not_ok', ['user_id' => $userId, 'status' => $response->status()]);
            $this->reportVendorFault('deepseek', $response->status());

            return null;
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content)) {
            return null;
        }

        return $this->parseItems($content);
    }

    /**
     * Escalate a vendor's non-2xx to Nightwatch when the status says
     * something about OUR account rather than routine backpressure (B3
     * escalation matrix, #W1-OBS-1). $vendor is 'mistral' or 'deepseek'.
     *
     *   401/402/403 (key/billing rejected)  -> THROTTLED, 1h per vendor+status
     *   400/404/422 (endpoint moved / our payload wrong) -> UNTHROTTLED —
     *     a canary bounded by our own deploy cadence, not vendor volume
     *   429 (routine backpressure)          -> nothing extra; Log::warning covers it
     *   >= 500 (vendor infra)               -> THROTTLED, 1h per vendor
     *   any other 4xx                       -> nothing extra
     *
     * mistral_ocr / deepseek_structure are capped at 900 calls/day EACH
     * (config/partna.php) — an unthrottled report on every call during an
     * outage would be up to 1800 Nightwatch events/day.
     */
    private function reportVendorFault(string $vendor, int $status): void
    {
        if (in_array($status, [401, 402, 403], true)) {
            ThrottledReport::once(
                "menu_ai:fault:{$vendor}:{$status}",
                new VendorAccountFaultException($vendor, 'rejected', $status),
            );

            return;
        }

        if (in_array($status, [400, 404, 422], true)) {
            report(new VendorAccountFaultException($vendor, 'unexpected_status', $status));

            return;
        }

        if ($status >= 500) {
            ThrottledReport::once(
                "menu_ai:fault:{$vendor}:5xx",
                new VendorAccountFaultException($vendor, 'server_error', $status),
            );
        }
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
