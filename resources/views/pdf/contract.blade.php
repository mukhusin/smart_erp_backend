@extends('pdf.layout')

@push('styles')
<style>
    .contract { font-size: 10.5px; line-height: 1.55; text-align: justify; }
    .contract h2, .contract h3 { font-size: 11px; margin: 10px 0 3px; color: #1f2328; }
    .contract p { margin: 0 0 6px; }
    .ref { color: #57606a; margin-bottom: 12px; }
    .signatures { width: 100%; border-collapse: collapse; margin-top: 30px; page-break-inside: avoid; }
    .signatures td { width: 50%; vertical-align: top; padding: 0 12px 0 0; font-size: 10px; }
    .line { border-bottom: 1px solid #555; height: 26px; margin-bottom: 3px; }
    .annex { page-break-before: always; }
    table.facts { width: 100%; border-collapse: collapse; font-size: 10px; }
    table.facts th, table.facts td { border: 1px solid #d0d7de; padding: 4px 6px; text-align: left; vertical-align: top; }
    table.facts th { width: 38%; background: {{ $tint }}; }
</style>
@endpush

@section('content')
    <div class="ref">{{ $values['contract_type'] }}@if ($values['contract_no']) · No. {{ $values['contract_no'] }} @endif @if ($values['employee_no']) · Employee no. {{ $values['employee_no'] }} @endif</div>
    <div class="contract">{!! $body !!}</div>

    <table class="signatures">
        <tr>
            <td>
                <strong>For the employer: {{ $values['company_name'] }}</strong>
                <div class="line"></div>Name
                <div class="line"></div>Signature and date
            </td>
            <td>
                <strong>The employee: {{ $values['employee_name'] }}</strong>
                <div class="line"></div>Signature
                <div class="line"></div>Date
            </td>
        </tr>
    </table>

    <div class="annex">
        <h1>Annex 1: Job description</h1>
        <div class="subtitle">{{ $values['position'] }}</div>
        <div class="contract">
            @if ($jobDescription) {!! nl2br(e($jobDescription)) !!} @else <p>……………</p> @endif
            <p style="font-size: 9px; color: #57606a; margin-top: 10px">The job description gives the kind of duties of this position; it is not the full list. The employee may be asked to do other job-related tasks.</p>
            <p style="margin-top: 14px"><strong>Consent.</strong> I confirm that I have read and understood the job description of my position and will carry it out in good faith.</p>
        </div>
        <table class="signatures">
            <tr>
                <td><strong>Employee: {{ $values['employee_name'] }}</strong><div class="line"></div>Signature and date</td>
                <td><strong>Supervisor: {{ $values['supervisor'] ?: '……………' }}</strong><div class="line"></div>Signature and date</td>
            </tr>
        </table>
    </div>

    <div class="annex">
        <h1>Annex 2: Personal information</h1>
        <table class="facts">
            @foreach ($personal as $label => $value)
                <tr><th>{{ $label }}</th><td>{{ $value ?: '……………………………………' }}</td></tr>
            @endforeach
        </table>
        @if ($children->isNotEmpty())
            <h3 style="font-size: 11px; margin: 12px 0 4px">Children</h3>
            <table class="facts">
                <tr><th>Name</th><th>Date of birth</th></tr>
                @foreach ($children as $child)
                    <tr><td>{{ $child->fullname }}</td><td>{{ $child->date_of_birth ? \Illuminate\Support\Carbon::parse($child->date_of_birth)->format('j M Y') : '' }}</td></tr>
                @endforeach
            </table>
        @endif
        <table class="signatures"><tr><td><strong>Employee</strong><div class="line"></div>Signature and date</td><td></td></tr></table>
    </div>
@endsection
