@extends('layouts.staff')

@section('title', 'Invoice Approvals')
@section('page-title', 'Invoice Approvals')

@section('content')

@if(!$billingOk)
<div class="alert alert-warning" style="margin-bottom:1rem">
    @if($billingError)
        {{ $billingError }}
    @else
        GlassBilling is not configured. Invoice approval data requires the connector.
    @endif
</div>
@endif

<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Due</th>
                <th>Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($approvals as $approval)
            <tr>
                <td class="text-sm text-dim">{{ $approval['id'] ?? '—' }}</td>
                <td>{{ $approval['customer_name'] ?? $approval['customer_id'] ?? '—' }}</td>
                <td style="font-variant-numeric:tabular-nums">
                    @if(isset($approval['amount_usd']))
                        ${{ number_format($approval['amount_usd'], 2) }}
                    @else
                        —
                    @endif
                </td>
                <td><span class="badge badge-{{ $approval['status'] ?? 'pending' }}">{{ $approval['status'] ?? '—' }}</span></td>
                <td class="text-sm text-dim">{{ $approval['due_date'] ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $approval['created_at'] ?? '—' }}</td>
                <td>
                    <a href="{{ route('admin.billing-approvals.show', $approval['id']) }}" style="color:var(--accent);text-decoration:none;font-size:.8125rem">View →</a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="text-dim" style="text-align:center;padding:2rem">
                    {{ $billingOk ? 'No invoice approvals found.' : 'Connect GlassBilling to view invoice approvals.' }}
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if(!empty($meta['total']))
<div style="margin-top:.75rem;color:var(--text-dim);font-size:.8125rem">
    Showing {{ count($approvals) }} of {{ $meta['total'] }} approvals
</div>
@endif

@endsection
