@extends('layouts.app')
@section('title','Public Holidays')
@section('breadcrumb')
    <li class="breadcrumb-item active">Public Holidays</li>
@endsection
@section('content')
<div class="row g-4">
<div class="col-lg-5">
<div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-star me-2"></i>Add Public Holiday</div>
    <div class="card-body">
        <form method="POST" action="{{ route('leave.public-holidays.store') }}">
            @csrf
            <div class="mb-3"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" required value="{{ old('title') }}"></div>
            <div class="row g-2 mb-3">
                <div class="col"><label class="form-label">Day</label><input type="number" name="day_number" class="form-control" min="1" max="31" required value="{{ old('day_number') }}"></div>
                <div class="col">
                    <label class="form-label">Month</label>
                    <select name="month_number" class="form-select" required>
                        @php $months=['January','February','March','April','May','June','July','August','September','October','November','December']; @endphp
                        @foreach($months as $i=>$m)<option value="{{ $i+1 }}" {{ old('month_number')==($i+1)?'selected':'' }}>{{ $m }}</option>@endforeach
                    </select>
                </div>
            </div>
            <button class="btn btn-primary w-100">Add Holiday</button>
        </form>
    </div>
</div>
</div>
<div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Holidays List</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>#</th><th>Title</th><th>Date</th><th>Active</th><th></th></tr></thead>
            <tbody>
                @forelse($holidays as $h)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $h->title }}</td>
                    <td>{{ $h->day_number }}/{{ $h->month_number }}</td>
                    <td><span class="badge {{ $h->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $h->is_active ? 'Yes' : 'No' }}</span></td>
                    <td class="text-end">
                        <form method="POST" action="{{ route('leave.public-holidays.destroy', $h) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No holidays defined.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</div>
</div>
@endsection
