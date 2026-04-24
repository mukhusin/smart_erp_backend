@extends('layouts.app')
@section('title', 'Department Employees')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hod.dashboard') }}">HOD</a></li>
    <li class="breadcrumb-item active">Employees</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-people me-2 text-primary"></i>Department Employees</h5>
    <span class="badge bg-secondary fs-6">{{ $employees->total() }} total</span>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Employee No</th>
                    <th>Name</th>
                    <th>Job Position</th>
                    <th>Contract Type</th>
                    <th>Employment Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($employees as $emp)
                    <tr>
                        <td class="fw-medium text-primary">{{ $emp->employee_no }}</td>
                        <td>{{ $emp->full_name }}</td>
                        <td>{{ $emp->jobPosition?->title ?? '—' }}</td>
                        <td>{{ $emp->activeContract?->natureOfContract?->name ?? '—' }}</td>
                        <td>{{ $emp->employment_date?->format('d M Y') ?? '—' }}</td>
                        <td>
                            <span class="badge bg-{{ $emp->employment_status === 'active' ? 'success' : 'secondary' }}">
                                {{ ucfirst($emp->employment_status) }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No employees found in your department.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($employees->hasPages())
        <div class="card-footer bg-transparent">{{ $employees->links() }}</div>
    @endif
</div>
@endsection
