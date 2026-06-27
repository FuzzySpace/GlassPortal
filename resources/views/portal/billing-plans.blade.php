@extends('layouts.customer')

@section('title', 'Plans')

@section('content')
<div style="max-width:1000px;margin:0 auto;padding:1.5rem">
    <h1 style="font-size:1.5rem;color:var(--text-h);margin-bottom:.5rem">Choose a Plan</h1>
    <p class="text-dim" style="margin-bottom:1.5rem">Start a subscription securely through Stripe Checkout. Your card details are entered on Stripe — GlassPortal never sees or stores them.</p>

    @unless($checkoutEnabled)
        <div class="alert alert-warning" style="margin-bottom:1.5rem">
            Online checkout is not currently available. Please contact support to start a subscription.
        </div>
    @endunless

    @if($plans->isEmpty())
        <div class="card" style="text-align:center;padding:2rem;color:var(--text-dim)">
            There are no plans available right now. Please check back later.
        </div>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem">
            @foreach($plans as $plan)
            <div class="card" style="display:flex;flex-direction:column">
                <div style="flex:1">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.5rem">
                        <strong style="color:var(--text-h)">{{ $plan->name }}</strong>
                        <span style="color:var(--text-h);font-weight:700">{{ $plan->priceLabel() }}</span>
                    </div>
                    @if($plan->product)<p class="text-sm text-dim" style="margin-bottom:.5rem">{{ $plan->product->name }}</p>@endif
                </div>
                <form method="POST" action="{{ route('portal.billing.checkout', $plan) }}" style="margin-top:1rem">
                    @csrf
                    <button type="submit"
                        @unless($checkoutEnabled) disabled @endunless
                        style="width:100%;padding:.55rem;border:none;border-radius:.375rem;font:inherit;font-weight:600;cursor:pointer;
                               background:{{ $checkoutEnabled ? 'var(--accent-d)' : 'var(--border)' }};
                               color:{{ $checkoutEnabled ? '#fff' : 'var(--text-dim)' }};
                               {{ $checkoutEnabled ? '' : 'cursor:not-allowed' }}">
                        Subscribe
                    </button>
                </form>
            </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
