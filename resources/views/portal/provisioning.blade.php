@extends('layouts.customer')

@section('title', 'Provisioning')

@section('content')
<div style="max-width:1000px;margin:0 auto;padding:1.5rem">
    <h1 style="font-size:1.5rem;color:var(--text-h);margin-bottom:.5rem">Provisioning Requests</h1>
    <p class="text-dim" style="margin-bottom:1.5rem">The status of provisioning work for your services. This is a read-only view.</p>

    @if(!$hasOrg)
        <div class="card" style="text-align:center;padding:2rem;color:var(--text-dim)">
            Your account is not linked to an organization yet. Contact support to get set up.
        </div>
    @elseif($requests->isEmpty())
        <div class="card" style="text-align:center;padding:2rem;color:var(--text-dim)">
            You have no provisioning requests.
        </div>
    @else
        <div class="card" style="padding:0">
            <table>
                <thead><tr><th>Service</th><th>Action</th><th>Status</th><th>Requested</th></tr></thead>
                <tbody>
                    @foreach($requests as $r)
                    <tr>
                        <td style="color:var(--text-h)">{{ $r->service_type ?? 'Service' }}</td>
                        <td class="text-sm text-dim">{{ $r->requested_action }}</td>
                        <td><span class="badge badge-{{ $r->status === 'completed' ? 'active' : ($r->isTerminal() ? 'inactive' : ($r->status === 'failed' ? 'error' : 'pending')) }}">{{ $r->status }}</span></td>
                        <td class="text-sm text-dim">{{ $r->created_at?->format('Y-m-d') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-dim text-sm" style="margin-top:1rem">Need to make a change? Contact support — provisioning actions are performed by our team.</p>
    @endif
</div>
@endsection
