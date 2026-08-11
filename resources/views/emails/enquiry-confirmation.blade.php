@extends('mail.layouts.partna')

@section('preheader'){{ $proDisplayName }} has received your enquiry and will reply soon.@endsection

@section('content')
    <h1 class="headline text-primary" style="margin:0 0 16px; font-size:24px; line-height:1.2; color:{{ $brand->palette->text }};">
        Thanks{{ $visitorName !== '' ? ", {$visitorName}" : '' }} — we've got your enquiry
    </h1>

    <p class="body-text" style="margin:0 0 12px; font-size:15px; line-height:1.5; color:{{ $brand->palette->text }};">
        {{ $proDisplayName }} has received your message about &ldquo;{{ $subject }}&rdquo; and will get back to you soon.
    </p>

    @if ($brand->replyToEmail !== null && trim($brand->replyToEmail) !== '')
        {{-- Only promise a direct reply path when the pro's inbox is actually
             wired as Reply-To — otherwise replies land at Partna's generic
             address and the promise is false. --}}
        <p class="body-text" style="margin:0 0 12px; font-size:15px; line-height:1.5; color:{{ $brand->palette->textMuted }};">
            You can reply directly to this email if you need to add anything.
        </p>
    @endif

    <p class="button-cell" style="margin:24px 0 0;">
        <a href="{{ $siteUrl }}" style="display:inline-block; background:{{ $brand->palette->buttonBg }}; color:{{ $brand->palette->buttonText }}; padding:12px 22px; border-radius:{{ $brand->palette->borderRadius }}; font-size:15px; font-weight:600; text-decoration:none;">
            Visit {{ $proDisplayName }}'s page
        </a>
    </p>
@endsection
