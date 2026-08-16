<x-mail::message>
# Billing Request Update

Your billing request has been updated.

**Type:** {{ str_replace('_', ' ', ucfirst($changeRequest->type ?? 'request')) }}
**New Status:** {{ str_replace('_', ' ', ucfirst($newStatus)) }}

@if($newStatus === 'approved')
Your request has been approved and will be processed.
@elseif($newStatus === 'rejected')
Your request was not approved. Please contact support if you have questions.
@elseif($newStatus === 'completed')
Your request has been completed.
@elseif($newStatus === 'under_review')
Your request is currently being reviewed by our team.
@endif

<x-mail::button :url="url('/portal/billing/change-requests/'.$changeRequest->id)">
View Request
</x-mail::button>

— The {{ config('app.name') }} Team
</x-mail::message>

