@extends('layouts.staff')

@section('title', 'Customers')
@section('page-title', 'Customers')

@section('content')

<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>Organization</th>
                <th>Billing Email</th>
                <th>Users</th>
                <th>Status</th>
                <th>Since</th>
            </tr>
        </thead>
        <tbody>
            @forelse($organizations as $org)
            <tr>
                <td>
                    <span style="font-weight:500;color:var(--text-h)">{{ $org->name }}</span>
                    <div class="text-sm text-dim">{{ $org->slug }}</div>
                </td>
                <td class="text-sm">{{ $org->billing_email ?? '—' }}</td>
                <td>{{ $org->users_count }}</td>
                <td>
                    <span class="badge badge-{{ $org->status === 'active' ? 'active' : 'inactive' }}">
                        {{ $org->status }}
                    </span>
                </td>
                <td class="text-sm text-dim">{{ $org->created_at->format('Y-m-d') }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="text-dim" style="text-align:center;padding:2rem">No customers yet.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($organizations->hasPages())
<div style="margin-top:1rem">{{ $organizations->links() }}</div>
@endif

@endsection
