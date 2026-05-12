@extends('layouts.staff')

@section('title', 'Modules')
@section('page-title', 'Ecosystem Modules')

@section('content')

<div class="alert alert-info" style="margin-bottom:1.5rem">
    Module connectors are registered in <code>config/glasshouse.php</code>.
    Enable each module by setting its environment variables and flipping its <code>enabled</code> flag.
    Live API connectors for non-GlassBilling modules are Phase 4+.
</div>

<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>Module</th>
                <th>Status</th>
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
                    @php
                        $s = $module['status'] ?? 'unconfigured';
                    @endphp
                    <span class="badge badge-{{ $s }}">{{ $s }}</span>
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
            <tr><td colspan="5" class="text-dim" style="text-align:center;padding:2rem">No modules configured.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
