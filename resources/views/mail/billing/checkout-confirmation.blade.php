<x-mail::message>
# Payment Received

Your checkout has been completed successfully.

**Plan:** {{ $session->plan?->name ?? 'N/A' }}
**Amount:** ${{ number_format(($session->amount_total ?? 0) / 100, 2) }} {{ $session->currency ?? 'USD' }}
**Date:** {{ $session->completed_at?->format('F j, Y') ?? now()->format('F j, Y') }}

Your subscription is being activated. You will receive a confirmation once your service is ready.

<x-mail::button :url="url('/portal/billing')">
View Billing Dashboard
</x-mail::button>

Thank you for choosing {{ config('app.name') }}.

— The {{ config('app.name') }} Team
</x-mail::message>
