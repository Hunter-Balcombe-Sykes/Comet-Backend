<?php

namespace App\Services\Site;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;

/**
 * Resolves a sitepage's Privacy Policy + Terms & Conditions — the single
 * source of truth for BOTH the public payload (`policies` key) and the
 * dashboard's read-only "Automated policy" preview (/me `policy_auto_texts`).
 *
 * Owner preferences live in site.sites.settings.privacy (JSONB, written by
 * PATCH /api/professional/site):
 *   { automated_privacy: bool, privacy_manual_text: string,
 *     automated_terms: bool,   terms_manual_text: string }
 *
 * Resolution per policy: automated defaults to TRUE (every sitepage ships
 * with generated policies out of the box); manual wins only when the owner
 * turned automated off AND actually wrote something — an empty manual text
 * falls back to the generated one so /privacy and /terms never render blank.
 *
 * The generated templates are deliberately generic ("not legal advice" — the
 * dashboard shows that disclaimer) and are personalized with the site's
 * display name (workplace name for business accounts), its public URL, and
 * the public contact email when one is live.
 */
class SitePolicyResolver
{
    /**
     * Full resolution for the public payload.
     *
     * `sections` is only present for auto mode — the sitepage renders them as
     * ALD-style disclosure panels; manual text has no structure and renders
     * as plain paragraphs.
     *
     * @return array{
     *     privacy: array{mode: 'auto'|'manual', text: string, sections: list<array{heading: string, body: string}>|null},
     *     terms: array{mode: 'auto'|'manual', text: string, sections: list<array{heading: string, body: string}>|null},
     * }
     */
    public function resolve(User $pro, ?Site $site, ?string $workplaceName = null, ?string $contactEmail = null): array
    {
        $prefs = $this->prefs($site);
        $name = $this->siteName($pro, $workplaceName);
        $url = $this->siteUrl($pro);

        return [
            'privacy' => $this->one(
                (bool) ($prefs['automated_privacy'] ?? true),
                trim((string) ($prefs['privacy_manual_text'] ?? '')),
                $this->privacySections($name, $url, $contactEmail),
            ),
            'terms' => $this->one(
                (bool) ($prefs['automated_terms'] ?? true),
                trim((string) ($prefs['terms_manual_text'] ?? '')),
                $this->termsSections($name, $url, $contactEmail),
            ),
        ];
    }

    /**
     * Flat generated texts for the dashboard preview textarea — the same
     * sections joined as "HEADING\n\nbody" blocks. Contact email is usually
     * unknown on this path (/me doesn't resolve public sections); the
     * templates word the contact line so its absence still reads complete.
     *
     * @return array{privacy: string, terms: string}
     */
    public function autoTexts(User $pro, ?Site $site, ?string $workplaceName = null, ?string $contactEmail = null): array
    {
        $name = $this->siteName($pro, $workplaceName);
        $url = $this->siteUrl($pro);

        return [
            'privacy' => $this->flatten($this->privacySections($name, $url, $contactEmail)),
            'terms' => $this->flatten($this->termsSections($name, $url, $contactEmail)),
        ];
    }

    /** @return array<string, mixed> */
    private function prefs(?Site $site): array
    {
        $settings = is_array($site?->settings) ? $site->settings : [];
        $prefs = $settings['privacy'] ?? [];

        return is_array($prefs) ? $prefs : [];
    }

    /**
     * @param  list<array{heading: string, body: string}>  $sections
     * @return array{mode: 'auto'|'manual', text: string, sections: list<array{heading: string, body: string}>|null}
     */
    private function one(bool $automated, string $manualText, array $sections): array
    {
        if (! $automated && $manualText !== '') {
            return ['mode' => 'manual', 'text' => $manualText, 'sections' => null];
        }

        return ['mode' => 'auto', 'text' => $this->flatten($sections), 'sections' => $sections];
    }

    private function siteName(User $pro, ?string $workplaceName): string
    {
        $workplace = trim((string) $workplaceName);
        if ($pro->isBusiness() && $workplace !== '') {
            return $workplace;
        }

        $display = trim((string) $pro->display_name);

        return $display !== '' ? $display : $pro->handle;
    }

    private function siteUrl(User $pro): string
    {
        return 'https://'.strtolower($pro->handle).'.'.config('partna.public_domain');
    }

    private function contactLine(?string $contactEmail): string
    {
        $email = trim((string) $contactEmail);

        return $email !== ''
            ? "you can contact us at {$email} or through the contact options on the Site"
            : 'you can contact us through the contact options on the Site';
    }

    /** @param list<array{heading: string, body: string}> $sections */
    private function flatten(array $sections): string
    {
        return implode("\n\n", array_map(
            static fn (array $s): string => $s['heading']."\n\n".$s['body'],
            $sections,
        ));
    }

