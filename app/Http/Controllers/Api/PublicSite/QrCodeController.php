<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\User\User;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: QR code generation (SVG) for professionals.
class QrCodeController extends ApiController
{
    /**
     * Generate a QR code SVG pointing at the professional's vanity URL.
     * Returns 404 if the professional is not found, has no partna_url, has no site,
     * or the site is not published. Public endpoint — always 404, never 403.
     */
    public function svg(string $userId, Request $request): Response
    {
        $professional = User::query()
            ->whereKey($userId)
            ->with('site')
            ->first();

        if (! $professional || ! $professional->partna_url) {
            abort(404);
        }

        // Do not serve a QR code pointing at an unpublished site — the URL
        // is valid but the page is not live, so the code would be useless.
        $site = $professional->site;
        if (! $site || ! $site->is_published) {
            abort(404);
        }

        $qrCode = QrCode::create($professional->partna_url)
            ->setSize((int) config('partna.public_profile.qr_code_size', 320))
            ->setMargin((int) config('partna.public_profile.qr_code_margin', 10));

        $writer = new SvgWriter;
        $result = $writer->write($qrCode);

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Cache-Control' => 'public, max-age='.(int) config('partna.cache.ttls.qr_code_svg', 86400),
        ]);
    }
}
