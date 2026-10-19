@extends('layouts.app')
@section('title', 'Licence optimisation')

@section('content')
@php
    $money = fn ($v) => $currency.' '.number_format((float) $v, 0);
    $ranked = $rows->sortByDesc(fn ($x) => $x['r']['totals']['savings'] ?? -1);
    $isAdmin = auth()->user()->can('settings.manage');
@endphp

<div class="page-head">
    <div><h1>Licence optimisation</h1><p>Money your clients pay for licences nobody uses: unassigned seats, accounts that are disabled or idle, and licences already included in another one.</p></div>
</div>

<div class="grid grid-4" style="margin-bottom:16px">
    <div class="card stat"><div class="label">Estimated monthly spend</div><div class="value">{{ $money($totals['monthly']) }}</div><div class="help">across analysed clients</div></div>
    <div class="card stat"><div class="label">Potential savings</div><div class="value {{ $totals['savings'] > 0 ? 'text-warn' : '' }}">{{ $money($totals['savings']) }}</div><div class="help">per month · {{ $money($totals['savings'] * 12) }} per year</div></div>
    <div class="card stat"><div class="label">Unassigned paid seats</div><div class="value">{{ $totals['unused_seats'] }}</div></div>
    <div class="card stat"><div class="label">Idle or disabled people</div><div class="value">{{ $totals['people'] }}</div><div class="help">still holding licences</div></div>
</div>

<div class="card" style="padding:0">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Client</th><th class="num">Monthly spend</th><th class="num">Unused seats</th><th class="num">Disabled</th><th class="num">Idle 90+ days</th><th class="num">Duplicates</th><th class="num">Savings / month</th><th>Analysed</th></tr></thead>
            <tbody>
            @forelse($ranked as ['tenant' => $client, 'r' => $r])
                <tr>
                    <td><a href="{{ route('licensing.tenant', $client) }}"><strong>{{ $client->name }}</strong></a></td>
                    @if($r)
                        <td class="num">{{ $money($r['totals']['monthly']) }}</td>
                        <td class="num">{{ $r['totals']['unused_seats'] ?: '–' }}</td>
                        <td class="num">{{ count($r['disabled']) ?: '–' }}</td>
                        <td class="num">{{ $r['signins'] ? (count($r['inactive']) ?: '–') : 'n/a' }}</td>
                        <td class="num">{{ count($r['overlaps']) ?: '–' }}</td>
                        <td class="num"><strong class="{{ $r['totals']['savings'] > 0 ? 'text-warn' : '' }}">{{ $money($r['totals']['savings']) }}</strong></td>
                        <td class="muted">{{ \Carbon\Carbon::parse($r['read_at'])->diffForHumans() }}</td>
                    @else
                        <td colspan="6" class="muted">Not analysed yet.</td>
                        <td><form method="post" action="{{ route('licensing.refresh', $client) }}" data-busy="Analysing…">@csrf <button class="btn btn-sm">Analyse</button></form></td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="8" class="empty">No connected clients yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<p class="help">Prices are estimates from the list below (monthly, per user). Data refreshes daily; a weekly summary goes to your alert channel when "Licence optimisation" is selected in Settings. Seats on annual commitments can usually only be reduced at renewal; reassign them meanwhile.</p>

<details class="card" style="margin-top:16px" @if($errors->has('prices.*') || $errors->has('currency')) open @endif>
    <summary><strong>Licence prices</strong> <span class="muted">used for the estimates</span></summary>
    <form method="post" action="{{ route('licensing.prices') }}" style="margin-top:12px">
        @csrf
        <fieldset @disabled(! $isAdmin) style="border:0;padding:0;margin:0">
            <div class="field" style="max-width:160px"><label for="currency">Currency</label><input id="currency" name="currency" value="{{ old('currency', $currency) }}" maxlength="3" required></div>
            <div class="price-grid">
                @foreach($prices as $part => $p)
                    <label class="field"><span>{{ \App\Services\Lifecycle\Directory::SKU_NAMES[$part] ?? $part }} <small class="muted mono">{{ $part }}</small></span>
                        <input type="number" step="0.01" min="0" name="prices[{{ $part }}]" value="{{ $p }}"></label>
                @endforeach
            </div>
            <div class="grid grid-2" style="max-width:520px;margin-top:8px">
                <div class="field"><label for="new_sku">Add a SKU (part number)</label><input id="new_sku" name="new_sku" placeholder="e.g. POWERAPPS_PER_USER"></div>
                <div class="field"><label for="new_price">Monthly price</label><input id="new_price" type="number" step="0.01" min="0" name="new_price"></div>
            </div>
            @error('currency')<div class="error-text">{{ $message }}</div>@enderror
        </fieldset>
        @if($isAdmin)<button class="btn btn-primary">Save prices</button>@else<p class="help">Only users who manage settings can change prices.</p>@endif
    </form>
</details>
@endsection
