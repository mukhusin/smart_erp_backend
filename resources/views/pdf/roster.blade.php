@extends('pdf.layout')

@push('styles')
<style>
    table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.grid th, table.grid td { border: 1px solid #d0d7de; text-align: center; padding: 2px 0; font-size: 7px; overflow: hidden; }
    table.grid thead { display: table-header-group; }
    table.grid tr { page-break-inside: avoid; }
    table.grid th { background: {{ $secondary }}; color: {{ $onSecondary }}; font-weight: bold; }
    table.grid th.we { background: #57606a; color: #fff; }
    table.grid th .wd { display: block; font-weight: normal; font-size: 6px; opacity: .85; }
    table.grid td.name { text-align: left; padding: 2px 4px; font-size: 7px; line-height: 1.15; }
    table.grid td.name small { color: #57606a; font-size: 6.5px; }
    table.grid td.we { background: #f3f5f7; }
    table.grid td.hrs { font-weight: bold; }
    table.grid td.code { font-weight: bold; }
    .legend { margin-top: 10px; }
    .legend span { display: inline-block; margin: 0 10px 4px 0; font-size: 8px; }
    .legend b { display: inline-block; min-width: 18px; padding: 1px 3px; border-radius: 2px; text-align: center; margin-right: 3px; }
</style>
@endpush

@php
    $on = function (?string $hex) {
        if (! $hex || ! preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
            return '#1f2328';
        }
        [$r, $g, $b] = array_map(fn ($c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, sscanf($hex, '#%02x%02x%02x'));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b > 0.4 ? '#111827' : '#ffffff';
    };
    $nameWidth = 18;
    $hoursWidth = 4;
    $dayWidth = count($days) ? (100 - $nameWidth - $hoursWidth) / count($days) : 0;
@endphp

@section('content')
    @if (count($rows))
        <table class="grid">
            <thead>
                <tr>
                    <th style="text-align: left; padding-left: 4px; width: {{ $nameWidth }}%">Employee</th>
                    @foreach ($days as $d)
                        <th class="{{ $d['weekend'] || $d['holiday'] ? 'we' : '' }}" style="width: {{ $dayWidth }}%">{{ $d['day'] }}<span class="wd">{{ substr($d['weekday'], 0, 2) }}</span></th>
                    @endforeach
                    <th style="width: {{ $hoursWidth }}%">Hrs</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td class="name">{{ $r['name'] }}@if ($r['employee_no']) <small>· {{ $r['employee_no'] }}</small>@endif</td>
                        @foreach ($r['cells'] as $i => $c)
                            @if ($c['code'])
                                <td class="code" style="background: {{ $c['color'] ?: '#eef2f7' }}; color: {{ $on($c['color'] ?: '#eef2f7') }}">{{ $c['short'] }}</td>
                            @else
                                <td class="{{ $days[$i]['weekend'] || $days[$i]['holiday'] ? 'we' : '' }}"></td>
                            @endif
                        @endforeach
                        <td class="hrs">{{ $r['hours'] ?: '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (count($codes))
            <div class="legend">
                @foreach ($codes as $c)
                    <span><b style="background: {{ $c['color'] ?: '#eef2f7' }}; color: {{ $on($c['color'] ?: '#eef2f7') }}">{{ $c['short'] }}</b>{{ $c['duty'] }}@if ($c['time']) ({{ $c['time'] }})@endif</span>
                @endforeach
            </div>
        @endif
        @php($holidays = collect($days)->filter(fn ($d) => $d['holiday']))
        @if ($holidays->isNotEmpty())
            <div class="legend">
                @foreach ($holidays as $h) <span><b style="background:#f3f5f7">{{ $h['day'] }}</b>{{ $h['holiday'] }}</span> @endforeach
            </div>
        @endif
    @else
        <div class="subtitle">No employees on this roster.</div>
    @endif
@endsection
