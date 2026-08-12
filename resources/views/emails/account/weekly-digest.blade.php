@extends('mail.layouts.partna')

@php($dashboardUrl = rtrim((string) config('app.frontend_url', 'https://app.partna.au'), '/'))

@section('preheader', "Your site last week: {$visits} visits, {$taps} taps.")

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Your week on Partna
    </h1>

    <p class="body-text text-secondary" style="margin: 0 0 20px 0; font-size: 14px; line-height: 1.5; color: #7d7d7d;">
        {{ $weekLabel }}
    </p>

    {{-- Stat band: three equal cells on the well surface. Table-based on
         purpose — see the layout header comment about Outlook. --}}
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 20px 0;">
        <tr>
            <td width="33%" align="center" style="background-color:#f2f2f2; border-radius:12px 0 0 12px; padding: 18px 8px;">
                <div style="font-size: 30px; font-weight: 600; line-height: 1.1; color:#171717;">{{ number_format($visits) }}</div>
                <div style="font-size: 12px; line-height: 1.5; color:#7d7d7d;">Visits</div>
            </td>
            <td width="34%" align="center" style="background-color:#f2f2f2; padding: 18px 8px; border-left: 1px solid #ffffff; border-right: 1px solid #ffffff;">
                <div style="font-size: 30px; font-weight: 600; line-height: 1.1; color:#171717;">{{ number_format($visitors) }}</div>
                <div style="font-size: 12px; line-height: 1.5; color:#7d7d7d;">Visitors</div>
            </td>
            <td width="33%" align="center" style="background-color:#f2f2f2; border-radius:0 12px 12px 0; padding: 18px 8px;">
                <div style="font-size: 30px; font-weight: 600; line-height: 1.1; color:#171717;">{{ number_format($taps) }}</div>
                <div style="font-size: 12px; line-height: 1.5; color:#7d7d7d;">Taps</div>
            </td>
        </tr>
    </table>

    @if ($topLinkLabel !== null)
        <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.5; color: #171717;">
            Most tapped: <strong>{{ $topLinkLabel }}</strong>@if ($topLinkClicks !== null) &nbsp;&middot;&nbsp; {{ number_format($topLinkClicks) }} {{ $topLinkClicks === 1 ? 'tap' : 'taps' }}@endif
        </p>
    @endif

    <x-mail.button :href="$dashboardUrl.'/analytics'">See full analytics</x-mail.button>
@endsection

@if (($unsubscribeUrl ?? null) !== null)
    @section('footer_note')
        You're receiving this weekly summary because you have an account at Partna.
        <span style="white-space:nowrap;"><a href="{{ rtrim((string) config('app.frontend_url', 'https://app.partna.au'), '/') }}/settings/notifications" style="color:#8f8f8f; text-decoration:underline;">Manage notification emails</a></span>
        &nbsp;&middot;&nbsp;
        <span style="white-space:nowrap;"><a href="{{ $unsubscribeUrl }}" style="color:#8f8f8f; text-decoration:underline;">Unsubscribe</a></span>
    @endsection
@endif
