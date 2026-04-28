@extends('layouts.app')
@section('title','New Message')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('messaging.index') }}">Messaging</a></li>
    <li class="breadcrumb-item active">New Message</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-9">
<div class="card">
    <div class="card-header"><h6 class="mb-0">Schedule a Message</h6></div>
    <div class="card-body">
        @if($errors->any())
            <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('messaging.store') }}" id="msgForm">
            @csrf

            {{-- Type --}}
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Message Type <span class="text-danger">*</span></label>
                    <select name="type" id="msgType" class="form-select @error('type') is-invalid @enderror" required>
                        <option value="">— Select —</option>
                        <option value="SMS"   {{ old('type')=='SMS'  ?'selected':'' }}>SMS</option>
                        <option value="Email" {{ old('type')=='Email'?'selected':'' }}>Email</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Scheduled At <span class="text-danger">*</span></label>
                    <input name="scheduled_at" type="datetime-local" class="form-control @error('scheduled_at') is-invalid @enderror"
                           value="{{ old('scheduled_at') }}"
                           min="{{ now()->addMinutes(30)->format('Y-m-d\TH:i') }}" required>
                </div>
            </div>

            {{-- Email subject (hidden for SMS) --}}
            <div class="mb-3" id="subjectRow" style="{{ old('type')!='Email'?'display:none':'' }}">
                <label class="form-label">Subject</label>
                <input name="subject" class="form-control @error('subject') is-invalid @enderror"
                       value="{{ old('subject') }}" placeholder="Email subject…">
            </div>

            {{-- Message body --}}
            <div class="mb-1">
                <label class="form-label">Message Body <span class="text-danger">*</span></label>
                <textarea name="message" id="msgBody" rows="4"
                    class="form-control @error('message') is-invalid @enderror" required>{{ old('message') }}</textarea>
                <div class="d-flex justify-content-between mt-1">
                    <small class="text-muted" id="charInfo">Max 120 chars for SMS, 1000 for Email</small>
                    <small class="text-muted" id="charCount">0</small>
                </div>
            </div>

            <div class="mb-3" id="extraRow" style="{{ old('type')!='Email'?'display:none':'' }}">
                <label class="form-label">Additional Body (optional)</label>
                <textarea name="extra" rows="3" class="form-control" placeholder="Additional paragraphs…">{{ old('extra') }}</textarea>
            </div>

            {{-- Recipients --}}
            <hr>
            <div class="mb-3">
                <label class="form-label">Recipient Group <span class="text-danger">*</span></label>
                <select name="recipient_type" id="recipientType" class="form-select @error('recipient_type') is-invalid @enderror" required>
                    <option value="">— Select —</option>
                    <option value="individual"       {{ old('recipient_type')=='individual'?'selected':'' }}>Individual Employees</option>
                    <option value="department"       {{ old('recipient_type')=='department'?'selected':'' }}>Department</option>
                    <option value="section"          {{ old('recipient_type')=='section'?'selected':'' }}>Section</option>
                    <option value="active_contracts" {{ old('recipient_type')=='active_contracts'?'selected':'' }}>All Active Employees</option>
                </select>
            </div>

            {{-- Individual picker --}}
            <div id="individualPicker" class="mb-3" style="display:none">
                <label class="form-label">Select Employees</label>
                <select name="employee_ids[]" class="form-select" multiple size="6">
                    @foreach($employees as $emp)
                    <option value="{{ $emp->id }}" {{ in_array($emp->id, old('employee_ids',[]))?'selected':'' }}>
                        {{ $emp->full_name }}
                    </option>
                    @endforeach
                </select>
                <small class="text-muted">Hold Ctrl/Cmd to select multiple.</small>
            </div>

            {{-- Department picker --}}
            <div id="departmentPicker" class="mb-3" style="display:none">
                <label class="form-label">Department</label>
                <select name="department_id" class="form-select @error('department_id') is-invalid @enderror">
                    <option value="">— Select Department —</option>
                    @foreach($departments as $dept)
                    <option value="{{ $dept->id }}" {{ old('department_id')==$dept->id?'selected':'' }}>{{ $dept->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Section picker --}}
            <div id="sectionPicker" class="mb-3" style="display:none">
                <label class="form-label">Section</label>
                <select name="section_id" class="form-select @error('section_id') is-invalid @enderror">
                    <option value="">— Select Section —</option>
                    @foreach($sections as $sec)
                    <option value="{{ $sec->id }}" {{ old('section_id')==$sec->id?'selected':'' }}>{{ $sec->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-send me-1"></i> Schedule Message
                </button>
                <a href="{{ route('messaging.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection

@push('scripts')
<script>
const typeEl      = document.getElementById('msgType');
const bodyEl      = document.getElementById('msgBody');
const countEl     = document.getElementById('charCount');
const subjectRow  = document.getElementById('subjectRow');
const extraRow    = document.getElementById('extraRow');
const recipType   = document.getElementById('recipientType');

function updateTypeUI() {
    const isSms = typeEl.value === 'SMS';
    subjectRow.style.display = isSms ? 'none' : '';
    extraRow.style.display   = isSms ? 'none' : '';
    countEl.textContent = bodyEl.value.length + ' / ' + (isSms ? 120 : 1000);
}

function updateRecipientUI() {
    ['individualPicker','departmentPicker','sectionPicker'].forEach(id =>
        document.getElementById(id).style.display = 'none'
    );
    const map = { individual: 'individualPicker', department: 'departmentPicker', section: 'sectionPicker' };
    if (map[recipType.value]) document.getElementById(map[recipType.value]).style.display = '';
}

typeEl.addEventListener('change', updateTypeUI);
bodyEl.addEventListener('input', updateTypeUI);
recipType.addEventListener('change', updateRecipientUI);

updateTypeUI();
updateRecipientUI();
</script>
@endpush
