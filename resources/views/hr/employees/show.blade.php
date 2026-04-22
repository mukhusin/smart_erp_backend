@extends('layouts.app')
@section('title', $employee->full_name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hr.employees.index') }}">Employees</a></li>
    <li class="breadcrumb-item active">{{ $employee->full_name }}</li>
@endsection

@section('content')
{{-- Profile header --}}
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex align-items-center gap-4 flex-wrap">
            <img src="{{ $employee->profile_image_url }}" class="rounded-circle border" width="90" height="90" style="object-fit:cover">
            <div class="flex-fill">
                <h5 class="mb-0 fw-bold">{{ $employee->emp_title ? $employee->emp_title.'. ' : '' }}{{ $employee->full_name }}</h5>
                <div class="text-muted">{{ $employee->jobPosition?->title ?? 'No position assigned' }} &nbsp;·&nbsp; {{ $employee->department?->name ?? '—' }}</div>
                <div class="mt-1 d-flex flex-wrap gap-2">
                    <span class="badge bg-primary-subtle text-primary">{{ $employee->employee_no }}</span>
                    @php $sc = ['employed'=>'success','probation'=>'warning','terminated'=>'danger','suspended'=>'danger']; @endphp
                    <span class="badge bg-{{ $sc[$employee->employment_status] ?? 'secondary' }}-subtle text-{{ $sc[$employee->employment_status] ?? 'secondary' }}">
                        {{ ucfirst($employee->employment_status) }}
                    </span>
                    @if($employee->activeContract)
                        <span class="badge bg-info-subtle text-info">{{ $employee->activeContract->natureOfContract?->contract_name ?? 'Active Contract' }}</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning">No Active Contract</span>
                    @endif
                </div>
            </div>
            <div class="d-flex gap-2 ms-auto">
                <a href="{{ route('hr.employees.edit', $employee) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil me-1"></i>Edit
                </a>
            </div>
        </div>
    </div>
</div>

{{-- Tabs --}}
<ul class="nav nav-tabs mb-0 border-bottom-0" id="empTabs" role="tablist" style="border-bottom: 1px solid #dee2e6;">
    @foreach([
        ['personal',    'bi-person',            'Personal'],
        ['contracts',   'bi-file-text',         'Contracts'],
        ['education',   'bi-mortarboard',       'Education'],
        ['experience',  'bi-briefcase',         'Experience'],
        ['dependants',  'bi-people',            'Dependants'],
        ['bank',        'bi-bank',              'Bank Accounts'],
        ['documents',   'bi-folder2-open',      'Documents'],
    ] as [$id, $icon, $label])
    <li class="nav-item">
        <button class="nav-link {{ $id === 'personal' ? 'active' : '' }}"
                data-bs-toggle="tab" data-bs-target="#tab-{{ $id }}" type="button">
            <i class="bi {{ $icon }} me-1"></i>{{ $label }}
        </button>
    </li>
    @endforeach
</ul>

<div class="tab-content bg-white border border-top-0 rounded-bottom p-3" style="border-color: #dee2e6 !important;">

    {{-- ── PERSONAL TAB ─────────────────────────────────── --}}
    <div class="tab-pane fade show active" id="tab-personal">
        <div class="row g-3">
            @php
            $fields = [
                ['Date of Birth',     $employee->date_of_birth?->format('d M Y')],
                ['Gender',            $employee->gender],
                ['Marital Status',    ucfirst($employee->marital_status ?? '—')],
                ['Nationality',       $employee->nationality],
                ['Phone',             $employee->phone_number],
                ['Email',             $employee->email],
                ['Home Address',      $employee->home_address],
                ['Place of Birth',    $employee->place_of_birth],
                ['ID Type',           $employee->id_type],
                ['ID Number',         $employee->id_number],
                ['TIN',               $employee->tin],
                ['NSSF No.',          $employee->nssf_no],
                ['NHIF No.',          $employee->nhif_no],
                ['WCF No.',           $employee->wcf_no],
                ['Employment Date',   $employee->employment_date?->format('d M Y')],
                ['Emp Type',          $employee->emp_type],
                ['Emp Category',      $employee->emp_category],
                ['Department',        $employee->department?->name],
                ['Section',           $employee->section?->name],
                ['Branch',            $employee->branch?->name],
            ];
            @endphp
            @foreach($fields as [$label, $value])
            <div class="col-md-4 col-xl-3">
                <small class="text-muted d-block">{{ $label }}</small>
                <span class="fw-semibold">{{ $value ?? '—' }}</span>
            </div>
            @endforeach
        </div>
    </div>

    {{-- ── CONTRACTS TAB ────────────────────────────────── --}}
    <div class="tab-pane fade" id="tab-contracts">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-semibold mb-0">Employment Contracts</h6>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addContractModal">
                <i class="bi bi-plus-lg me-1"></i>New Contract
            </button>
        </div>
        <table class="table table-sm table-hover">
            <thead class="table-light">
                <tr><th>Type</th><th>Basic Salary</th><th>Start</th><th>End</th><th>Period</th><th>Status</th></tr>
            </thead>
            <tbody>
            @forelse($employee->contracts as $c)
                <tr>
                    <td>{{ $c->natureOfContract?->contract_name ?? '—' }}</td>
                    <td>{{ number_format($c->basic_salary) }} TZS</td>
                    <td>{{ $c->start_date->format('d M Y') }}</td>
                    <td>{{ $c->end_date?->format('d M Y') ?? 'Open-ended' }}</td>
                    <td>{{ $c->period ? $c->period.' months' : '—' }}</td>
                    <td>
                        @if($c->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">{{ ucfirst($c->status) }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-3">No contracts yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── EDUCATION TAB ────────────────────────────────── --}}
    <div class="tab-pane fade" id="tab-education">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-semibold mb-0">Academic Qualifications</h6>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addQualModal">
                <i class="bi bi-plus-lg me-1"></i>Add
            </button>
        </div>
        <table class="table table-sm table-hover">
            <thead class="table-light">
                <tr><th>Level</th><th>Institution</th><th>Program</th><th>Period</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($employee->qualifications as $q)
                <tr>
                    <td class="fw-semibold">{{ $q->education_level }}</td>
                    <td>{{ $q->institution ?? '—' }}</td>
                    <td>{{ $q->program ?? '—' }}</td>
                    <td>{{ $q->date_of_start ?? '—' }} – {{ $q->date_of_end ?? '—' }}</td>
                    <td>
                        <form method="POST" action="{{ route('hr.employees.qualifications.destroy',[$employee,$q]) }}" onsubmit="return confirm('Remove?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-xs btn-sm btn-light text-danger py-0"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-3">No qualifications added.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── EXPERIENCE TAB ───────────────────────────────── --}}
    <div class="tab-pane fade" id="tab-experience">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-semibold mb-0">Work Experience</h6>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addExpModal">
                <i class="bi bi-plus-lg me-1"></i>Add
            </button>
        </div>
        <table class="table table-sm table-hover">
            <thead class="table-light">
                <tr><th>Company</th><th>Position</th><th>Period</th><th>Salary</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($employee->experiences as $e)
                <tr>
                    <td class="fw-semibold">{{ $e->company_name }}</td>
                    <td>{{ $e->position }}</td>
                    <td>{{ $e->start_date }} – {{ $e->end_date ?? 'Present' }}</td>
                    <td>{{ $e->salary ? number_format($e->salary) : '—' }}</td>
                    <td>
                        <form method="POST" action="{{ route('hr.employees.experiences.destroy',[$employee,$e]) }}" onsubmit="return confirm('Remove?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-light text-danger py-0"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-3">No work experience added.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── DEPENDANTS TAB ───────────────────────────────── --}}
    <div class="tab-pane fade" id="tab-dependants">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-semibold mb-0">Dependants & Next of Kin</h6>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addDepModal">
                <i class="bi bi-plus-lg me-1"></i>Add
            </button>
        </div>
        <div class="row g-3">
        @forelse($employee->dependants as $d)
            <div class="col-md-4">
                <div class="card border">
                    <div class="card-body py-2 px-3">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="fw-semibold">{{ $d->fullname }}</div>
                                <small class="text-muted">{{ ucfirst($d->relationship) }} &nbsp;·&nbsp; {{ $d->gender }}</small>
                                @if($d->is_next_of_kin)<span class="badge bg-warning-subtle text-warning ms-1">Next of Kin</span>@endif
                            </div>
                            <form method="POST" action="{{ route('hr.employees.dependants.destroy',[$employee,$d]) }}" onsubmit="return confirm('Remove?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-sm btn-light text-danger py-0"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                        @if($d->phone)<div class="small mt-1"><i class="bi bi-phone me-1"></i>{{ $d->phone }}</div>@endif
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12 text-center text-muted py-4">No dependants added.</div>
        @endforelse
        </div>
    </div>

    {{-- ── BANK ACCOUNTS TAB ────────────────────────────── --}}
    <div class="tab-pane fade" id="tab-bank">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-semibold mb-0">Bank Accounts</h6>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addBankModal">
                <i class="bi bi-plus-lg me-1"></i>Add Account
            </button>
        </div>
        <table class="table table-sm table-hover">
            <thead class="table-light">
                <tr><th>Bank</th><th>Account Name</th><th>Account No.</th><th>Branch</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($employee->bankAccounts as $ba)
                <tr>
                    <td class="fw-semibold">{{ $ba->bank?->name ?? '—' }}</td>
                    <td>{{ $ba->account_name }}</td>
                    <td class="font-monospace">{{ $ba->account_number }}</td>
                    <td>{{ $ba->branch ?? '—' }}</td>
                    <td>
                        @if($ba->is_active)<span class="badge bg-success-subtle text-success">Active</span>
                        @else<span class="badge bg-secondary-subtle text-secondary">Inactive</span>@endif
                    </td>
                    <td>
                        <form method="POST" action="{{ route('hr.employees.bank-accounts.destroy',[$employee,$ba]) }}" onsubmit="return confirm('Remove?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-light text-danger py-0"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-3">No bank accounts added.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── DOCUMENTS TAB ────────────────────────────────── --}}
    <div class="tab-pane fade" id="tab-documents">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-semibold mb-0">Documents</h6>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addDocModal">
                <i class="bi bi-upload me-1"></i>Upload Document
            </button>
        </div>
        <div class="row g-3">
        @forelse($employee->documents as $doc)
            <div class="col-md-4">
                <div class="card border">
                    <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                        <i class="bi bi-file-earmark-{{ str_contains($doc->file_name,'.pdf') ? 'pdf text-danger' : 'image text-primary' }} fs-3"></i>
                        <div class="flex-fill min-w-0">
                            <div class="fw-semibold text-truncate small">{{ $doc->file_name }}</div>
                            <small class="text-muted">{{ $doc->file_type }}</small>
                        </div>
                        <div class="d-flex gap-1">
                            <a href="{{ $doc->file_url }}" target="_blank" class="btn btn-sm btn-light py-0"><i class="bi bi-eye"></i></a>
                            <form method="POST" action="{{ route('hr.employees.documents.destroy',[$employee,$doc]) }}" onsubmit="return confirm('Delete?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-light text-danger py-0"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12 text-center text-muted py-4">No documents uploaded.</div>
        @endforelse
        </div>
    </div>

