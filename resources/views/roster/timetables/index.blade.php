@extends('layouts.app')
@section('title','Timetables')
@section('breadcrumb')<li class="breadcrumb-item active">Timetables</li>@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Roster Timetables</h5>
    <a href="{{ route('roster.timetables.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New</a>
</div>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>#</th><th>Title</th><th>Department</th><th>Check-In</th><th>Check-Out</th><th>Work Hrs</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($timetables as $tt)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $tt->title }}</td>
                    <td>{{ $tt->department?->name }}</td>
                    <td>{{ $tt->check_in_start }} – {{ $tt->check_in_end }}</td>
                    <td>{{ $tt->check_out_start }} – {{ $tt->check_out_end }}</td>
                    <td>{{ $tt->work_hour }}</td>
                    <td><span class="badge {{ $tt->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $tt->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('roster.timetables.edit', $tt) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('roster.timetables.destroy', $tt) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No timetables defined.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($timetables->hasPages())<div class="card-footer">{{ $timetables->links() }}</div>@endif
</div>
@endsection
