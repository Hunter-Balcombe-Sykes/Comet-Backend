@extends('mail.layouts.partna')

@section('preheader', 'Your Partna site is ready.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #1d1d1f;">
        Your Partna site is ready
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        We've built a site for you. Claim it to make it yours and take control of your page.
    </p>

    <x-mail.button :href="$claimUrl">Claim your site</x-mail.button>

    <p class="body-text text-secondary" style="margin: 32px 0 8px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">
        If the button doesn't work, paste this link into your browser:
    </p>

    <p class="body-text text-secondary" style="margin: 0; font-size: 13px; line-height: 1.5; color: #86868b; word-break: break-all;">
        <a href="{{ $claimUrl }}" style="color: #3a6efc; text-decoration: none;">{{ $claimUrl }}</a>
    </p>
@endsection