</div>{{-- end tab-content --}}

{{-- ═══════════════ MODALS ═══════════════ --}}

{{-- Add Contract --}}
<div class="modal fade" id="addContractModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('hr.employees.contracts.store', $employee) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">New Contract</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label required-star">Contract Type</label>
                        <select name="nature_of_contract_id" class="form-select" required>
                            <option value="">— Select —</option>
                            @foreach($contractTypes as $ct)
                                <option value="{{ $ct->id }}">{{ $ct->contract_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Salary Scale</label>
                        <select name="salary_scale_id" class="form-select">
                            <option value="">— None —</option>
                            @foreach($salaryScales as $ss)
                                <option value="{{ $ss->id }}">{{ $ss->name }} ({{ number_format($ss->minimum) }} – {{ number_format($ss->maximum) }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required-star">Basic Salary (TZS)</label>
                        <input type="number" name="basic_salary" class="form-control" min="0" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required-star">Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Period (months)</label>
                        <input type="number" name="period" class="form-control" min="1">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Comment</label>
                        <textarea name="comment" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Contract</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Add Qualification --}}
<div class="modal fade" id="addQualModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('hr.employees.qualifications.store', $employee) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add Qualification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label required-star">Education Level</label>
                        <select name="education_level" class="form-select" required>
                            @foreach(['Primary','Secondary (O-Level)','Secondary (A-Level)','Certificate','Diploma','Advanced Diploma','Bachelor\'s Degree','Postgraduate Diploma','Master\'s Degree','PhD','Other'] as $el)
                                <option>{{ $el }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Institution</label>
                        <input type="text" name="institution" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Program / Course</label>
                        <input type="text" name="program" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Start Year</label>
                        <input type="text" name="date_of_start" class="form-control" placeholder="e.g. 2015">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">End Year</label>
                        <input type="text" name="date_of_end" class="form-control" placeholder="e.g. 2018">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Add Experience --}}
<div class="modal fade" id="addExpModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('hr.employees.experiences.store', $employee) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add Work Experience</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label required-star">Company / Organisation</label>
                        <input type="text" name="company_name" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label required-star">Position Held</label>
                        <input type="text" name="position" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required-star">Start Date</label>
                        <input type="text" name="start_date" class="form-control" placeholder="MM/YYYY" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">End Date</label>
                        <input type="text" name="end_date" class="form-control" placeholder="MM/YYYY or leave blank">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Monthly Salary</label>
                        <input type="number" name="salary" class="form-control" min="0">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Add Dependant --}}
