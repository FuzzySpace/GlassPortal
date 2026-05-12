@extends('layouts.customer')

@section('title', 'Support')

@section('content')
<div class="page-header">
    <h2>Support</h2>
    <p>Submit and track support requests.</p>
</div>

<div class="alert alert-info" style="margin-bottom:1.5rem">
    The support ticketing integration is planned for Phase 4.
    For urgent issues, please contact your account manager directly.
</div>

<div class="card">
    <div class="card-title">Contact Options</div>
    <div class="mt-2 text-sm" style="line-height:1.8">
        <p>Email: <a href="#">support@glasshouse.example</a></p>
        <p style="margin-top:.5rem">Ticketing system coming in Phase 4.</p>
    </div>
</div>
@endsection
