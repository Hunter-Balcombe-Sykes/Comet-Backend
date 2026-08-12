@extends('mail.layouts.partna')
@section('preheader', $notification->title)
@section('content')
    @include('emails.notifications._partial-content')
@endsection

@if (($unsubscribeUrl ?? null) !== null)
    @section('footer_note')
        You're receiving this because you have an account at Partna.
        <span style="white-space:nowrap;"><a href="{{ $manageUrl }}" style="color:#8f8f8f; text-decoration:underline;">Manage notification emails</a></span>
        &nbsp;·&nbsp;
        <span style="white-space:nowrap;"><a href="{{ $unsubscribeUrl }}" style="color:#8f8f8f; text-decoration:underline;">Unsubscribe</a></span>
    @endsection
@endif
