@component('mail::message')
# Your account has been permanently closed

Your Partna account has been permanently closed for the following reason:

> {{ $decision->reason ?? 'A serious violation of our community standards.' }}

Thanks,
Partna Trust & Safety
@endcomponent
