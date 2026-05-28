@component('mail::message')
# Your content has been hidden

We removed your content from public view for the following reason:

> {{ $decision->reason ?? 'A violation of our community standards.' }}

If you believe this was a mistake, you can submit an appeal from your account dashboard.

Thanks,
Partna Trust & Safety
@endcomponent
