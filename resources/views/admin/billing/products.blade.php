@extends('layouts.staff')

@section('title', 'Billing Products')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Name</th><th>Key</th><th>Status</th><th>Plans</th><th>Catalog link</th></tr></thead>
        <tbody>
            @forelse($products as $p)
            <tr>
                <td style="color:var(--text-h)">{{ $p->name }}</td>
                <td class="text-sm text-dim"><code>{{ $p->product_key }}</code></td>
                <td><span class="badge badge-{{ $p->status === 'active' ? 'active' : 'inactive' }}">{{ $p->status }}</span></td>
                <td class="text-sm text-dim">{{ $p->plans_count }}</td>
                <td class="text-sm text-dim">{{ $p->catalogEntry?->title ?? '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-dim" style="text-align:center;padding:2rem">No billing products yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($products->hasPages())<div style="margin-top:1rem">{{ $products->links() }}</div>@endif
@endsection
