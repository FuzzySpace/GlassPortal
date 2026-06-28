@extends('layouts.staff')

@section('title', 'Pilot Readiness')
@section('page-title', 'Pilot Readiness')

@php
    $badge = fn ($status) => match ($status) {
        'ready'   => 'badge-active',
        'warning' => 'badge-pending',
        'blocked' => 'badge-inactive',
        default   => 'badge-pending',
    };
    $glyph = fn ($status) => match ($status) {
        'ready' => '✓', 'warning' => '!', 'blocked' => '✗', default => '?',
    };
@endphp

@section('content')

@if($isReady)
    <div class="alert alert-info" style="margin-bottom:1rem">
        <strong>No blocked checks.</strong> The system has the wiring and data for a controlled pilot.
        Review the warnings below before a live (Stripe test-mode) run.
        <span class="text-dim">Ready {{ $summary['ready'] }} · Warnings {{ $summary['warning'] }} · Blocked {{ $summary['blocked'] }}</span>
    </div>
@else
    <div class="alert alert-warning" style="margin-bottom:1rem">
        <strong>{{ $summary['blocked'] }} blocked check(s).</strong> Resolve these before starting a pilot.
        <span class="text-dim">Ready {{ $summary['ready'] }} · Warnings {{ $summary['warning'] }} · Blocked {{ $summary['blocked'] }}</span>
    </div>
@endif

<div class="text-sm text-dim" style="margin-bottom:1.25rem">
    Read-only. This page executes nothing and provisions nothing. For the CLI equivalent run
    <code>php artisan glassportal:pilot-readiness</code>; for the manual flow see the pilot runbook
    (<code>docs/runbooks/pilot-product-test.md</code>).
</div>

@foreach($categories as $category => $items)
<div class="card" style="margin-bottom:1rem;padding:0">
    <div class="section-title" style="margin:0;padding:.75rem 1rem;border-bottom:1px solid var(--border)">{{ $category }}</div>
    <table style="width:100%">
        <tbody>
            @foreach($items as $item)
            <tr>
                <td style="width:1%;padding:.5rem .75rem;vertical-align:top"><span class="badge {{ $badge($item->status) }}">{{ $glyph($item->status) }} {{ $item->status }}</span></td>
                <td style="padding:.5rem .75rem;vertical-align:top">
                    <code class="text-sm" style="color:var(--text-h)">{{ $item->key }}</code>
                    <div class="text-sm" style="margin-top:.15rem">{{ $item->message }}</div>
                    @if($item->action && $item->status !== 'ready')
                        <div class="text-sm text-dim" style="margin-top:.15rem">→ {{ $item->action }}</div>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endforeach

<div class="card" style="margin-top:1.5rem">
    <div class="section-title">Operator quick links</div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        @php
            $links = [
                'admin.billing.products'             => 'Products',
                'admin.billing.plans'                => 'Plans',
                'admin.billing.customers'            => 'Customers',
                'admin.billing.subscriptions'        => 'Subscriptions',
                'admin.billing.checkout-sessions'    => 'Checkout sessions',
                'admin.billing.entitlements'         => 'Entitlements',
                'admin.provisioning.requests.index'  => 'Provisioning requests',
                'admin.billing.change-requests'      => 'Billing change requests',
                'admin.billing.events'               => 'Billing events',
            ];
        @endphp
        @foreach($links as $name => $label)
            @if(\Illuminate\Support\Facades\Route::has($name))
                <a href="{{ route($name) }}" class="badge badge-active" style="text-decoration:none;padding:.4rem .8rem">{{ $label }} →</a>
            @endif
        @endforeach
    </div>
    <div class="text-sm text-dim" style="margin-top:.75rem">
        System status: run <code>php artisan glassportal:healthcheck</code>. Secrets are never displayed on this page.
    </div>
</div>
@endsection
