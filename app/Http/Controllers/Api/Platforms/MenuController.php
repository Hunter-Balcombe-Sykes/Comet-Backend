<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ApplyMenuScanRequest;
use App\Http\Requests\Platforms\ScanMenuUploadRequest;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\Menu;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\MenuAiExtractor;
use App\Services\Platforms\MenuDashboardPayload;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\MenuSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Dashboard surface for a user's menu (the relational site.menus +
// menu_categories + menu_items) plus the per-item order links computed at
// read time from the live online-ordering entries. Most menu CONTENT is owned
// by MenuFetchJob (auto-scraped on online-ordering connect) — this controller
// never scrapes inline. POST /refresh re-dispatches the job (forced).
// POST /scan OCRs a user-uploaded menu photo/PDF into items (MenuAiExtractor);
// POST /scan/apply commits a reviewed batch via MenuScanApplier, independent
// of any scrape. Owner-authored
// (manual) content — add/edit/delete a dish by hand — lives in
// MenuContentController. The menu itself IS also served publicly — through
// `pools.menus` on GET /api/public/profiles/{handle} since slice 7 Phase 3
// Task 10 deleted the standalone /menu endpoint — while this controller's
// endpoints are the authenticated dashboard read/write surface. The full read
// shape is composed by
// MenuPayloadComposer, behind MenuDashboardPayload (shared with
// MenuContentController), which re-asks the orphan/count questions of the
// content lane the owner verbs now write.
class MenuController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(
        private readonly MenuSource $source,
        private readonly MenuScanApplier $scanApplier,
        private readonly MenuDashboardPayload $payload,
    ) {}

    // GET /api/platforms/menu/status — drives the integrations index card.
    public function status(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $menu = Menu::query()->where('user_id', $user->id)->first();

        // A menu is valid while it has a backing Uber Eats / DoorDash ordering
        // link OR its own owner-authored (scan/manual) content (which never
        // depended on one). Otherwise it's an orphaned scraped row whose links
        // were removed via a path that didn't clear the menu — guard against that
        // reading as connected when refresh() can't re-scrape it.
        //
        // Slice 7 Task 6: both signals are asked of BOTH lanes now — the ten
        // owner verbs write content.*, so an owner-built menu leaves
        // site.menu_categories/menu_items empty and the legacy-only questions
        // would report it as an orphan with zero dishes.
        if ($this->source->resolveAll($user) === null && ! $this->payload->hasOwnerContent($user, $menu)) {
            return $this->success(['connected' => false, 'itemCount' => 0, 'source' => null, 'fetchStatus' => null]);
        }

        $itemCount = $this->payload->itemCount($user, $menu);

        return $this->success([
            'connected' => $itemCount > 0,
            'itemCount' => $itemCount,
            'source' => $menu?->content_source,
            'fetchStatus' => $menu?->fetch_status,
        ]);
    }

    // GET /api/platforms/menu — the full menu + computed order links.
    public function show(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->success($this->payload->for($user));
    }

    // POST /api/platforms/menu/refresh — re-scrape (forced).
    public function refresh(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Sector-derived gate (2026-07-15): Menu is a food-business feature.
        // GET status()/show() stay open (UI-hidden, not endpoint-blocked) — only
        // this mutating path is gated. Runs FIRST — it's a 403 role/capability
        // restriction, distinct from the ownership gate below, and the
        // existing 403 for a non-food account must keep firing before it.
        if (! AccountCapabilities::for($user)->can_use_menu) {
            return $this->error('Menu is not available for your account.', 403);
        }

        // Ownership gate (defence-in-depth — the menu is already scoped by
        // $user->id, so this can never actually deny the caller today; it
        // exists for parity with every other mutating controller and any
        // future by-id path). No row yet → authorize a skeleton carrying the
        // caller's own user_id (SEC-106).
        $menu = Menu::query()->where('user_id', $user->id)->first();
        $this->authorizeForUser($user, 'update', $menu ?? new Menu(['user_id' => $user->id]));

        // Only meaningful when the user has a resolvable Uber Eats / DoorDash link.
        if ($this->source->resolveAll($user) === null) {
            return $this->error('Connect Uber Eats or DoorDash in Online ordering first.', 422);
        }

        // Flip to pending immediately for instant UI feedback; the job also sets it.
        Menu::query()->where('user_id', $user->id)->update(['fetch_status' => 'pending']);

        MenuFetchJob::dispatch((string) $user->id, true);

        return $this->success(['fetchStatus' => 'pending']);
    }

    // POST /api/platforms/menu/scan — OCR + structure one uploaded menu
    // photo/PDF into the {items:[...]} batch /scan/apply accepts. This is the
    // backend replacement for the dashboard's deleted /api/menu-scan Vercel
    // route (2026-08-26): one pipeline (MenuAiExtractor), one set of AI keys,
    // and the AiSpendBudget daily caps now cover user uploads too. Mistral
    // accepts base64 data URIs in the same image_url/document_url fields as
    // hosted URLs, so no storage step is needed — the file's bytes go straight
    // to OCR and are never persisted. Extraction only: the client reviews the
    // items and commits them through applyScan().
    public function scan(ScanMenuUploadRequest $request, MenuAiExtractor $extractor): JsonResponse
    {
        $user = $this->currentUser($request);

        // Sector-derived gate (2026-07-15) — same rule as refresh() above,
        // and it must run before any billed AI call.
        if (! AccountCapabilities::for($user)->can_use_menu) {
            return $this->error('Menu is not available for your account.', 403);
        }

        // Ownership gate — see refresh()'s comment (SEC-106).
        $menu = Menu::query()->where('user_id', $user->id)->first();
        $this->authorizeForUser($user, 'update', $menu ?? new Menu(['user_id' => $user->id]));

        if (! $extractor->configured()) {
            return $this->error("Menu scanning isn't configured yet.", 503);
        }

        $file = $request->file('file');
        // Content-sniffed by Symfony (magic bytes), already validated against
        // the four allowed types by the form request's mimetypes rule.
        $mime = (string) $file->getMimeType();
        $dataUri = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file->getPathname()));

        $text = $mime === 'application/pdf'
            ? $extractor->ocrDocumentUrl($dataUri, (string) $user->id)
            : $extractor->ocrImageUrl($dataUri, (string) $user->id);

        if ($text === null) {
            return $this->error('The menu scanner is having trouble right now. Try again in a moment.', 502);
        }
        if (trim($text) === '') {
            return $this->error("We couldn't read any menu items from that file. Try a clearer photo or a different page.", 422);
        }

        $items = $extractor->structure($text, (string) $user->id);
        if ($items === null) {
            return $this->error('The menu scanner is having trouble right now. Try again in a moment.', 502);
        }
        if ($items === []) {
            return $this->error("We couldn't read any menu items from that file. Try a clearer photo or a different page.", 422);
        }

        return $this->success(['items' => $items]);
    }

    // POST /api/platforms/menu/scan/apply — apply AI-extracted items from a
    // user-uploaded menu photo/PDF scan (FE10's contract). Never touches the
    // scraper; MenuScanApplier matches by name and merges. Works even for a
    // user with no Uber Eats/DoorDash link at all (creates the menu row).
    public function applyScan(ApplyMenuScanRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Sector-derived gate (2026-07-15) — same rule as refresh() above.
        if (! AccountCapabilities::for($user)->can_use_menu) {
            return $this->error('Menu is not available for your account.', 403);
        }

        // Ownership gate — see refresh()'s comment (SEC-106). Enforced HERE,
        // not inside MenuScanApplier, so the service stays callable from
        // non-HTTP callers (e.g. an automatic Google-photos scan job) without
        // an HTTP-shaped actor.
        $menu = Menu::query()->where('user_id', $user->id)->first();
        $this->authorizeForUser($user, 'update', $menu ?? new Menu(['user_id' => $user->id]));

        $items = $request->validated()['items'];
        $result = $this->scanApplier->apply($user, $items);

        // Persist for MenuFetchJob's post-rebuild re-apply — the same contract
        // GoogleMenuPhotoScanJob writes. Without this, a manual scan's
        // enrichment of SCRAPED dishes was silently lost on the next /refresh
        // rebuild (the automatic scan survived, the user's own upload didn't).
        // Merged by normalized name (new batch wins) so a manual upload and the
        // Google-photos scan can coexist in the one slot; re-fetch because
        // apply() creates the row when the user had none.
        $menu = Menu::query()->where('user_id', $user->id)->first();
        if ($menu !== null) {
            $existing = is_array($menu->scan_items['items'] ?? null) ? $menu->scan_items['items'] : [];
            $newNames = [];
            foreach ($items as $item) {
                $newNames[mb_strtolower(trim((string) $item['name']))] = true;
            }
            $kept = array_values(array_filter(
                $existing,
                static fn ($item) => is_array($item)
                    && ! isset($newNames[mb_strtolower(trim((string) ($item['name'] ?? '')))]),
            ));
            $menu->forceFill([
                'scan_items' => [
                    'items' => array_slice([...$kept, ...$items], 0, 400),
                    'source' => 'upload',
                    'scannedAt' => now()->toIso8601String(),
                ],
            ])->save();
        }

        return $this->success($result);
    }
}
