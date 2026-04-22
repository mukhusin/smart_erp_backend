@extends('layouts.app')
@section('title','Attendance Transactions')
@section('breadcrumb')<li class="breadcrumb-item active">Attendance</li>@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Attendance Transactions</h5>
</div>
<form class="row g-2 mb-3" method="GET">
    <div class="col-md-3">
        <select name="employee_id" class="form-select form-select-sm">
            <option value="">All Employees</option>
            @foreach($employees as $e)<option value="{{ $e->id }}" {{ request('employee_id')==$e->id?'selected':'' }}>{{ $e->full_name }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-3">
        <select name="department_id" class="form-select form-select-sm">
            <option value="">All Departments</option>
            @foreach($departments as $d)<option value="{{ $d->id }}" {{ request('department_id')==$d->id?'selected':'' }}>{{ $d->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-2"><input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}" placeholder="From"></div>
    <div class="col-md-2"><input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}" placeholder="To"></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
</form>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Employee</th><th>Date</th><th>Punch In</th><th>Punch Out</th><th>Work Hrs</th><th>Worked Hrs</th><th>Lost Hrs</th><th>Lost Amt</th><th>Overtime</th></tr>
            </thead>
            <tbody>
                @forelse($transactions as $tr)
                <tr>
                    <td>{{ $transactions->firstItem()+$loop->index }}</td>
                    <td>{{ $tr->employee?->full_name }}</td>
                    <td>{{ $tr->transaction_date }}</td>
                    <td>{{ $tr->punch_in_time ?? '—' }}</td>
                    <td>{{ $tr->punch_out_time ?? '—' }}</td>
                    <td>{{ $tr->worktime_hour }}</td>
                    <td>{{ $tr->worked_hour }}</td>
                    <td>{{ $tr->lost_hour > 0 ? number_format($tr->lost_hour,2) : '—' }}</td>
                    <td>{{ $tr->lost_amount > 0 ? number_format($tr->lost_amount,2) : '—' }}</td>
                    <td>
                        @if($tr->has_overtime)
                            <span class="badge bg-info">{{ $tr->overtime_hr }}h {{ $tr->overtime_minutes }}m</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="10" class="text-center text-muted py-4">No attendance records found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($transactions->hasPages())<div class="card-footer">{{ $transactions->links() }}</div>@endif
</div>
@endsection