    /** @return list<array{heading: string, body: string}> */
    private function privacySections(string $name, string $url, ?string $contactEmail): array
    {
        $contact = $this->contactLine($contactEmail);

        return [
            [
                'heading' => 'Overview',
                'body' => "This Privacy Policy describes how {$name} (\"we\", \"us\", \"our\") collects, uses and shares your information when you visit {$url} (the \"Site\"). By using the Site you agree to the collection and use of information as described here.",
            ],
            [
                'heading' => 'Information we collect',
                'body' => 'We collect information you choose to give us — for example your name and email address when you send an enquiry, join our mailing list, or contact us. We also collect limited usage information automatically when you browse the Site, such as the pages you view, the type of device and browser you use, and your approximate region. This usage information is aggregated and is not used to personally identify you.',
            ],
            [
                'heading' => 'How we use your information',
                'body' => 'We use your information to respond to your enquiries, to send you updates you have asked to receive, and to operate, protect and improve the Site. We do not sell your personal information.',
            ],
            [
                'heading' => 'Sharing and third-party services',
                'body' => 'We share information only with the service providers that power the Site — such as hosting, analytics and email delivery — and only as needed to run it. The Site also links out to third-party platforms we use (for example booking, ordering, ticketing or store platforms). Once you leave the Site, anything you share with those platforms is governed by their own privacy policies, and we encourage you to read them.',
            ],
            [
                'heading' => 'Cookies and similar technologies',
                'body' => 'The Site uses only the minimal cookies and similar technologies needed for it to function and to understand how it is used in aggregate. You can control or clear cookies through your browser settings.',
            ],
            [
                'heading' => 'Data retention',
                'body' => 'We keep your information only for as long as it is needed for the purposes described above, or as required by law, and then delete it.',
            ],
            [
                'heading' => 'Your rights',
                'body' => "You may ask us to access, correct or delete the personal information we hold about you at any time — {$contact}. If you are subscribed to our updates, every email includes an unsubscribe link.",
            ],
            [
                'heading' => 'Changes to this policy',
                'body' => 'We may update this Privacy Policy from time to time. The latest version will always be available on this page, and continued use of the Site after a change means you accept the updated policy.',
            ],
            [
                'heading' => 'Contact',
                'body' => "For any questions about this Privacy Policy or how your information is handled, {$contact}.",
            ],
        ];
    }

    /** @return list<array{heading: string, body: string}> */
    private function termsSections(string $name, string $url, ?string $contactEmail): array
    {
        $contact = $this->contactLine($contactEmail);

        return [
            [
                'heading' => 'Agreement to these terms',
                'body' => "These Terms and Conditions (\"Terms\") govern your use of {$url} (the \"Site\"), operated by {$name} (\"we\", \"us\", \"our\"). By accessing or using the Site you agree to be bound by these Terms. If you do not agree, please do not use the Site.",
            ],
            [
                'heading' => 'The Site',
                'body' => 'The Site presents information about us and our work — which may include services, products, events, menus, media and links. Content on the Site may change at any time without notice, and we may modify or discontinue any part of the Site without liability.',
            ],
            [
                'heading' => 'Third-party platforms',
                'body' => 'Some things on the Site link out to third-party platforms — for example booking, ordering, ticketing or store platforms. Any purchase, booking or order is completed on the relevant platform under its own terms, and any contract for those transactions is between you and that platform or merchant. We are not a party to, and are not responsible for, transactions completed on third-party platforms.',
            ],
            [
                'heading' => 'Intellectual property',
                'body' => "Unless stated otherwise, the content on the Site — including text, images, logos and branding — is owned by or licensed to {$name}. You may view it for personal, non-commercial purposes; you may not reproduce, distribute or use it commercially without our prior written permission.",
            ],
            [
                'heading' => 'Acceptable use',
                'body' => 'You agree not to misuse the Site — including interfering with its operation, attempting unauthorised access, scraping it at scale, or using it in any way that is unlawful or harms others.',
            ],
            [
                'heading' => 'Disclaimer',
                'body' => 'The Site and its content are provided on an "as is" and "as available" basis. While we aim to keep information accurate and current, we make no warranties about its completeness, accuracy or availability.',
            ],
            [
                'heading' => 'Liability',
                'body' => 'To the maximum extent permitted by law, we are not liable for any indirect or consequential loss arising from your use of the Site or of third-party platforms linked from it. Nothing in these Terms excludes, restricts or modifies any consumer guarantees or rights you have under the Australian Consumer Law or other laws that cannot be excluded.',
            ],
            [
                'heading' => 'Changes to these terms',
                'body' => 'We may update these Terms from time to time. The latest version will always be available on this page, and continued use of the Site after a change means you accept the updated Terms.',
            ],
            [
                'heading' => 'Governing law',
                'body' => 'These Terms are governed by the laws of Australia, and any dispute arising from them is subject to the exclusive jurisdiction of the Australian courts.',
            ],
            [
                'heading' => 'Contact',
                'body' => "For any questions about these Terms, {$contact}.",
            ],
        ];
    }
}
