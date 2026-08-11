@extends('mail.layouts.partna')

@php($daysLabel = $bucket === 't3' ? '3 days' : 'tomorrow')

@section('title', 'Your old handle is releasing soon')
@section('preheader', "Your old handle \"{$alias->handle}\" releases from the redirect pool {$daysLabel}.")

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Your old handle is releasing soon
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Hi,
    </p>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Your old handle <strong>{{ $alias->handle }}</strong> will be released from the redirect pool
        {{ $bucket === 't3' ? 'in 3 days' : 'tomorrow' }}
        (on {{ \Carbon\Carbon::parse($alias->expires_at)->toFormattedDateString() }}).
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        After release, anyone may claim this handle. If you'd like it back,
        @if ($alias->reclaim_until && \Carbon\Carbon::parse($alias->reclaim_until)->isFuture())
            you can reclaim it for free from your dashboard before the deadline.
        @else
            you'll need to rename to it normally (subject to the 30-day cooldown).
        @endif
    </p>

    @php($dashboardUrl = rtrim((string) config('app.frontend_url', 'https://app.partna.au'), '/'))
    <x-mail.button :href="$dashboardUrl">
        @if ($alias->reclaim_until && \Carbon\Carbon::parse($alias->reclaim_until)->isFuture())
            Reclaim your handle
        @else
            Open your dashboard
        @endif
    </x-mail.button>
@endsection

@section('footer_note', 'This is a transactional email related to your account.')