<div class="modal fade" id="addDepModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('hr.employees.dependants.store', $employee) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add Dependant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label required-star">Full Name</label>
                        <input type="text" name="fullname" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required-star">Relationship</label>
                        <select name="relationship" class="form-select" required>
                            @foreach(['Spouse','Child','Parent','Sibling','Other'] as $r)
                                <option>{{ $r }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required-star">Gender</label>
                        <select name="gender" class="form-select" required>
                            <option>Male</option><option>Female</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="date_of_birth" class="form-control">
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_next_of_kin" value="1" id="isNOK">
                            <label class="form-check-label" for="isNOK">Set as Next of Kin</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Add Bank Account --}}
<div class="modal fade" id="addBankModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('hr.employees.bank-accounts.store', $employee) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add Bank Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label required-star">Bank</label>
                        <select name="bank_id" class="form-select" required>
                            <option value="">— Select Bank —</option>
                            @foreach($banks as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label required-star">Account Name</label>
                        <input type="text" name="account_name" class="form-control" required value="{{ $employee->full_name }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required-star">Account Number</label>
                        <input type="text" name="account_number" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Branch</label>
                        <input type="text" name="branch" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Upload Document --}}
<div class="modal fade" id="addDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('hr.employees.documents.store', $employee) }}" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Upload Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label required-star">Document Type</label>
                        <select name="file_type" class="form-select" required>
                            @foreach(['Birth Certificate','National ID Copy','Passport Copy','Academic Certificate','Employment Letter','Contract Copy','Medical Certificate','Other'] as $ft)
                                <option>{{ $ft }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label required-star">File (PDF, JPG, PNG — max 5MB)</label>
                        <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
