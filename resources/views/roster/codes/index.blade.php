@extends('layouts.app')
@section('title','Roster Codes')
@section('breadcrumb')
    <li class="breadcrumb-item active">Roster Codes</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Roster Codes</h5>
    <a href="{{ route('roster.codes.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New Code</a>
</div>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>Code</th><th>Duty</th><th>Start</th><th>End</th><th>Work Hr</th><th>Can Punch</th><th>Pass Day</th><th></th></tr></thead>
            <tbody>
                @forelse($codes as $code)
                <tr>
                    <td><span class="badge bg-primary">{{ $code->code }}</span></td>
                    <td>{{ $code->duty }}</td>
                    <td>{{ $code->start_time ?? '—' }}</td>
                    <td>{{ $code->end_time ?? '—' }}</td>
                    <td>{{ $code->work_hour ?? '—' }}</td>
                    <td>{{ $code->can_punch ? 'Yes' : 'No' }}</td>
                    <td>{{ $code->pass_day ? 'Yes' : 'No' }}</td>
                    <td class="text-end">
                        <a href="{{ route('roster.codes.edit', $code) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('roster.codes.destroy', $code) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No roster codes defined.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($codes->hasPages())<div class="card-footer">{{ $codes->links() }}</div>@endif
</div>
@endsection
