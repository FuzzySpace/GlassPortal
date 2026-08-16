<x-mail::message>
# Your Subscription is Active

Your subscription is now active and your service entitlement has been created.

**Plan:** {{ $subscription->plan?->name ?? 'N/A' }}
**Status:** Active
**Period:** {{ $subscription->current_period_start?->format('M j, Y') ?? '—' }} to {{ $subscription->current_period_end?->format('M j, Y') ?? '—' }}

<x-mail::button :url="url('/portal/billing/subscriptions')">
View Subscriptions
</x-mail::button>

If you have any questions, please submit a support request through the portal.

— The {{ config('app.name') }} Team
</x-mail::message>
