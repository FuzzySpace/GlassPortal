@php $r = request()->route()?->getName() ?? ''; @endphp
<div style="display:flex;gap:.25rem;flex-wrap:wrap;margin-bottom:1.25rem;border-bottom:1px solid var(--border);padding-bottom:.6rem">
    @foreach([
        'admin.billing.overview'      => 'Overview',
        'admin.billing.customers'     => 'Customers',
        'admin.billing.products'      => 'Products',
        'admin.billing.plans'         => 'Plans',
        'admin.billing.subscriptions'     => 'Subscriptions',
        'admin.billing.entitlements'      => 'Entitlements',
        'admin.billing.checkout-sessions' => 'Checkouts',
        'admin.billing.change-requests'   => 'Requests',
        'admin.billing.events'            => 'Events',
    ] as $name => $label)
    <a href="{{ route($name) }}"
       style="padding:.35rem .8rem;border-radius:.375rem;font-size:.85rem;text-decoration:none;{{ str_starts_with($r, $name) ? 'background:var(--accent-d);color:#fff' : 'color:var(--text-dim)' }}">{{ $label }}</a>
    @endforeach
</div>
