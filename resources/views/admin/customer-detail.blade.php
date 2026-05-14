@extends('layouts.staff')

@section('title', 'Customer: ' . $org->name)
@section('page-title', 'Customer Detail')

@section('content')

<div style="margin-bottom:1rem">
    <a href="{{ route('admin.customers') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Back to Customers</a>
</div>

{{-- Top meta row --}}
<div class="grid grid-2" style="margin-bottom:1.5rem">
    <div class="card">
        <div class="section-title" style="margin-bottom:.75rem">Organization</div>
        <table style="width:100%">
            <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:40%">Name</td><td style="color:var(--text-h);font-weight:500">{{ $org->name }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Slug</td><td class="text-sm">{{ $org->slug }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Billing Email</td><td class="text-sm">{{ $org->billing_email ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Status</td><td><span class="badge badge-{{ $org->status === 'active' ? 'active' : 'inactive' }}">{{ $org->status }}</span></td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Members</td><td class="text-sm">{{ $org->users->count() }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Created</td><td class="text-sm">{{ $org->created_at->format('Y-m-d') }}</td></tr>
        </table>
    </div>

    <div class="card">
        <div class="section-title" style="margin-bottom:.75rem">GlassBilling Link</div>
        @if(!$billingLinked)
            <div class="alert alert-warning" style="margin:0 0 1rem">
                This organization has no GlassBilling customer ID.
                Set <code>organizations.glassbilling_customer_id</code> to link billing data.
            </div>
        @elseif(!$billingOk)
            <div class="alert alert-warning" style="margin:0 0 1rem">
                {{ $billingError ?? 'GlassBilling data unavailable.' }}
            </div>
            <table style="width:100%">
                <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:40%">Customer ID</td><td class="text-sm">{{ $org->glassbilling_customer_id }}</td></tr>
            </table>
        @else
            <table style="width:100%">
                <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:40%">Customer ID</td><td class="text-sm">{{ $gbCustomer['id'] ?? $org->glassbilling_customer_id }}</td></tr>
                <tr><td class="text-dim text-sm" style="padding:.3rem 0">GB Name</td><td style="color:var(--text-h)">{{ $gbCustomer['name'] ?? '—' }}</td></tr>
                <tr><td class="text-dim text-sm" style="padding:.3rem 0">GB Email</td><td class="text-sm">{{ $gbCustomer['email'] ?? '—' }}</td></tr>
                <tr><td class="text-dim text-sm" style="padding:.3rem 0">GB Status</td><td><span class="badge badge-{{ $gbCustomer['status'] ?? 'unknown' }}">{{ $gbCustomer['status'] ?? '—' }}</span></td></tr>
                <tr><td class="text-dim text-sm" style="padding:.3rem 0">Balance</td><td class="text-sm" style="font-variant-numeric:tabular-nums">
                    @if(isset($gbCustomer['balance_usd']))
                        ${{ number_format($gbCustomer['balance_usd'], 2) }}
                    @else
                        —
                    @endif
                </td></tr>
            </table>
        @endif

        <div class="section-title" style="margin-top:1.25rem;margin-bottom:.5rem">Actions</div>
        <p class="text-sm text-dim">Write actions (edit customer, link/unlink) are coming in Phase 6 controlled writes.</p>
    </div>
</div>

{{-- Portal users --}}
<div class="section-title">Portal Users</div>
<div class="card" style="padding:0;margin-bottom:1.5rem">
    <table>
        <thead>
            <tr><th>Name</th><th>Email</th><th>Role</th><th>Joined</th></tr>
        </thead>
        <tbody>
            @forelse($org->users as $u)
            <tr>
                <td style="color:var(--text-h)">{{ $u->name }}</td>
                <td class="text-sm">{{ $u->email }}</td>
                <td><span class="badge badge-info">{{ $u->role?->label() ?? $u->role }}</span></td>
                <td class="text-sm text-dim">{{ $u->created_at->format('Y-m-d') }}</td>
            </tr>
            @empty
            <tr><td colspan="4" class="text-dim" style="text-align:center;padding:2rem">No portal users in this organization.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Services --}}
<div class="section-title">Services
    @if(!$billingLinked) <span class="text-dim text-sm">(no GlassBilling link)</span>
    @elseif(!$servicesOk) <span class="text-dim text-sm">(GlassBilling unavailable)</span>
    @endif
