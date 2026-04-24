@extends('layouts.app')
@section('title', 'My Duty Roster')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('self-service.dashboard') }}">Self-Service</a></li>
    <li class="breadcrumb-item active">My Duty Roster</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-calendar3-week me-2 text-primary"></i>My Duty Roster</h5>
    <span class="text-muted small">{{ $employee->department?->name }}</span>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th>Department</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Year</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($rosters as $roster)
                    <tr>
                        <td class="fw-medium">{{ $roster->title }}</td>
                        <td>{{ $roster->department?->name }}</td>
                        <td>{{ \Carbon\Carbon::parse($roster->from_date)->format('d M Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($roster->to_date)->format('d M Y') }}</td>
                        <td>{{ $roster->roster_year }}</td>
                        <td>
                            @php $cls = match($roster->status) {
                                'approved' => 'bg-success', 'pending' => 'bg-warning text-dark',
                                'rejected' => 'bg-danger', default => 'bg-secondary'
                            }; @endphp
                            <span class="badge {{ $cls }}">{{ ucfirst($roster->status) }}</span>
                        </td>
                        <td>
                            <a href="{{ route('roster.duty.show', $roster) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-calendar2 fs-3 d-block mb-2"></i>
                            No active duty rosters assigned to your department.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($rosters->hasPages())
        <div class="card-footer bg-transparent">{{ $rosters->links() }}</div>
    @endif
</div>
@endsection
