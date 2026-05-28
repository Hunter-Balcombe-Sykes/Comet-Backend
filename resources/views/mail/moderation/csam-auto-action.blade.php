@component('mail::message')
# CSAM auto-action requires your attention

**Case ID:** {{ $case->id }}

A CSAM hash match was detected. The user has been auto-suspended and the upload quarantined.

Please review the case and confirm the auto-action in the staff dashboard.

Thanks,
Partna Trust & Safety
@endcomponent
