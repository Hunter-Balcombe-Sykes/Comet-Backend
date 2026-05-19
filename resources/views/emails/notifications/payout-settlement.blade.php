@extends('mail.layouts.partna')
@section('preheader', $notification->title)
@section('content')
    @include('emails.notifications._partial-content')
@endsection
