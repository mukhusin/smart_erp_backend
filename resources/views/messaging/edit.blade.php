@extends('layouts.app')
@section('title','Edit Message')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('messaging.index') }}">Messaging</a></li>
    <li class="breadcrumb-item"><a href="{{ route('messaging.show', $messaging) }}">Details</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-9">
<div class="card">
    <div class="card-header"><h6 class="mb-0">Edit Pending Message</h6></div>
    <div class="card-body">
        @if($errors->any())
            <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('messaging.update', $messaging) }}">
            @csrf @method('PUT')

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Message Type <span class="text-danger">*</span></label>
                    <select name="type" id="msgType" class="form-select" required>
                        <option value="SMS"   {{ old('type',$messaging->type)=='SMS'  ?'selected':'' }}>SMS</option>
                        <option value="Email" {{ old('type',$messaging->type)=='Email'?'selected':'' }}>Email</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Scheduled At <span class="text-danger">*</span></label>
                    <input name="scheduled_at" type="datetime-local" class="form-control"
                           value="{{ old('scheduled_at', $messaging->scheduled_at->format('Y-m-d\TH:i')) }}"
                           min="{{ now()->addMinutes(30)->format('Y-m-d\TH:i') }}" required>
                </div>
            </div>

            <div class="mb-3" id="subjectRow">
                <label class="form-label">Subject</label>
                <input name="subject" class="form-control" value="{{ old('subject',$messaging->subject) }}">
            </div>

            <div class="mb-3">
                <label class="form-label">Message Body <span class="text-danger">*</span></label>
                <textarea name="message" id="msgBody" rows="4" class="form-control" required>{{ old('message',$messaging->message) }}</textarea>
            </div>

            <div class="mb-3" id="extraRow">
                <label class="form-label">Additional Body</label>
                <textarea name="extra" rows="3" class="form-control">{{ old('extra',$messaging->extra) }}</textarea>
            </div>

            <hr>

            <div class="mb-3">
                <label class="form-label">Recipient Group <span class="text-danger">*</span></label>
                <select name="recipient_type" id="recipientType" class="form-select" required>
                    <option value="individual"       {{ old('recipient_type',$messaging->recipient_type)=='individual'?'selected':'' }}>Individual Employees</option>
                    <option value="department"       {{ old('recipient_type',$messaging->recipient_type)=='department'?'selected':'' }}>Department</option>
                    <option value="section"          {{ old('recipient_type',$messaging->recipient_type)=='section'?'selected':'' }}>Section</option>
                    <option value="active_contracts" {{ old('recipient_type',$messaging->recipient_type)=='active_contracts'?'selected':'' }}>All Active Employees</option>
                </select>
            </div>

            <div id="individualPicker" class="mb-3" style="display:none">
                <label class="form-label">Select Employees</label>
                <select name="employee_ids[]" class="form-select" multiple size="6">
                    @php $selectedIds = $messaging->recipients->pluck('employee_id')->toArray(); @endphp
                    @foreach($employees as $emp)
                    <option value="{{ $emp->id }}" {{ in_array($emp->id, $selectedIds)?'selected':'' }}>{{ $emp->full_name }}</option>
                    @endforeach
                </select>
            </div>

            <div id="departmentPicker" class="mb-3" style="display:none">
                <label class="form-label">Department</label>
                <select name="department_id" class="form-select">
                    <option value="">— Select —</option>
                    @foreach($departments as $dept)
                    <option value="{{ $dept->id }}" {{ old('department_id',$messaging->department_id)==$dept->id?'selected':'' }}>{{ $dept->name }}</option>
                    @endforeach
                </select>
            </div>

            <div id="sectionPicker" class="mb-3" style="display:none">
                <label class="form-label">Section</label>
                <select name="section_id" class="form-select">
                    <option value="">— Select —</option>
                    @foreach($sections as $sec)
                    <option value="{{ $sec->id }}" {{ old('section_id',$messaging->section_id)==$sec->id?'selected':'' }}>{{ $sec->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Update</button>
                <a href="{{ route('messaging.show', $messaging) }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection

@push('scripts')
<script>
const typeEl    = document.getElementById('msgType');
const recipType = document.getElementById('recipientType');

function updateTypeUI() {
    const isSms = typeEl.value === 'SMS';
    document.getElementById('subjectRow').style.display = isSms ? 'none' : '';
    document.getElementById('extraRow').style.display   = isSms ? 'none' : '';
}

function updateRecipientUI() {
    ['individualPicker','departmentPicker','sectionPicker'].forEach(id =>
        document.getElementById(id).style.display = 'none'
    );
    const map = { individual: 'individualPicker', department: 'departmentPicker', section: 'sectionPicker' };
    if (map[recipType.value]) document.getElementById(map[recipType.value]).style.display = '';
}

typeEl.addEventListener('change', updateTypeUI);
recipType.addEventListener('change', updateRecipientUI);
updateTypeUI();
updateRecipientUI();
</script>
@endpush
