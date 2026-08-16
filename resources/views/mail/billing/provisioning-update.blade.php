<x-mail::message>
# Service Provisioning Update

Your service provisioning request has been updated.

**Service:** {{ $request->product_key ?? $request->module_key ?? 'N/A' }}
**Action:** {{ str_replace('_', ' ', ucfirst($request->requested_action ?? 'provision')) }}
**New Status:** {{ str_replace('_', ' ', ucfirst($newStatus)) }}

@if($newStatus === 'completed')
Your service is now ready to use. You can access it from your portal dashboard.
@elseif($newStatus === 'approved')
Your request has been approved and is being prepared for fulfillment.
@elseif($newStatus === 'rejected')
Your request was not approved. Please contact support if you have questions.
@elseif($newStatus === 'failed')
There was an issue fulfilling your request. Our team has been notified and will follow up.
@else
Your request is being processed. We will notify you of further updates.
@endif

<x-mail::button :url="url('/portal/provisioning')">
View Provisioning Status
</x-mail::button>

— The {{ config('app.name') }} Team
</x-mail::message>