</div>
<div class="card" style="padding:0;margin-bottom:1.5rem">
    <table>
        <thead>
            <tr><th>ID</th><th>Product / Plan</th><th>Status</th><th>Billing</th><th>Since</th><th></th></tr>
        </thead>
        <tbody>
            @forelse($services as $svc)
            <tr>
                <td class="text-sm text-dim">{{ $svc['id'] ?? '—' }}</td>
                <td>
                    <span style="color:var(--text-h)">{{ $svc['product_name'] ?? '—' }}</span>
                    @if(!empty($svc['plan_name']))
                        <br><span class="text-sm text-dim">{{ $svc['plan_name'] }}</span>
                    @endif
                </td>
                <td><span class="badge badge-{{ $svc['status'] ?? 'unknown' }}">{{ $svc['status'] ?? '—' }}</span></td>
                <td><span class="badge badge-{{ $svc['billing_status'] ?? 'unknown' }}">{{ $svc['billing_status'] ?? '—' }}</span></td>
                <td class="text-sm text-dim">{{ $svc['created_at'] ?? '—' }}</td>
                <td>
                    @if(!empty($svc['id']))
                        <a href="{{ route('admin.services.show', $svc['id']) }}" style="color:var(--accent);text-decoration:none;font-size:.8125rem">View →</a>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="text-dim" style="text-align:center;padding:2rem">
                    @if(!$billingLinked) No GlassBilling link — set glassbilling_customer_id.
                    @elseif(!$servicesOk) GlassBilling data unavailable.
                    @else No services found.
                    @endif
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Provisioning --}}
<div class="section-title">Provisioning Requests
    @if(!$billingLinked) <span class="text-dim text-sm">(no GlassBilling link)</span>
    @elseif(!$provisionOk) <span class="text-dim text-sm">(GlassBilling unavailable)</span>
    @endif
</div>
<div class="card" style="padding:0;margin-bottom:1.5rem">
    <table>
        <thead>
            <tr><th>ID</th><th>Product</th><th>Status</th><th>Requested</th><th></th></tr>
        </thead>
        <tbody>
            @forelse($provisioning as $req)
            <tr>
                <td class="text-sm text-dim">{{ $req['id'] ?? '—' }}</td>
                <td>{{ $req['product_name'] ?? '—' }}</td>
                <td><span class="badge badge-{{ $req['status'] ?? 'pending' }}">{{ $req['status'] ?? '—' }}</span></td>
                <td class="text-sm text-dim">{{ $req['created_at'] ?? '—' }}</td>
                <td>
                    @if(!empty($req['id']))
                        <a href="{{ route('admin.provisioning.show', $req['id']) }}" style="color:var(--accent);text-decoration:none;font-size:.8125rem">View →</a>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="text-dim" style="text-align:center;padding:2rem">
                    @if(!$billingLinked) No GlassBilling link.
                    @elseif(!$provisionOk) GlassBilling data unavailable.
                    @else No provisioning requests.
                    @endif
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Invoice Approvals --}}
<div class="section-title">Invoice Approvals
    @if(!$billingLinked) <span class="text-dim text-sm">(no GlassBilling link)</span>
    @elseif(!$approvalsOk) <span class="text-dim text-sm">(GlassBilling unavailable)</span>
    @endif
</div>
<div class="card" style="padding:0">
    <table>
        <thead>
            <tr><th>ID</th><th>Amount</th><th>Status</th><th>Due</th><th></th></tr>
        </thead>
        <tbody>
            @forelse($approvals as $apv)
            <tr>
                <td class="text-sm text-dim">{{ $apv['id'] ?? '—' }}</td>
                <td style="font-variant-numeric:tabular-nums">
                    {{ isset($apv['amount_usd']) ? '$'.number_format($apv['amount_usd'], 2) : '—' }}
                </td>
                <td><span class="badge badge-{{ $apv['status'] ?? 'pending' }}">{{ $apv['status'] ?? '—' }}</span></td>
                <td class="text-sm text-dim">{{ $apv['due_date'] ?? '—' }}</td>
                <td>
                    @if(!empty($apv['id']))
                        <a href="{{ route('admin.billing-approvals.show', $apv['id']) }}" style="color:var(--accent);text-decoration:none;font-size:.8125rem">View →</a>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="text-dim" style="text-align:center;padding:2rem">
                    @if(!$billingLinked) No GlassBilling link.
                    @elseif(!$approvalsOk) GlassBilling data unavailable.
                    @else No invoice approvals.
                    @endif
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
