@extends('mail.layouts.partna')

@section('preheader', 'Your Partna early access invite is here.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #1d1d1f;">
        You're in
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        Your Partna early access invite is ready. Finish signing up with {{ $recipientEmail }} and your site goes live at your own Partna address.
    </p>

    <x-mail.button :href="$signupUrl">Finish signing up</x-mail.button>

    <p class="body-text text-secondary" style="margin: 32px 0 8px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">
        This invite is personal to this email address. If you weren't expecting it, you can safely ignore this email.
    </p>

    <p class="body-text text-secondary" style="margin: 0; font-size: 13px; line-height: 1.5; color: #86868b; word-break: break-all;">
        Button not working? Paste this URL into your browser:<br>
        <a href="{{ $signupUrl }}" style="color: #3a6efc; text-decoration: none;">{{ $signupUrl }}</a>
    </p>
@endsection

@section('footer_note', "You're receiving this because this address was invited to Partna early access.")
