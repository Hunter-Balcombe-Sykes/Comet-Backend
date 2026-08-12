@extends('mail.layouts.partna')

@section('title', $notification->title)
@section('preheader', \Illuminate\Support\Str::limit(strip_tags($notification->body), 140))

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #171717;">
        {{ $notification->title }}
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 24px 0; font-size: 17px; line-height: 1.47; color: #171717; white-space: pre-wrap;">{{ $notification->body }}</p>

    @if (! empty($notification->cta_url))
        <x-mail.button :href="$notification->cta_url">Open link</x-mail.button>
    @endif
@endsection

{{--
  RFC 8058 one-click unsubscribe also sets List-Unsubscribe / List-Unsubscribe-Post
  headers in StaffBroadcastMail — this visible link is the in-body counterpart most
  mail clients still render alongside (or instead of) the header-driven UI affordance.
--}}
@section('footer_note')
    Don't want these emails? <a href="{{ $unsubscribeUrl }}" style="color:#8f8f8f; text-decoration:underline;">Unsubscribe</a>
@endsection
