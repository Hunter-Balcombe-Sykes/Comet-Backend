@extends('mail.layouts.partna')

@section('preheader')You're subscribed to {{ $proDisplayName }}'s updates.@endsection

@section('footer_note')
    Didn't sign up, or changed your mind? <a href="{{ $unsubscribeUrl }}" style="color:#a1a1a6; text-decoration:underline;">Unsubscribe</a>.
@endsection

@section('content')
    <h1 class="headline text-primary" style="margin:0 0 16px; font-size:24px; line-height:1.2; color:{{ $brand->palette->text }};">
        You're subscribed{{ $visitorName ? ', '.e($visitorName) : '' }}
    </h1>

    <p class="body-text" style="margin:0 0 12px; font-size:15px; line-height:1.5; color:{{ $brand->palette->text }};">
        Thanks for joining {{ $proDisplayName }}'s list. You'll hear about news and updates straight from them.
    </p>

    <p class="button-cell" style="margin:24px 0 0;">
        <a href="{{ $siteUrl }}" style="display:inline-block; background:{{ $brand->palette->buttonBg }}; color:{{ $brand->palette->buttonText }}; padding:12px 22px; border-radius:{{ $brand->palette->borderRadius }}; font-size:15px; font-weight:600; text-decoration:none;">
            Visit {{ $proDisplayName }}'s page
        </a>
    </p>
@endsection
