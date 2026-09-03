@extends('mail.layouts.partna')

@php($siteUrl = "https://{$handle}.partna.au")
@php($dashboardUrl = rtrim((string) config('app.frontend_url', 'https://app.partna.au'), '/'))

@section('preheader', 'Your Partna site is live — here are the first three things to do.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Welcome to Partna
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Your site is live at <a href="{{ $siteUrl }}" style="color:#1367fb; text-decoration:none;">{{ $handle }}.partna.au</a>.
    </p>

    {{-- Empty renders the pre-existing email verbatim: a thin scrape that
         connected nothing is real and not rare, and must not leave a heading
         with nothing under it. --}}
    @if (! empty($connectedPlatforms))
        <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
            Already connected: {{ implode(', ', $connectedPlatforms) }}.
        </p>
    @endif

    <p class="body-text text-primary" style="margin: 0 0 8px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Three things worth doing first:
    </p>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0;">
        <tr><td style="padding: 6px 0; font-size: 16px; line-height: 1.5; color: #171717;">1.&nbsp;&nbsp;Connect your first platform, so your content syncs itself.</td></tr>
        <tr><td style="padding: 6px 0; font-size: 16px; line-height: 1.5; color: #171717;">2.&nbsp;&nbsp;Pick your accent and style — your site takes it everywhere.</td></tr>
        <tr><td style="padding: 6px 0; font-size: 16px; line-height: 1.5; color: #171717;">3.&nbsp;&nbsp;Share your link wherever people already find you.</td></tr>
    </table>

    <x-mail.button :href="$dashboardUrl">Open your dashboard</x-mail.button>
@endsection

@section('footer_note', "You're receiving this because you just created a Partna account.")
