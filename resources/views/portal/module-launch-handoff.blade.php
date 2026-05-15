@extends('layouts.customer')

@section('title', 'Launching {{ $link->display_name }}')

@section('content')
<div class="page-header">
    <h2>{{ $link->display_name }}</h2>
    <p>Secure Launch</p>
</div>

<div class="card" style="max-width:480px;margin:0 auto;text-align:center;padding:2.5rem 2rem">
    <div style="font-size:2rem;margin-bottom:1rem;color:var(--accent)">⊛</div>

    <h3 style="color:var(--text-h);margin:0 0 .75rem" id="launch-heading">
        Launching securely&hellip;
    </h3>

    <p style="color:var(--text-dim);font-size:.875rem;margin:0 0 1.5rem;line-height:1.6">
        You are being redirected to <strong>{{ $link->display_name }}</strong>
        via a secure signed handoff.
        This page will submit automatically.
    </p>

    {{--
        POST-form handoff.
        The signed launch token (SLP) is submitted in the POST body so it does
        not appear in the browser URL bar or in server access logs on the module side.
        The signing secret itself is NEVER rendered here — only the signed token.
        Phase 9 recommendation: replace this with a back-channel exchange where
        the module calls GlassPortal to redeem a one-time code, removing the token
        from the browser entirely.
    --}}
    <form id="launch-form"
          method="POST"
          action="{{ $launchUrl }}"
          style="display:none"
          aria-hidden="true">
        <input type="hidden" name="slt" value="{{ $_token_for_handoff }}">
        <input type="hidden" name="portal" value="{{ config('glasshouse_sso.issuer', 'glassportal') }}">
    </form>

    <p class="text-sm text-dim" style="margin:0">
        Not redirecting?
        <button form="launch-form" type="submit"
                style="background:none;border:none;color:var(--accent);cursor:pointer;font-size:.875rem;padding:0;text-decoration:underline">
            Click here to continue.
        </button>
    </p>

    <p class="text-sm text-dim" style="margin:.75rem 0 0">
        <a href="{{ route('portal.modules') }}" style="color:var(--text-dim)">← Back to modules</a>
    </p>
</div>

<script>
    // Auto-submit after a brief moment so the user sees the "Launching…" state.
    // The form is display:none so the token is not visible in rendered HTML.
    (function () {
        var form = document.getElementById('launch-form');
        if (form) { setTimeout(function () { form.submit(); }, 400); }
    })();
</script>
@endsection
