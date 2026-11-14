<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 118px 32px 56px 32px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2328; }
    header { position: fixed; top: -96px; left: 0; right: 0; height: 80px; border-bottom: 2px solid {{ $secondary }}; }
    header table { width: 100%; border-collapse: collapse; }
    header td { vertical-align: middle; padding: 0; }
    header .logo { width: 84px; }
    header .logo img { max-width: 72px; max-height: 72px; }
    header .name { font-size: 16px; font-weight: bold; color: {{ $primary }}; }
    header .details { font-size: 9px; color: #57606a; line-height: 1.45; }
    footer { position: fixed; bottom: -40px; left: 0; right: 0; height: 26px; border-top: 1px solid {{ $secondary }}; font-size: 8.5px; color: #57606a; padding-top: 5px; }
    footer table { width: 100%; border-collapse: collapse; }
    h1 { font-size: 14px; margin: 0 0 2px; color: {{ $primary }}; }
    .subtitle { color: #57606a; margin-bottom: 10px; }
</style>
@stack('styles')
</head>
<body>
<header>
    <table>
        <tr>
            @if ($logo)
                <td class="logo"><img src="{{ $logo }}" alt=""></td>
            @endif
            <td>
                <div class="name">{{ $company?->name }}</div>
                <div class="details">
                    @php($place = collect([$company?->address, $company?->city, $company?->country])->filter()->implode(', '))
                    @if ($place) {{ $place }}<br> @endif
                    @php($contact = collect([$company?->phone ? 'Tel: '.$company->phone : null, $company?->email, $company?->website])->filter()->implode('  ·  '))
                    @if ($contact) {{ $contact }}<br> @endif
                    @php($ids = collect([$company?->tin ? 'TIN: '.$company->tin : null, $company?->registration_number ? 'Reg. No: '.$company->registration_number : null])->filter()->implode('  ·  '))
                    @if ($ids) {{ $ids }} @endif
                </div>
            </td>
        </tr>
    </table>
</header>
<footer>
    <table>
        <tr>
            <td style="width: 42%">{{ $title }}</td>
            <td style="width: 42%; text-align: center">Printed by {{ $printedBy }} on {{ $printedAt }}</td>
            <td style="width: 16%"></td>
        </tr>
    </table>
</footer>
<main>
    <h1>{{ $title }}</h1>
    @if (! empty($subtitle)) <div class="subtitle">{{ $subtitle }}</div> @endif
    @yield('content')
</main>
</body>
</html>
