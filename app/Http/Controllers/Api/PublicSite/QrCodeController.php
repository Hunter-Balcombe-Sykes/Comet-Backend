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
     * Returns 404 if the professional is not found or has no partna_url set.
     */
    public function svg(string $userId, Request $request): Response
    {
        $professional = User::query()
            ->whereKey($userId)
            ->first();

        if (! $professional || ! $professional->partna_url) {
            abort(404);
        }

        $qrCode = QrCode::create($professional->partna_url)
            ->setSize(320)
            ->setMargin(10);

        $writer = new SvgWriter;
        $result = $writer->write($qrCode);

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
