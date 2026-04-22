@extends('layouts.app')
@section('title','Salary Scales')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Salary Scales</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div><h5 class="page-title">Salary Scales</h5><p class="page-subtitle">Define salary bands per position</p></div>
    <a href="{{ route('hr.salary-scales.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Scale</a>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Scale Name</th><th>Position</th><th class="text-end">Minimum</th><th class="text-end">Maximum</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($scales as $i => $s)
                <tr>
                    <td>{{ $i+1 }}</td>
                    <td class="fw-semibold">{{ $s->name }}</td>
                    <td>{{ $s->jobPosition?->title ?? '—' }}</td>
                    <td class="text-end">{{ number_format($s->minimum) }}</td>
                    <td class="text-end">{{ number_format($s->maximum) }}</td>
                    <td class="text-end">
                        <a href="{{ route('hr.salary-scales.edit',$s) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('hr.salary-scales.destroy',$s) }}" class="d-inline" onsubmit="return confirm('Delete?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No salary scales defined.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
