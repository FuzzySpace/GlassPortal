<x-mail::message>
# Invoice Paid

A payment has been successfully processed for your account.

**Invoice:** {{ $invoice->stripe_invoice_id ?? '#'.$invoice->id }}
**Amount:** ${{ number_format(($invoice->amount_paid_cents ?? 0) / 100, 2) }} {{ $invoice->currency ?? 'USD' }}
**Paid:** {{ $invoice->paid_at?->format('F j, Y') ?? now()->format('F j, Y') }}

<x-mail::button :url="url('/portal/billing/invoices/'.$invoice->id)">
View Invoice
</x-mail::button>

— The {{ config('app.name') }} Team
</x-mail::message>
