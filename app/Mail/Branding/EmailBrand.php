<?php

namespace App\Mail\Branding;

use App\Services\Design\ThemeModePalettes;

/**
 * Immutable branding bundle for one email send. Pure data — no I/O.
 *
 * isPartna=true is the platform-branded variant (Partna logo/footer); false is a
 * professional's white-label brand. Cached as a primitive array (toArray) and
 * rebuilt on read (fromArray) so the shape can evolve without poisoning cached
 * blobs across deploys.
 */
final class EmailBrand
{
    public function __construct(
        public readonly bool $isPartna,
        public readonly string $proName,
        public readonly string $siteUrl,
        public readonly ?string $logoUrl,
        public readonly ?string $logoUrlLight,
        public readonly ?string $logoUrlDark,
        public readonly ?string $replyToEmail,
        public readonly EmailPalette $palette,
    ) {}

    public static function partna(): self
    {
        // Deliberately app.frontend_url, not app.url: app.url is the API's own
        // domain (api.partna.au / unset -> localhost in some envs), which never
        // serves /branding/* — the dashboard SPA (app.partna.au) does.
        $appUrl = (string) config('app.frontend_url', 'https://app.partna.au');

        return new self(
            isPartna: true,
            proName: (string) config('mail.from.name', 'Partna'),
            siteUrl: (string) config('app.partna_marketing_url', 'https://partna.au'),
            logoUrl: null,
            logoUrlLight: "{$appUrl}/branding/partna-wordmark-light.png",
            logoUrlDark: "{$appUrl}/branding/partna-wordmark-dark.png",
            replyToEmail: null,
            palette: EmailBrandDefaults::defaults(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'isPartna' => $this->isPartna,
            'proName' => $this->proName,
            'siteUrl' => $this->siteUrl,
            'logoUrl' => $this->logoUrl,
            'logoUrlLight' => $this->logoUrlLight,
            'logoUrlDark' => $this->logoUrlDark,
            'replyToEmail' => $this->replyToEmail,
            'palette' => [
                'accent' => $this->palette->accent,
                'accentContrast' => $this->palette->accentContrast,
                'bg' => $this->palette->bg,
                'text' => $this->palette->text,
                'textMuted' => $this->palette->textMuted,
                'buttonBg' => $this->palette->buttonBg,
                'buttonText' => $this->palette->buttonText,
                'borderRadius' => $this->palette->borderRadius,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $p = $data['palette'] ?? [];

        return new self(
            isPartna: (bool) ($data['isPartna'] ?? true),
            proName: (string) ($data['proName'] ?? ''),
            siteUrl: (string) ($data['siteUrl'] ?? config('app.partna_marketing_url', 'https://partna.au')),
            logoUrl: $data['logoUrl'] ?? null,
            logoUrlLight: $data['logoUrlLight'] ?? null,
            logoUrlDark: $data['logoUrlDark'] ?? null,
            replyToEmail: $data['replyToEmail'] ?? null,
            palette: new EmailPalette(
                accent: (string) ($p['accent'] ?? EmailBrandDefaults::ACCENT),
                accentContrast: (string) ($p['accentContrast'] ?? EmailBrandDefaults::ACCENT_CONTRAST),
                // Pre-rework cached blobs may miss bg/text — fall back to the
                // bleach (default theme-mode) anchors.
                bg: (string) ($p['bg'] ?? ThemeModePalettes::anchorsFor(null)['bg']),
                text: (string) ($p['text'] ?? ThemeModePalettes::anchorsFor(null)['text']),
                textMuted: (string) ($p['textMuted'] ?? EmailBrandDefaults::TEXT_MUTED),
                buttonBg: (string) ($p['buttonBg'] ?? EmailBrandDefaults::ACCENT),
                buttonText: (string) ($p['buttonText'] ?? EmailBrandDefaults::ACCENT_CONTRAST),
                borderRadius: (string) ($p['borderRadius'] ?? EmailBrandDefaults::BORDER_RADIUS),
            ),
        );
    }
}
