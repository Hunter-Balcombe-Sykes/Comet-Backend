@component('mail::message')
# Case escalated to external authority

**Case ID:** {{ $decision->case_id }}

This case has been escalated to an external authority.

**Reason:** {{ $decision->reason ?? 'See audit log.' }}

Thanks,
Partna Trust & Safety
@endcomponent
