<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 28px 40px 48px 40px; }
    body { font-family: DejaVu Serif, serif; font-size: 10.5px; color: #111; }
    .head { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: middle; font-style: italic; font-size: 9.5px; line-height: 1.5; }
    .head .logo { text-align: center; width: 30%; }
    .head .logo img { max-height: 78px; max-width: 150px; }
    .head .right { text-align: left; padding-left: 60px; }
    .title { text-align: center; font-weight: bold; font-size: 12.5px; margin: 6px 0 6px; line-height: 1.35; }
    table.grid { border-collapse: collapse; width: 100%; }
    table.grid th, table.grid td { border: 1px solid #555; padding: 2px 4px; }
    table.grid th { background: #d0d0d0; text-align: left; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    .info td { vertical-align: top; }
    .band { background: {{ $secondary }}; color: {{ $onSecondary }}; padding: 2px 4px; font-size: 12px; text-transform: uppercase; }
    .gap { height: 12px; }
    .total td { font-weight: bold; }
    .shade td { background: #d0d0d0; }
    .net { border-collapse: collapse; margin-left: auto; width: 62%; font-size: 13px; font-weight: bold; }
    .net td { border: 1px solid #555; background: #c8c8c8; padding: 3px 5px; }
    .note { color: #d00000; font-weight: bold; text-align: center; margin: 26px 20px 0; font-size: 10px; }
    .small { font-size: 8.5px; text-align: center; }
    .watermark { position: fixed; top: 330px; left: 150px; transform: rotate(-45deg); font-size: 40px; color: rgba(210, 40, 40, 0.16); text-align: center; line-height: 1.3; letter-spacing: 1px; }
</style>
</head>
<body>
<div class="watermark">CONFIDENTIAL</div>

<table class="head">
    <tr>
        <td style="width: 35%">
            @if ($company?->phone) Tel: {{ $company->phone }}<br> @endif
            @if ($company?->email) E-mail: <span style="color: #1a4fd8; text-decoration: underline">{{ $company->email }}</span> @endif
        </td>
        <td class="logo">
            @if ($logo) <img src="{{ $logo }}" alt=""> @else <strong style="font-style: normal; font-size: 14px">{{ $company?->name }}</strong> @endif
        </td>
        <td class="right" style="width: 35%">
            @if ($company?->address) {{ $company->address }}<br> @endif
            @php($place = collect([$company?->city, $company?->country])->filter()->implode(' '))
            @if ($place) {{ $place }}<br> @endif
            @if ($company?->website) Website: <span style="color: #1a4fd8; text-decoration: underline">{{ $company->website }}</span> @endif
        </td>
    </tr>
</table>

<div class="title">CONFIDENTIAL<br>SALARY SLIP</div>

<table style="width: 100%; border-collapse: collapse" class="info">
    <tr>
        <td style="width: 46%; padding-right: 6px">
            <div class="band">Employee information</div>
            <div style="font-weight: bold; font-size: 11.5px; margin-top: 2px">{{ strtoupper($e['name']) }}</div>
            @if ($e['position']) <div style="font-style: italic">{{ $e['position'] }}</div> @endif
            @if ($e['employee_no']) <div>NO: {{ $e['employee_no'] }}</div> @endif
            @if ($e['phone']) <div>Phone: {{ $e['phone'] }}</div> @endif
            @if ($e['email']) <div>Email: {{ $e['email'] }}</div> @endif
        </td>
        <td>
            <table class="grid">
                <tr><th>MONTH</th><th>PAY TYPE</th><th>SN #</th></tr>
                <tr><td>{{ $month }}</td><td>MONTHLY</td><td>{{ $sn }}</td></tr>
            </table>
            <div class="gap"></div>
            <table class="grid">
                <tr><th>TIN</th><th>NSSF</th><th>NHIF</th></tr>
                <tr><td>{{ $e['tin'] ?: '—' }}</td><td>{{ $e['nssf'] ?: '—' }}</td><td>{{ $e['nhif'] ?: '—' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="gap"></div>
<table class="grid">
    <tr><th style="width: 40%">EARNINGS</th><th>AMOUNT</th><th style="width: 16%">YTD</th></tr>
    @foreach ($earnings as $l)
        <tr><td>{{ $l['label'] }}</td><td class="num">{{ number_format($l['amount'], 2) }}</td><td class="num">{{ number_format($l['ytd'], 2) }}</td></tr>
    @endforeach
    <tr class="total shade"><td>Gross Salary</td><td class="num">Tsh {{ number_format($gross['amount'], 2) }}</td><td class="num">{{ number_format($gross['ytd'], 2) }}</td></tr>
</table>

<div class="gap"></div>
<table class="grid">
    <tr><th style="width: 46%">DEDUCTIONS</th><th>AMOUNT</th><th style="width: 16%">YTD</th></tr>
    @foreach ($deductions as $l)
        <tr><td>{{ $l['label'] }}</td><td class="num">{{ number_format($l['amount'], 2) }}</td><td class="num">{{ number_format($l['ytd'], 2) }}</td></tr>
    @endforeach
    <tr class="total"><td>Total Deductions</td><td class="num">Tsh {{ number_format($total_deductions['amount'], 2) }}</td><td class="num">{{ number_format($total_deductions['ytd'], 2) }}</td></tr>
</table>

<div class="gap"></div>
<table class="net">
    <tr><td style="white-space: nowrap">NET PAY BY {{ $pay_by }}</td><td class="num">Tsh {{ number_format($net['amount'], 2) }}</td><td class="num" style="width: 22%">{{ number_format($net['ytd'], 2) }}</td></tr>
</table>

@if ($attendance_note) <div class="note">{{ $attendance_note }}</div> @endif

@if ($contributions)
    <div style="font-weight: bold; font-size: 13px; margin: 34px 0 8px">NOTE</div>
    <table class="grid" style="font-weight: bold">
        @foreach ($contributions as $c)
            <tr><td style="font-weight: bold">{{ strtoupper($c['label']) }}</td><td class="num" style="width: 18%; font-weight: bold">Tsh {{ number_format($c['amount'], 2) }}</td><td class="num" style="width: 14%; font-weight: bold">{{ number_format($c['ytd'], 2) }}</td></tr>
        @endforeach
    </table>
@endif

<div class="small" style="margin-top: 30px; font-weight: bold">YTD: Year To Date ({{ $year }})</div>
<div class="small" style="margin-top: 16px">If you have any question about this payslip, please contact the Payroll Officer.</div>
</body>
</html>
