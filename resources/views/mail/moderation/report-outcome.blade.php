@extends('mail.layouts.partna')

@section('title', 'Update on your report')
@section('preheader', 'We reviewed your report — here is the outcome.')

@section('content')
    <h1 class="headline text-primary" style="margin: 0 0 16px 0; font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Segoe UI',Roboto,sans-serif; font-size: 32px; font-weight: 600; line-height: 1.125; letter-spacing: -0.022em; color: #1d1d1f;">
        Update on your report
    </h1>

    <p class="body-text text-primary" style="margin: 0 0 16px 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        Thank you for your report. We have reviewed it and taken appropriate action.
    </p>

    <p class="body-text text-primary" style="margin: 0; font-size: 17px; line-height: 1.47; color: #1d1d1f;">
        {{ $outcome }}
    </p>
@endsection

@section('footer_note', 'Sent by Partna Trust & Safety.')
