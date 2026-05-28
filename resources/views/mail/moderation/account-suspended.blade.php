@component('mail::message')
# Your account has been suspended

Your Partna account has been suspended for the following reason:

> {{ $decision->reason ?? 'A violation of our community standards.' }}

You may submit an appeal through your account dashboard.

Thanks,
Partna Trust & Safety
@endcomponent
