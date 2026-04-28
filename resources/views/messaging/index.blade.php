@extends('layouts.app')
@section('title','Messaging')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Messaging</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Messaging</h5>
        <p class="page-subtitle">Schedule SMS and Email campaigns to employees</p>
    </div>
    <a href="{{ route('messaging.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> New Message
    </a>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show py-2 small">
        <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Type</th>
                    <th>Subject / Preview</th>
                    <th>Recipient</th>
                    <th>Recipients</th>
                    <th>Scheduled</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($messages as $msg)
                @php
                    $statusColor = match($msg->status) {
                        'Sent','Delivered' => 'success',
                        'Failed'           => 'danger',
                        default            => 'warning',
                    };
                @endphp
                <tr>
                    <td>
                        <span class="badge bg-{{ $msg->type === 'SMS' ? 'primary' : 'info' }}">
                            <i class="bi bi-{{ $msg->type === 'SMS' ? 'phone' : 'envelope' }} me-1"></i>{{ $msg->type }}
                        </span>
                    </td>
                    <td>
                        @if($msg->subject)<div class="fw-semibold small">{{ Str::limit($msg->subject,40) }}</div>@endif
                        <div class="text-muted small">{{ Str::limit($msg->message, 60) }}</div>
                    </td>
                    <td class="small">{{ ucwords(str_replace('_',' ',$msg->recipient_type)) }}</td>
                    <td><span class="badge bg-secondary">{{ $msg->recipients->count() }}</span></td>
                    <td class="small">{{ $msg->scheduled_at->format('d M Y H:i') }}</td>
                    <td><span class="badge bg-{{ $statusColor }}">{{ $msg->status }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('messaging.show', $msg) }}" class="btn btn-sm btn-outline-primary me-1">
                            <i class="bi bi-eye"></i>
                        </a>
                        @if($msg->status === 'Pending')
                        <a href="{{ route('messaging.edit', $msg) }}" class="btn btn-sm btn-outline-secondary me-1">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="POST" action="{{ route('messaging.destroy', $msg) }}" class="d-inline"
                              onsubmit="return confirm('Delete this message?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No messages scheduled yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($messages->hasPages())
    <div class="card-footer">{{ $messages->links() }}</div>
    @endif
</div>
@endsection
