@extends('mail.layouts.partna')
@section('preheader', e($notification->title))
@section('content')
    @include('emails.notifications._partial-content')
@endsection
