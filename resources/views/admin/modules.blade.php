@extends('layouts.staff')

@section('title', 'Modules')
@section('page-title', 'Ecosystem Modules')

@section('content')

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div class="alert alert-info" style="flex:1;margin:0">
        Module connectors are registered in <code>config/glasshouse.php</code>.
        Enable each module by setting its environment variables and flipping its <code>enabled</code> flag.
        Per-organization module links are managed in
        <a href="{{ route('admin.module-links') }}" style="color:var(--accent)">Module Links</a>.
    </div>
</div>

{{-- Connector modules (system-level registry) --}}
<div class="section-title" style="margin-bottom:.75rem">Connector Registry</div>
<div class="card" style="padding:0;margin-bottom:1.5rem">
    <table>
        <thead>
            <tr>
                <th>Module</th>
                <th>Status</th>
                <th>Auth Mode</th>
                <th>Base URL</th>
                <th>Health Endpoint</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($modules as $key => $module)
            <tr>
                <td>
                    <span style="font-weight:600;color:var(--text-h)">{{ $module['display_name'] }}</span>
                    <div class="text-sm text-dim">{{ $key }}</div>
                </td>
                <td>
                    @php $s = $module['status'] ?? 'unconfigured'; @endphp
                    <span class="badge badge-{{ $s }}">{{ $s }}</span>
                </td>
                <td>
                    @php
                        $authMode = $key === 'glassbilling' ? 'api_token' : 'standalone';
                    @endphp
                    <code class="text-sm">{{ $authMode }}</code>
                </td>
                <td class="text-sm">
                    @if(!empty($module['base_url']))
                        <code style="font-size:.75rem;color:var(--text-dim)">{{ $module['base_url'] }}</code>
                    @else
                        <span class="text-dim">not set</span>
                    @endif
                </td>
                <td class="text-sm text-dim">{{ $module['health_endpoint'] ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $module['notes'] ?? '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-dim" style="text-align:center;padding:2rem">No modules configured.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Customer-facing launch modules --}}
<div class="section-title" style="margin-bottom:.75rem">Customer Launch Registry</div>
<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>Module Key</th>
                <th>Display Name</th>
                <th>Linked Orgs</th>
                <th>Active Links</th>
                <th>Description</th>
            </tr>
        </thead>
        <tbody>
            @forelse($launchModules as $key => $meta)
            <tr>
                <td><code style="color:var(--accent)">{{ $key }}</code></td>
                <td style="color:var(--text-h);font-weight:500">{{ $meta['display_name'] }}</td>
                <td>
                    {{ $linkCounts[$key]['total'] ?? 0 }}
                    @if(($linkCounts[$key]['total'] ?? 0) > 0)
                        <a href="{{ route('admin.module-links') }}?module_key={{ $key }}" style="font-size:.75rem;color:var(--accent);margin-left:.35rem">view →</a>
                    @endif
                </td>
                <td>
                    @if(($linkCounts[$key]['active'] ?? 0) > 0)
                        <span class="badge badge-active">{{ $linkCounts[$key]['active'] }}</span>
                    @else
                        <span class="text-dim text-sm">0</span>
                    @endif
                </td>
                <td class="text-sm text-dim">{{ $meta['description'] ?? '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-dim" style="text-align:center;padding:2rem">No launch modules configured.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
