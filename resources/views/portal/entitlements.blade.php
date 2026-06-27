@extends('layouts.customer')

@section('title', 'Entitlements')

@section('content')
<div style="max-width:1000px;margin:0 auto;padding:1.5rem">
    <h1 style="font-size:1.5rem;color:var(--text-h);margin-bottom:.5rem">Your Service Entitlements</h1>
    <p class="text-dim" style="margin-bottom:1.5rem">What your account is currently entitled to receive. This is a read-only view of your billing entitlements.</p>

    @if(!$hasOrg)
        <div class="card" style="text-align:center;padding:2rem;color:var(--text-dim)">
            Your account is not linked to an organization yet. Contact support to get set up.
        </div>
    @elseif($entitlements->isEmpty())
        <div class="card" style="text-align:center;padding:2rem;color:var(--text-dim)">
            You have no active, pending, or suspended entitlements.
        </div>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem">
            @foreach($entitlements as $e)
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
                    <strong style="color:var(--text-h)">{{ $e->name }}</strong>
                    <span class="badge badge-{{ $e->isActive() ? 'active' : ($e->isSuspended() ? 'inactive' : 'pending') }}">{{ $e->status }}</span>
                </div>
                @if($e->description)<p class="text-sm text-dim" style="margin-bottom:.5rem">{{ $e->description }}</p>@endif
                <div class="text-sm text-dim">
                    @if($e->service_type)Type: {{ $e->service_type }}<br>@endif
                    @if($e->quantity > 1)Quantity: {{ $e->quantity }}<br>@endif
                    @if($e->current_period_end)Renews/ends: {{ $e->current_period_end->format('Y-m-d') }}@endif
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
