@php($brand = $brand ?? \App\Mail\Branding\EmailBrand::partna())
{{--
  Universal email layout for all Partna outbound mail.

  Visual language is Apple-inspired (system fonts, large tight headlines,
  generous whitespace, pill-style accent button). Brand accent blue is
  pulled from the frontend design system: oklch(0.5772 0.2324 260) ≈ #1367fb.

  Child templates extend this and fill the @yield slots:
    - 'preheader' (preview text shown in inbox list; single short line)
    - 'content'   (main body — headline + paragraphs + optional CTA + fine print)

  Table-based + inline styles are deliberate. Outlook 2007–2019 ignores most
  modern CSS; this layout renders identically in Outlook, Gmail, Apple Mail,
  iOS Mail, Yahoo, and ProtonMail. Do not "modernise" to flexbox/grid.
--}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title', config('app.name', 'Partna'))</title>

    <style type="text/css">
        /* Resets */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        a { color: #1367fb; text-decoration: none; }

        /* Mobile */
        @media screen and (max-width: 600px) {
            .container { width: 100% !important; }
            .px-gutter { padding-left: 24px !important; padding-right: 24px !important; }
            .headline { font-size: 28px !important; line-height: 1.15 !important; letter-spacing: -0.018em !important; }
            .body-text { font-size: 16px !important; }
            .button-cell { padding: 22px 0 14px 0 !important; }
        }

        /* Always-light (strategy A, 2026-08-12): the color-scheme meta pins
           clients that honour it; this pins the ones that theme by media
           query instead. There is deliberately no dark logo set. */
        @media (prefers-color-scheme: dark) {
            body, .bg-body { background: #ffffff !important; }
            .text-primary { color: #171717 !important; }
            .text-secondary { color: #7d7d7d !important; }
        }
    </style>
</head>
<body class="bg-body" style="margin:0; padding:0; background-color:{{ $brand->palette->bg }}; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',Roboto,Oxygen,Ubuntu,Cantarell,'Helvetica Neue',Arial,sans-serif;">

    {{-- Preheader: shown as preview text in the inbox list, never visible in the open email --}}
    <div style="display:none; font-size:1px; color:#ffffff; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
        @yield('preheader')&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;
    </div>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:{{ $brand->palette->bg }};">
        <tr>
            <td align="center" style="padding: 32px 16px;">

                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="container" style="width:600px; max-width:600px;">

                    {{-- Header: pro logo / wordmark in white-label mode, the Partna
                         icon + wordmark otherwise (always-light — one asset set,
                         served from this app's own /branding/). --}}
                    <tr>
                        <td class="px-gutter" align="left" style="padding: 8px 40px 40px 40px;">
                            @if (! $brand->isPartna && $brand->logoUrl)
                                <a href="{{ $brand->siteUrl }}" style="text-decoration:none;">
                                    <img src="{{ $brand->logoUrl }}" alt="{{ $brand->proName }}" height="32" style="display:block; max-height:48px; border:0; outline:none;">
                                </a>
                            @elseif (! $brand->isPartna)
                                <a href="{{ $brand->siteUrl }}" style="text-decoration:none;">
                                    <span style="font-size:22px; font-weight:600; letter-spacing:-0.01em; color:{{ $brand->palette->text }};">{{ $brand->proName }}</span>
                                </a>
                            @elseif ($brand->iconUrl && $brand->wordmarkUrl)
                                <a href="https://app.partna.au" style="text-decoration:none;">
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                        <tr>
                                            <td valign="middle" style="line-height:0;">
                                                <img src="{{ $brand->iconUrl }}" alt="" width="20" height="20" style="display:block; width:20px; height:20px; border:0; outline:none;">
                                            </td>
                                            <td valign="middle" style="line-height:0; padding-left:14px;">
                                                <img src="{{ $brand->wordmarkUrl }}" alt="Partna" width="76" height="20" style="display:block; width:76px; height:20px; border:0; outline:none;">
                                            </td>
                                        </tr>
                                    </table>
                                </a>
                            @else
                                {{-- No asset URLs (stale cached blob) — text wordmark, never a broken image. --}}
                                <a href="https://app.partna.au" style="text-decoration:none;">
                                    <span style="font-size:22px; font-weight:600; letter-spacing:-0.01em; color:{{ $brand->palette->text }};">Partna</span>
                                </a>
                            @endif
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td class="px-gutter" align="left" style="padding: 0 40px 40px 40px;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer: why-received, hairline, then the legal block. Each
                         link rides inside a nowrap span so a wrap never strands a
                         separator at the end of a line. --}}
                    <tr>
                        <td class="px-gutter" align="left" style="padding: 28px 40px 8px 40px; border-top: 1px solid #ebebeb;">
                            @if ($brand->isPartna)
                                {{-- Why-received: the template's footer_note wins; the generic
                                     line is the fallback only — never both. --}}
                                <p style="margin: 0 0 20px 0; font-size: 12px; line-height: 1.6; color:#8f8f8f;">
                                    @hasSection('footer_note')
                                        @yield('footer_note')
                                    @else
                                        You're receiving this because you have an account at Partna.
                                    @endif
                                </p>
                                <p style="margin: 0 0 8px 0; font-size: 11px; line-height: 1.8; color:#8f8f8f;">
                                    <span style="white-space:nowrap;"><a href="https://partna.au/privacy" style="color:#1367fb; text-decoration:none;">Privacy policy</a></span>
                                    &nbsp;&nbsp;<span style="white-space:nowrap;">·&nbsp;&nbsp;<a href="https://partna.au/terms" style="color:#1367fb; text-decoration:none;">Terms of service</a></span>
                                    &nbsp;&nbsp;<span style="white-space:nowrap;">·&nbsp;&nbsp;<a href="mailto:hello@partna.tech" style="color:#1367fb; text-decoration:none;">hello@partna.tech</a></span>
                                </p>
                                <p style="margin: 0; font-size: 11px; line-height: 1.6; color:#8f8f8f;">
                                    &copy; {{ date('Y') }} Partna Technologies AU PTY LTD &nbsp;·&nbsp; <a href="https://partna.au" style="color:#8f8f8f; text-decoration:none;">partna.au</a>
                                </p>
                            @else
                                <p style="margin: 0 0 6px 0; font-size: 12px; line-height: 1.5; color:#8f8f8f;">
                                    {{ $brand->proName }} &nbsp;·&nbsp; sent via <a href="https://partna.au" style="color:#8f8f8f; text-decoration:none;">Partna</a>
                                </p>
                                @hasSection('footer_note')
                                    <p style="margin: 0; font-size: 11px; line-height: 1.5; color:#8f8f8f;">@yield('footer_note')</p>
                                @endif
                            @endif
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
