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

{{-- SIONA connector status panel (Phase 19) --}}
@if(isset($sionaHealth))
@php
    $sionaStatus  = $sionaHealth['status'] ?? 'unconfigured';
    $sionaOk      = $sionaStatus === 'ok';
    $sionaMsg     = $sionaHealth['message'] ?? '';
    $sionaLatency = $sionaHealth['latency_ms'] ?? null;
    $sionaConf    = $sionaHealth['configured'] ?? false;
    $sionaBadge   = match($sionaStatus) {
        'ok'           => 'active',
        'degraded'     => 'pending',
        'error'        => 'error',
        default        => 'unconfigured',
    };
@endphp
<div class="card" style="margin-bottom:1.5rem;border-left:3px solid var(--{{ $sionaOk ? 'accent' : ($sionaStatus === 'unconfigured' ? 'border' : 'warning') }})">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
        <div style="font-weight:600;color:var(--text-h)">◆ SIONA Connector</div>
        <span class="badge badge-{{ $sionaBadge }}">{{ $sionaStatus }}</span>
    </div>
    <div class="text-sm text-dim" style="margin-bottom:.4rem">{{ $sionaMsg }}</div>
    @if($sionaLatency !== null)
    <div class="text-sm text-dim">Probe latency: {{ $sionaLatency }}ms</div>
    @endif
    @if(! $sionaConf)
    <div class="text-sm" style="color:var(--warning);margin-top:.4rem">
        Set <code>SIONA_ENABLED=true</code>, <code>SIONA_API_URL</code>, and <code>SIONA_API_TOKEN</code> to enable live health probing.
        Supported auth modes: <code>standalone</code>, <code>signed_launch</code>, <code>backchannel_launch</code>.
    </div>
    @endif
    @isset($sionaSigning)
    {{-- Phase 21A: signed launch secret status — label only, never the secret value --}}
    <div class="text-sm text-dim" style="margin-top:.5rem">
        Signed launch secret:
        <span class="badge badge-{{ $sionaSigning['state'] === 'dedicated' ? 'active' : ($sionaSigning['state'] === 'fallback' ? 'pending' : 'error') }}">{{ $sionaSigning['label'] }}</span>
        @if($sionaSigning['state'] !== 'dedicated')
            <span class="text-dim">— set <code>GLASSPORTAL_MODULE_SECRET_SIONA</code> for per-module isolation</span>
        @endif
    </div>
    @endisset
    <div class="text-sm text-dim" style="margin-top:.5rem">
        Health endpoint:
        <a href="{{ url('/api/connectors/siona/health') }}" style="color:var(--accent);text-decoration:none" target="_blank">
            /api/connectors/siona/health
        </a>
    </div>
</div>
@endif

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
                        $authMode = match($key) {
                            'glassbilling' => 'api_token',
                            'siona'        => 'standalone / signed_launch / backchannel_launch',
                            default        => 'standalone',
                        };
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
                <th>Supported Auth Modes</th>
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
                <td class="text-sm text-dim">
                    @if(!empty($meta['supported_auth_modes']))
                        @foreach($meta['supported_auth_modes'] as $mode)
                            <code style="font-size:.7rem">{{ $mode }}</code>{{ !$loop->last ? ',' : '' }}
                        @endforeach
                    @else
                        <span class="text-dim">—</span>
                    @endif
                </td>
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
            <tr><td colspan="6" class="text-dim" style="text-align:center;padding:2rem">No launch modules configured.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
