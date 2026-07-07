@extends('mail.layouts.partna')

@section('title', 'Confirm your account deletion')
@section('preheader', 'Confirm your account deletion request — link expires in 24 hours.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #1d1d1f;">
        Confirm your account deletion
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        Hi {{ $displayName }},
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        We received a request to delete your Partna account. Tap the button below to confirm.
    </p>

    <x-mail.button :href="$confirmationUrl">Confirm deletion</x-mail.button>

    <p class="body-text text-secondary" style="margin: 32px 0 8px 0; font-size: 14px; line-height: 1.5; color: #6e6e73;">
        This link expires in 24 hours. If you didn't request this, you can safely ignore this email — your account will remain active.
    </p>

    <p class="body-text text-secondary" style="margin: 0; font-size: 13px; line-height: 1.5; color: #86868b; word-break: break-all;">
        Button not working? Paste this URL into your browser:<br>
        <a href="{{ $confirmationUrl }}" style="color: #3a6efc; text-decoration: none;">{{ $confirmationUrl }}</a>
    </p>
@endsection

@section('footer_note', 'This is a transactional email related to your account security.')
