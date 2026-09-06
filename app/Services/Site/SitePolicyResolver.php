<?php

namespace App\Services\Site;

use App\Console\Commands\PurgeRawAnalyticsEvents;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Analytics\Writers\PostgresEventWriter;

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
 *
 * GENERIC IS NOT THE SAME AS VAGUE, and the privacy template learnt the
 * difference on 2026-09-01. It described a site that collects anonymous
 * aggregates behind functional-only cookies. The site it is published on does
 * neither: apps/pages/src/analytics/beacon.ts mints a persistent per-visitor
 * id in localStorage (pv_vid) plus a per-tab sessionStorage id (pv_sid) and
 * attaches both to every beacon, and the pages middleware forwards the
 * visitor's real IP and Cloudflare's geo (country/region/city/lat/lon) on
 * every call, all of which the backend stores per event
 * (AnalyticsController::buildEvent + DetectsClientInfo). This text goes out
 * under the OWNER'S business name, so an inaccuracy here is a claim THEY are
 * making about their own site. When the analytics lane changes what it
 * collects or how long it keeps it, this file changes in the same commit.
 *
 * Which is why no sentence here states behaviour on its own authority any
 * more: every behavioural claim is gated on the lane that performs it saying
 * it will. See behaviouralGuarantees() for the list and for what chasing this
 * one env var at a time cost before the inversion.
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
        // Only AccountCapabilities touches account_type (2026-09-06 — this
        // read a raw isBusiness() with no documented exception, unlike
        // LinkRouter::gateAllows()'s equivalent read, which spells out why it
        // must diverge from the capability).
        if (AccountCapabilities::for($pro)->workplace_brand_is_site_identity && $workplace !== '') {
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

    /**
     * EVERY sentence in the generated policy that asserts platform BEHAVIOUR,
     * paired with the lane that decides whether it is currently true. The key
     * is the phrase that appears in the text ONLY when the claim is being made;
     * the value is that lane's own answer to "will you actually do this".
     *
     * This is the contract, and it is deliberately an inversion of how the
     * file used to work. The prose used to state behaviour and the code used to
     * hope it matched, so each new knob was a new way to make a real business
     * assert, on its own sitepage, something the platform was not doing: first
     * the raw retention floor, then the derived-scores floor, then a batch size
     * of zero, then a location precision that rounds nothing. None of those was
     * a crash. Every one of them was a sentence.
     *
     * A new claim belongs here on the same commit as the sentence that makes it,
     * and its guarantee must be ASKED of the lane that performs it — never
     * re-derived here, because a second copy of a rule is how all four of the
     * above happened. PublishedPolicyMatchesBehaviourTest sweeps the reachable
     * configurations and, for each one, checks both halves: that a false
     * guarantee keeps its phrase out of the text, and that a true guarantee is
     * backed by the lane actually doing the thing.
     *
     * @return array<string, bool> phrase published only when true => lane's own answer
     */
    public static function behaviouralGuarantees(): array
    {
        return [
            'deleted automatically' => PurgeRawAnalyticsEvents::scheduledPurgeWouldDelete(),
            'rounded down in precision' => PostgresEventWriter::coordinatesAreCoarsened(),
        ];
    }

    /**
     * The retention sentence, read from the window the purge actually runs on
     * (PurgeRawAnalyticsEvents::configuredRawRetentionDays() — the command's
     * own accessor for the key it purges on) rather than a number typed into
     * the prose: a hardcoded "90 days" becomes a false statement the day
     * someone sets the env var.
     *
     * Naming a window at all is gated on the command's OWN answer to "would a
     * scheduled run delete anything" (scheduledPurgeWouldDelete(), derived from
     * its single guard set) — never on a rule re-derived here. When nothing is
     * purged the policy says exactly that instead; a shorter untrue claim is not
     * an improvement on a longer one.
     */
    private function analyticsRetentionLine(): string
    {
        $days = PurgeRawAnalyticsEvents::configuredRawRetentionDays();

        $records = 'Individual usage records — each page you open, link you tap, section you view and visit you make, '
            .'with the browser identifiers and location estimate attached';

        $direct = 'Anything you send us directly, such as an enquiry or a mailing-list subscription, is kept until you '
            .'ask us to delete it or we no longer need it, and then deleted.';

        if (! PurgeRawAnalyticsEvents::scheduledPurgeWouldDelete()) {
            return "{$records} — are currently kept with no fixed deletion date. {$direct}";
        }

        return "{$records} — are deleted automatically {$days} days after they are recorded. Counts and rankings "
            ."worked out from them may be kept longer, but those no longer single out a browser. {$direct}";
    }

    /**
     * The location sentence. Everything up to the coordinates is unconditional —
     * the country/region/city estimate is stored on every event regardless of
     * configuration. The coarsening clause is not: PostgresEventWriter rounds to
     * a configured number of decimals with no floor under it, and at 4dp or more
     * it rounds nothing at all, because DetectsClientInfo::parseCoordinate()
     * already handed it 4dp. So the clause is published only when that lane says
     * it is coarsening (coordinatesAreCoarsened()), and the honest alternative —
     * we store what our network provider gave us — is published when it is not.
     */
    private function locationLine(): string
    {
        $lead = 'Your device\'s IP address reaches our servers with every request. We do not keep the address itself '
            .'— it is put through a one-way hash before anything is stored, which we use to spot abuse and to avoid '
            .'counting the same action twice. The IP address is used first to estimate where you are, and that '
            .'estimate IS stored with each record: your country, your state or region, your city, and ';

        $tail = ' It is an estimate from your network connection, not your device\'s GPS, and it is sometimes wrong.';

        if (! PostgresEventWriter::coordinatesAreCoarsened()) {
            return $lead.'the coordinates our network provider reports for your connection, stored at the precision '
                .'we receive them.'.$tail;
        }

        return $lead.'approximate coordinates for that area, rounded down in precision before they are saved.'.$tail;
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
                'body' => 'We collect information you choose to give us — for example your name and email address when you send an enquiry, join our mailing list, or contact us. We also record what you do on the Site automatically: the pages you open, the links and buttons you tap, the sections and items you scroll into view, how long you stay, the site or link that brought you here (including any campaign tags on it), and the device and browser you use. Each of those is stored as its own record, tied to the identifier described in the next section — not as an anonymous total.',
            ],
            [
                'heading' => 'An identifier stored in your browser',
                'body' => 'The first time you open the Site we generate a random identifier and save it in your browser under the key pv_vid, in local storage. It has no expiry date and stays until you clear this site\'s data, and it is attached to every record described above. We use it to tell repeat visits from new ones — to see how many people use the Site, not just how many pages are loaded. A second identifier, pv_sid, is saved in session storage and disappears when you close the tab; it groups a single visit together. Neither is linked to your name or email unless you also send us an enquiry, but both single your browser out from every other browser, so this information is specific to you rather than anonymous. Clearing this site\'s data in your browser settings removes them, and a new pv_vid is generated on your next visit.',
            ],
            [
                'heading' => 'Location and your IP address',
                'body' => $this->locationLine(),
            ],
            [
                'heading' => 'How we use your information',
                'body' => 'We use your information to respond to your enquiries, to send you updates you have asked to receive, and to operate, protect and improve the Site. The usage records above are how we see which pages and items people actually engage with and how many separate visitors the Site has. We do not sell your personal information, and we do not use it to advertise to you elsewhere.',
            ],
            [
                'heading' => 'Sharing and third-party services',
                'body' => 'We share information only with the service providers that power the Site — such as hosting, analytics and email delivery — and only as needed to run it. The usage records described above are collected and stored for us by the platform that hosts the Site, and are not shared with advertising networks. The Site also links out to third-party platforms we use (for example booking, ordering, ticketing or store platforms). Once you leave the Site, anything you share with those platforms is governed by their own privacy policies, and we encourage you to read them.',
            ],
            [
                'heading' => 'Cookies and similar technologies',
                'body' => 'The Site does not set advertising or cross-site tracking cookies, and nothing here follows you to other websites. It does use your browser\'s local and session storage for the two identifiers described above. Those are for analytics — the Site works without them — and they are written as soon as you open a page, without asking you first. You can clear them at any time through your browser settings, and blocking storage for this site stops them being written at all.',
            ],
            [
                'heading' => 'Data retention',
                'body' => $this->analyticsRetentionLine(),
            ],
            [
                'heading' => 'Your rights',
                'body' => "You may ask us to access, correct or delete the personal information we hold about you at any time — {$contact}. If you are subscribed to our updates, every email includes an unsubscribe link. You can remove the browser identifiers described above yourself, without asking us, by clearing this site's data in your browser settings.",
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
