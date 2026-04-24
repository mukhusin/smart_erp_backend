@extends('layouts.app')
@section('title', 'My Profile')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('self-service.dashboard') }}">Self-Service</a></li>
    <li class="breadcrumb-item active">My Profile</li>
@endsection

@section('content')
<div class="row g-3">
    {{-- Left: Photo + Quick Info --}}
    <div class="col-lg-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <img src="{{ $employee->profile_image_url }}" class="rounded-circle mx-auto mb-3"
                 width="120" height="120" style="object-fit:cover">
            <h6 class="mb-0">{{ $employee->full_name }}</h6>
            <div class="text-muted small">{{ $employee->employee_no }}</div>
            <span class="badge bg-{{ $employee->employment_status === 'active' ? 'success' : 'secondary' }} mt-2">
                {{ ucfirst($employee->employment_status) }}
            </span>
            <hr>
            <div class="text-start small">
                <div class="mb-2"><i class="bi bi-building me-2 text-muted"></i>{{ $employee->department?->name ?? '—' }}</div>
                <div class="mb-2"><i class="bi bi-person-badge me-2 text-muted"></i>{{ $employee->jobPosition?->title ?? '—' }}</div>
                <div class="mb-2"><i class="bi bi-geo-alt me-2 text-muted"></i>{{ $employee->branch?->name ?? '—' }}</div>
                @if($employee->phone_number)
                    <div class="mb-2"><i class="bi bi-telephone me-2 text-muted"></i>{{ $employee->phone_number }}</div>
                @endif
                @if($employee->email)
                    <div><i class="bi bi-envelope me-2 text-muted"></i>{{ $employee->email }}</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Right: Tabs --}}
    <div class="col-lg-9">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-bottom">
                <ul class="nav nav-tabs card-header-tabs" id="profileTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#personal"><i class="bi bi-person me-1"></i>Personal</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#employment"><i class="bi bi-briefcase me-1"></i>Employment</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#contract"><i class="bi bi-file-earmark-text me-1"></i>Contract</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#qualifications"><i class="bi bi-mortarboard me-1"></i>Education</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#bank"><i class="bi bi-bank me-1"></i>Bank</a></li>
                </ul>
            </div>
            <div class="card-body tab-content pt-3">

                {{-- Personal Tab --}}
                <div class="tab-pane fade show active" id="personal">
                    <div class="row g-3">
                        @foreach([
                            ['Date of Birth', $employee->date_of_birth?->format('d M Y')],
                            ['Gender', $employee->gender],
                            ['Marital Status', ucfirst($employee->marital_status ?? '—')],
                            ['Nationality', $employee->nationality],
                            ['Place of Birth', $employee->place_of_birth],
                            ['ID Type', $employee->id_type],
                            ['ID Number', $employee->id_number],
                            ['TIN Number', $employee->tin],
                            ['Phone', $employee->phone_number],
                            ['Email', $employee->email],
                            ['Home Address', $employee->home_address],
                        ] as [$label, $value])
                        <div class="col-md-6">
                            <div class="text-muted small">{{ $label }}</div>
                            <div class="fw-medium">{{ $value ?? '—' }}</div>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Employment Tab --}}
                <div class="tab-pane fade" id="employment">
                    <div class="row g-3">
                        @foreach([
                            ['Employee No', $employee->employee_no],
                            ['Employment Date', $employee->employment_date?->format('d M Y')],
                            ['Employee Type', $employee->emp_type],
                            ['Employee Category', $employee->emp_category],
                            ['Department', $employee->department?->name],
                            ['Section', $employee->section?->name],
                            ['Job Position', $employee->jobPosition?->title],
                            ['Branch', $employee->branch?->name],
                            ['NSSF No', $employee->nssf_no],
                            ['NHIF No', $employee->nhif_no],
                            ['Employment Status', ucfirst($employee->employment_status)],
                        ] as [$label, $value])
                        <div class="col-md-6">
                            <div class="text-muted small">{{ $label }}</div>
                            <div class="fw-medium">{{ $value ?? '—' }}</div>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Contract Tab --}}
                <div class="tab-pane fade" id="contract">
                    @if($employee->activeContract)
                        <div class="row g-3">
                            @php $c = $employee->activeContract; @endphp
                            @foreach([
                                ['Contract Type', $c->natureOfContract?->name],
                                ['Status', ucfirst($c->status)],
                                ['Start Date', $c->start_date?->format('d M Y')],
                                ['End Date', $c->end_date?->format('d M Y') ?? 'Open-ended'],
                                ['Basic Salary', number_format($c->basic_salary, 2) . ' TZS'],
                                ['Period (months)', $c->period],
                            ] as [$label, $value])
                            <div class="col-md-6">
                                <div class="text-muted small">{{ $label }}</div>
                                <div class="fw-medium">{{ $value ?? '—' }}</div>
                            </div>
                            @endforeach
                            @if($c->comment)
                            <div class="col-12">
                                <div class="text-muted small">Comment</div>
                                <div>{{ $c->comment }}</div>
                            </div>
                            @endif
                        </div>
                    @else
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-file-earmark-x fs-3 d-block mb-2"></i>No active contract on record.
                        </div>
                    @endif
                </div>

                {{-- Qualifications Tab --}}
                <div class="tab-pane fade" id="qualifications">
                    @forelse($employee->qualifications as $q)
                        <div class="border rounded p-3 mb-2">
                            <div class="fw-medium">{{ $q->education_level }}</div>
                            <div class="text-muted small">{{ $q->institution }} &mdash; {{ $q->program }}</div>
                            <div class="text-muted small">{{ $q->date_of_start }} – {{ $q->date_of_end ?? $q->end_year }}</div>
                        </div>
                    @empty
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-mortarboard fs-3 d-block mb-2"></i>No qualifications on record.
                        </div>
                    @endforelse
                </div>

                {{-- Bank Tab --}}
                <div class="tab-pane fade" id="bank">
                    @forelse($employee->bankAccounts as $bank)
                        <div class="border rounded p-3 mb-2">
                            <div class="fw-medium">{{ $bank->bank?->name ?? $bank->bank_name }}</div>
                            <div class="text-muted small">Account: <strong>{{ $bank->account_number }}</strong></div>
                            <div class="text-muted small">Branch: {{ $bank->branch ?? '—' }}</div>
                        </div>
                    @empty
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-bank fs-3 d-block mb-2"></i>No bank accounts on record.
                        </div>
                    @endforelse
                </div>

            </div>
        </div>
    </div>
</div>
@endsection
