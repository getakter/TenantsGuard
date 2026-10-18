@extends('layouts.app')
@section('title', 'Connect tenant')

@section('content')
<div class="page-head">
    <div>
        <h1>Connect a Microsoft 365 tenant</h1>
        <p>Read-only by default. Nothing is installed in the customer tenant and no passwords are stored.</p>
    </div>
</div>

@unless($configured)
    <div class="alert alert-warn">
        The Microsoft app is not set up yet, so Microsoft cannot show its consent screen.
        Open <a href="{{ route('settings.index') }}">Settings &gt; Microsoft app</a> and choose <strong>Automatic setup</strong>, which takes one Microsoft sign-in, or <strong>Manual registration</strong>.
    </div>
@endunless

<div class="grid grid-2">
    <div class="option recommended">
        <h2>Option 1: Connect with Microsoft <span class="badge s-info">Recommended</span></h2>
        <p>A Global Administrator signs in with Microsoft in a popup, picks their account, and clicks <strong>Accept</strong>. The tenant is detected automatically and scanned right away.</p>
        @include('partials.ms-button', [
            'href' => route('connect.microsoft'),
            'label' => 'Connect with Microsoft',
            'disabled' => ! $configured,
            'title' => $configured ? null : 'Set up the Microsoft app in Settings first',
        ])
        <p class="help" style="margin-top:10px">If the customer's admin is not with you, use option 2 and send them the consent link.</p>
    </div>

    <div class="option">
        <h2>Option 2: Add by domain or tenant ID</h2>
        <form method="post" action="{{ route('tenants.store') }}" data-busy="Looking up tenant…">
            @csrf
            <div class="field">
                <label for="identifier">Tenant ID or primary domain</label>
                <input id="identifier" type="text" name="identifier" value="{{ old('identifier') }}" placeholder="contoso.com or 00000000-0000-…" required>
                <div class="help">The domain is resolved to a tenant ID through Microsoft's public OpenID metadata.</div>
            </div>
            <div class="field">
                <label for="name">Display name (optional)</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" placeholder="Contoso Ltd">
            </div>
            <button class="btn btn-primary">Add tenant</button>
        </form>
        <p class="help" style="margin-top:10px">Then use <strong>Grant admin consent</strong> on the tenant page, or copy the consent link for the customer's admin.</p>
    </div>
</div>

<div class="card" style="margin-top:16px">
    <h3>Just exploring?</h3>
    <p class="muted">A demo tenant simulates a weakly configured Microsoft 365 tenant so you can try scanning, fixes, baselines, drift and reports.</p>
    <form method="post" action="{{ route('tenants.demo') }}" data-busy="Creating…">@csrf <button class="btn">Add demo tenant</button></form>
</div>
@endsection
