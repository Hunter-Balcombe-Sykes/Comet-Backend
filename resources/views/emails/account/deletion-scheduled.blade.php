@extends('mail.layouts.partna')

@section('title', 'Your account is scheduled for deletion')
@section('preheader', "Your account will be permanently deleted on {$deletesAt}. Cancel any time before then.")

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        Your account is scheduled for deletion
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Hi {{ $displayName }},
    </p>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Your Partna account will be permanently deleted on <strong>{{ $deletesAt }}</strong>.
    </p>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717;">
        Your account is now in read-only mode and your public profile page is offline. You can still log in to cancel the deletion at any time during this window.
    </p>

    <x-mail.button :href="$cancelUrl">Cancel deletion</x-mail.button>
@endsection

@section('footer_note', 'This is a transactional email related to your account security.')
