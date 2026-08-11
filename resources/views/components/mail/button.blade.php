{{--
  Bulletproof CTA button. Outlook 2007–2019 ignores CSS padding on <a>, so the
  classic technique is a VML rect fallback wrapping a styled anchor. The anchor
  inside is the visible button on every modern client; VML renders only in
  Outlook (where it's the only thing that paints the pill shape).

  Props:
    href  — destination URL (required)
    color — pill background hex; defaults to Partna accent blue
    text  — text colour hex; defaults to white

  Slot: button label.

  Usage:
    <x-mail.button href="https://...">Reset password</x-mail.button>
--}}
@props([
    'href' => '#',
    'color' => \App\Mail\Branding\EmailBrandDefaults::ACCENT,
    'text' => \App\Mail\Branding\EmailBrandDefaults::ACCENT_CONTRAST,
])

@php
    // Scheme allowlist (P3, 2026-08-12): CTAs are staff/system-authored today,
    // but guard the chokepoint before the source widens — a javascript:/data:
    // href degrades to a dead button, never a live one.
    if (! preg_match('/^https?:\/\//i', (string) $href)) {
        $href = '#';
    }

    // UTM tagging so email-driven traffic is visible to analytics. Only on
    // Partna-owned hosts — auth/verify links and external URLs pass untouched.
    $utmHost = parse_url($href, PHP_URL_HOST);
    if (is_string($utmHost) && preg_match('/(^|\.)partna\.au$/i', $utmHost) && ! str_contains($href, 'utm_source=')) {
        $href .= (str_contains($href, '?') ? '&' : '?').'utm_source=email&utm_medium=transactional';
    }
@endphp

<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 12px 0 4px 0;">
    <tr>
        <td align="left">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $href }}" style="height:48px;v-text-anchor:middle;width:240px;" arcsize="100%" strokecolor="{{ $color }}" fillcolor="{{ $color }}">
                <w:anchorlock/>
                <center style="color:{{ $text }};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:16px;font-weight:600;">{{ trim($slot) }}</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-- -->
            <a href="{{ $href }}" style="display:inline-block; background-color:{{ $color }}; color:{{ $text }}; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',Roboto,sans-serif; font-size:16px; font-weight:600; line-height:1; letter-spacing:-0.01em; text-decoration:none; padding:15px 28px; border-radius:980px; mso-hide:all;">{{ trim($slot) }}</a>
            <!--<![endif]-->
        </td>
    </tr>
</table>
