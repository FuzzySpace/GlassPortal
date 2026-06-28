@php $r = request()->route()?->getName() ?? ''; @endphp
<div style="display:flex;gap:.25rem;flex-wrap:wrap;margin-bottom:1.5rem;border-bottom:1px solid var(--border);padding-bottom:.6rem">
    @foreach([
        'portal.billing.dashboard'         => 'Overview',
        'portal.billing.subscriptions'     => 'Subscriptions',
        'portal.billing.invoices'          => 'Invoices',
        'portal.billing.payments'          => 'Payments',
        'portal.billing.checkout-sessions' => 'Checkout History',
        'portal.billing.plans'             => 'Plans',
        'portal.billing.change-requests'   => 'Billing Requests',
    ] as $name => $label)
    @php $active = $name === 'portal.billing.dashboard' ? ($r === $name) : str_starts_with($r, $name); @endphp
    <a href="{{ route($name) }}"
       style="padding:.35rem .8rem;border-radius:.375rem;font-size:.85rem;text-decoration:none;{{ $active ? 'background:var(--accent-d);color:#fff' : 'color:var(--text-dim)' }}">{{ $label }}</a>
    @endforeach
</div>
