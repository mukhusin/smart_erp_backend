@extends('pdf.layout')

@push('styles')
<style>
    table.facts, table.scores { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-bottom: 10px; }
    table.facts th, table.facts td, table.scores th, table.scores td { border: 1px solid #d0d7de; padding: 4px 6px; text-align: left; vertical-align: top; }
    table.facts th { width: 22%; background: {{ $tint }}; }
    table.scores th { background: {{ $secondary }}; color: {{ $onSecondary }}; }
    table.scores td.n, table.scores th.n { text-align: center; width: 13%; }
    table.scores tr.total td { font-weight: bold; background: {{ $tint }}; }
    h3 { font-size: 11px; margin: 12px 0 4px; }
    .final { font-size: 13px; font-weight: bold; margin: 6px 0 10px; }
    .note { font-size: 9.5px; margin: 0 0 6px; white-space: pre-line; }
    .muted { color: #57606a; }
    .signatures { width: 100%; border-collapse: collapse; margin-top: 24px; page-break-inside: avoid; font-size: 9.5px; }
    .signatures td { width: 33%; vertical-align: top; padding-right: 10px; }
    .line { border-bottom: 1px solid #555; height: 24px; margin-bottom: 3px; }
</style>
@endpush

@php
    $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $unit = $scale === 'five' ? ' / 5' : '%';
@endphp

@section('content')
    <table class="facts">
        <tr><th>Employee</th><td>{{ $a->employee?->full_name }} @if ($a->employee?->employee_no) ({{ $a->employee->employee_no }}) @endif</td><th>Period</th><td>{{ $a->cycle->name }}</td></tr>
        <tr><th>Position</th><td>{{ $a->employee?->jobPosition?->title ?? '—' }}</td><th>Dates</th><td>{{ $a->cycle->start_date->format('d M Y') }} – {{ $a->cycle->end_date->format('d M Y') }}</td></tr>
        <tr><th>Department</th><td>{{ $a->department?->name ?? '—' }}</td><th>Status</th><td>{{ \Modules\Appraisals\Models\Appraisal::STATUS_LABELS[$a->status] ?? $a->status }}</td></tr>
    </table>

    <div class="final">Final score: {{ $a->final_score !== null ? $fmt($a->final_score).'%' : '—' }} @if ($a->rating) · {{ $a->rating }} @endif</div>

    <h3>Scores</h3>
    <table class="scores">
        <thead>
            <tr><th>Part</th><th class="n">Weight</th>@foreach ($scores as $s)<th class="n">{{ $s['label'] }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @foreach ($parts as $p)
                @if ($p['key'] === 'competencies')
                    @foreach ($competencies as $c)
                        <tr><td>{{ $p['label'] }}: {{ $c['name'] }}</td><td class="n">@if ($loop->first) {{ $p['weight'] }}% @endif</td>
                            @foreach ($scores as $s)<td class="n">{{ $fmt($s['competencies'][(string) $c['id']] ?? null) }}</td>@endforeach</tr>
                    @endforeach
                @else
                    <tr><td>{{ $p['label'] }}</td><td class="n">{{ $p['weight'] }}%</td>@foreach ($scores as $s)<td class="n">{{ $fmt($s[$p['key']] ?? null) }}</td>@endforeach</tr>
                @endif
            @endforeach
            <tr class="total"><td>Weighted total (%)</td><td class="n">100%</td>@foreach ($scores as $s)<td class="n">{{ $fmt($s['total']) }}</td>@endforeach</tr>
        </tbody>
    </table>
    <div class="muted" style="font-size: 8.5px">Scale: {{ $scale === 'five' ? '1 to 5' : '0 to 100%' }}.</div>

    <h3>Tasks in the period</h3>
    <p class="note">{{ $tasks['total'] }} tasks, {{ $tasks['done'] }} done ({{ $tasks['on_time'] }} on time), {{ $tasks['overdue'] }} overdue. Average progress {{ $fmt($tasks['average_progress']) }}%.</p>

    @if ($a->mid_year_comment)
        <h3>Mid-year review</h3>
        <p class="note">{{ $a->mid_year_comment }}</p>
    @endif
    @if ($a->self_comment)
        <h3>Employee's self-assessment</h3>
        <p class="note">{{ $a->self_comment }}</p>
    @endif
    @foreach ($scores as $s)
        @if ($s['rater'] !== 'self' && $s['overall'])
            <h3>{{ $s['label'] }}{{ $s['by'] ? ' ('.$s['by'].')' : '' }}</h3>
            <p class="note">{{ $s['overall'] }}</p>
        @endif
    @endforeach
    @if ($a->acknowledged_at)
        <h3>Employee's acknowledgement</h3>
        <p class="note">{{ $a->employee_agrees ? 'Agrees with the appraisal.' : 'Does not agree with the appraisal.' }} {{ $a->employee_comment }} ({{ $a->acknowledged_at->format('d M Y') }})</p>
    @endif

    <table class="signatures">
        <tr>
            <td><strong>Employee</strong><div class="line"></div>Signature and date</td>
            <td><strong>Supervisor / Head of department</strong><div class="line"></div>Signature and date</td>
            <td><strong>Human resources</strong><div class="line"></div>Signature and date</td>
        </tr>
    </table>
@endsection
