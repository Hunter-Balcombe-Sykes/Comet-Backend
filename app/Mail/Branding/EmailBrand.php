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
        public readonly ?string $replyToEmail,
        public readonly EmailPalette $palette,
    ) {}

    public static function partna(): self
    {
        return new self(
            isPartna: true,
            proName: (string) config('mail.from.name', 'Partna'),
            siteUrl: (string) config('app.partna_marketing_url', 'https://partna.au'),
            logoUrl: null,
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
