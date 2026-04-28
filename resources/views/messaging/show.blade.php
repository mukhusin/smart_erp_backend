@extends('layouts.app')
@section('title','Message Details')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('messaging.index') }}">Messaging</a></li>
    <li class="breadcrumb-item active">Details</li>
@endsection

@section('content')
@php
    $statusColor = match($messaging->status) {
        'Sent','Delivered' => 'success', 'Failed' => 'danger', default => 'warning'
    };
@endphp

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h5 class="page-title">{{ $messaging->subject ?: ucwords($messaging->type) . ' Message' }}</h5>
        <p class="page-subtitle mb-0">
            Scheduled: {{ $messaging->scheduled_at->format('d M Y H:i') }}
            &nbsp;·&nbsp;
            <span class="badge bg-{{ $statusColor }}">{{ $messaging->status }}</span>
            &nbsp;·&nbsp;
            <span class="badge bg-{{ $messaging->type==='SMS'?'primary':'info' }}">{{ $messaging->type }}</span>
        </p>
    </div>
    @if($messaging->status === 'Pending')
    <div class="d-flex gap-2">
        <a href="{{ route('messaging.edit', $messaging) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i> Edit
        </a>
        <form method="POST" action="{{ route('messaging.destroy', $messaging) }}"
              onsubmit="return confirm('Delete this message?')">
            @csrf @method('DELETE')
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button>
        </form>
    </div>
    @endif
</div>

<div class="row g-3">
    <div class="col-md-7">
        <div class="card mb-3">
            <div class="card-header fw-semibold">Message Content</div>
            <div class="card-body">
                @if($messaging->subject)
                <p class="small text-muted mb-1">Subject</p>
                <p class="fw-semibold">{{ $messaging->subject }}</p>
                <hr>
                @endif
                <p class="small text-muted mb-1">Body</p>
                <p style="white-space:pre-wrap">{{ $messaging->message }}</p>
                @if($messaging->extra)
                <hr>
                <p style="white-space:pre-wrap">{{ $messaging->extra }}</p>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">
                Recipients <span class="badge bg-secondary ms-1">{{ $messaging->recipients->count() }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size:.83rem">
                    <thead class="table-light">
                        <tr><th>Employee</th><th>Address</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse($messaging->recipients as $r)
                        @php $rc = match($r->status) { 'Sent','Delivered'=>'success','Failed'=>'danger',default=>'warning' }; @endphp
                        <tr>
                            <td>{{ $r->employee?->full_name ?? '—' }}</td>
                            <td>{{ $r->recipient_address }}</td>
                            <td><span class="badge bg-{{ $rc }}">{{ $r->status }}</span></td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="text-center text-muted py-3">No recipients.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card">
            <div class="card-header fw-semibold">Details</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted">Created by</td><td>{{ $messaging->creator?->name ?? '—' }}</td></tr>
                    <tr><td class="text-muted">Created at</td><td>{{ $messaging->created_at->format('d M Y H:i') }}</td></tr>
                    <tr><td class="text-muted">Recipient type</td><td>{{ ucwords(str_replace('_',' ',$messaging->recipient_type)) }}</td></tr>
                    @if($messaging->department)<tr><td class="text-muted">Department</td><td>{{ $messaging->department->name }}</td></tr>@endif
                    @if($messaging->section)<tr><td class="text-muted">Section</td><td>{{ $messaging->section->name }}</td></tr>@endif
                    <tr><td class="text-muted">From</td><td>{{ $messaging->from_address }}</td></tr>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
