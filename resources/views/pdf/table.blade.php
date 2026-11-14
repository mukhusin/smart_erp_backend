@extends('pdf.layout')

@push('styles')
<style>
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: {{ $secondary }}; color: {{ $onSecondary }} !important; text-align: left; font-weight: bold; padding: 5px 5px; }
    table.data tbody tr:nth-child(even) td { background: {{ $tint }}; }
    table.data td { border-bottom: 1px solid #eaeef2; padding: 3px 5px; vertical-align: top; }
    table.data thead { display: table-header-group; }
    table.data tr { page-break-inside: avoid; }
    table.data .num { text-align: right; white-space: nowrap; }
    table.data .idx { width: 22px; }
    table.data td.idx { color: #8c959f; }
    table.data tfoot td { font-weight: bold; border-top: 2px solid {{ $primary }}; border-bottom: none; }
    .empty { color: #57606a; padding: 16px 0; }
    .count { color: #57606a; margin-top: 6px; }
</style>
@endpush

@section('content')
    @if (count($rows))
        <table class="data">
            <thead>
                <tr>
                    <th class="idx">#</th>
                    @foreach ($headings as $i => $h) <th class="{{ $numeric[$i] ? 'num' : '' }}">{{ $h }}</th> @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $n => $row)
                    <tr>
                        <td class="idx">{{ $n + 1 }}</td>
                        @foreach ($row as $i => $v)
                            <td class="{{ $numeric[$i] ? 'num' : '' }}">{{ is_float($v) ? number_format($v, 2) : ($v ?? '—') }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if ($totals)
                <tfoot>
                    <tr>
                        <td></td>
                        @foreach ($totals as $i => $v)
                            <td class="{{ $numeric[$i] ? 'num' : '' }}">{{ is_float($v) || is_int($v) ? number_format($v, 2) : $v }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
        <div class="count">{{ number_format(count($rows)) }} {{ count($rows) === 1 ? 'record' : 'records' }}</div>
    @else
        <div class="empty">No records.</div>
    @endif
@endsection
