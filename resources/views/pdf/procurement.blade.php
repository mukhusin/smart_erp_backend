@extends('pdf.layout')

@push('styles')
<style>
    table.grid { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    table.grid td { vertical-align: top; padding: 0; }
    .box { border: 1px solid #d0d7de; border-radius: 4px; padding: 6px 8px; }
    .box .label { font-size: 8px; text-transform: uppercase; color: #57606a; letter-spacing: .4px; margin-bottom: 2px; }
    .box .strong { font-weight: bold; font-size: 11px; }
    table.meta { width: 100%; border-collapse: collapse; }
    table.meta td { padding: 1px 0; }
    table.meta td.k { color: #57606a; width: 42%; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.data th { background: {{ $secondary }}; color: {{ $onSecondary }} !important; text-align: left; font-weight: bold; padding: 5px; }
    table.data tbody tr:nth-child(even) td { background: {{ $tint }}; }
    table.data td { border-bottom: 1px solid #eaeef2; padding: 3px 5px; vertical-align: top; }
    table.data thead { display: table-header-group; }
    table.data tr { page-break-inside: avoid; }
    table.data .num { text-align: right; white-space: nowrap; }
    table.data td.best { font-weight: bold; color: #1a7f37; }
    table.data tfoot td { font-weight: bold; border-top: 1px solid #8c959f; padding: 4px 5px; }
    table.data tfoot td.light { font-weight: normal; border-top: none; }
    .section { margin-top: 12px; font-weight: bold; color: {{ $primary }}; font-size: 11px; }
    .note { margin-top: 6px; color: #424a53; line-height: 1.45; }
    table.sign { width: 100%; border-collapse: collapse; margin-top: 18px; page-break-inside: avoid; }
    table.sign td { width: 33%; padding: 0 10px 0 0; vertical-align: bottom; }
    .line { border-top: 1px solid #57606a; margin-top: 28px; padding-top: 3px; font-size: 8.5px; color: #57606a; }
</style>
@endpush

@section('content')
<table class="grid"><tr>
    @if (! empty($party))
    <td style="width: 52%; padding-right: 10px">
            <div class="box">
                <div class="label">{{ $party['label'] }}</div>
                <div class="strong">{{ $party['name'] }}</div>
                @foreach ($party['lines'] ?? [] as $l) @if ($l) <div>{{ $l }}</div> @endif @endforeach
            </div>
    </td>
    @endif
    <td style="width: {{ empty($party) ? '100%' : '48%' }}">
        <div class="box"><table class="meta">
            @foreach ($meta as $k => $v) @if ($v !== null && $v !== '') <tr><td class="k">{{ $k }}</td><td>{{ $v }}</td></tr> @endif @endforeach
        </table></div>
    </td>
</tr></table>

@if (! empty($intro)) <div class="note" style="margin-bottom: 6px">{!! nl2br(e($intro)) !!}</div> @endif

<table class="data">
    <thead><tr>
        @foreach ($columns as $c) <th class="{{ ! empty($c['num']) ? 'num' : '' }}">{{ $c['label'] }}</th> @endforeach
    </tr></thead>
    <tbody>
        @forelse ($rows as $r)
            <tr>@foreach ($columns as $c) <td class="{{ ! empty($c['num']) ? 'num' : '' }} {{ in_array($c['key'], $r['_best'] ?? [], true) ? 'best' : '' }}">{{ $r[$c['key']] ?? '' }}</td> @endforeach</tr>
        @empty
            <tr><td colspan="{{ count($columns) }}">Nothing listed.</td></tr>
        @endforelse
    </tbody>
    @if (! empty($totals))
        <tfoot>
            @foreach ($totals as $i => $t)
                <tr><td colspan="{{ count($columns) - 1 }}" class="num {{ $i < count($totals) - 1 ? 'light' : '' }}">{{ $t[0] }}</td><td class="num {{ $i < count($totals) - 1 ? 'light' : '' }}">{{ $t[1] }}</td></tr>
            @endforeach
        </tfoot>
    @endif
</table>

@foreach ($notes ?? [] as $heading => $text)
    @if ($text) <div class="section">{{ $heading }}</div><div class="note">{!! nl2br(e($text)) !!}</div> @endif
@endforeach

@if (! empty($approvals))
    <div class="section">Approvals</div>
    <table class="data">
        <thead><tr><th>Step</th><th>Decision</th><th>By</th><th>Date</th><th>Comment</th></tr></thead>
        <tbody>@foreach ($approvals as $a)
            <tr><td>{{ $a['step'] }}</td><td>{{ $a['decision'] }}</td><td>{{ $a['by'] }}</td><td>{{ $a['at'] }}</td><td>{{ $a['comment'] }}</td></tr>
        @endforeach</tbody>
    </table>
@endif

@if (! empty($signatures))
    <table class="sign"><tr>
        @foreach ($signatures as $s) <td><div class="line">{{ $s }}</div></td> @endforeach
    </tr></table>
@endif
@endsection
