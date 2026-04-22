@extends('layouts.app')
@section('title','Add Employee')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hr.employees.index') }}">Employees</a></li>
    <li class="breadcrumb-item active">Add</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-xl-9">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold">
        <i class="bi bi-person-plus me-2 text-primary"></i>New Employee — Basic Information
        <div class="text-muted small fw-normal mt-1">Complete details like contracts, documents and qualifications after saving.</div>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('hr.employees.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="row g-3">
                {{-- Profile photo --}}
                <div class="col-12 text-center mb-2">
                    <label for="profile_image" class="d-inline-block" style="cursor:pointer">
                        <img id="photoPreview" src="https://ui-avatars.com/api/?name=Employee&background=0d6efd&color=fff&size=128"
                             class="rounded-circle border" width="90" height="90" style="object-fit:cover">
                        <div class="small text-primary mt-1"><i class="bi bi-camera me-1"></i>Upload Photo</div>
                    </label>
                    <input type="file" id="profile_image" name="profile_image" class="d-none" accept="image/*"
                           onchange="document.getElementById('photoPreview').src=URL.createObjectURL(this.files[0])">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Title</label>
                    <select name="emp_title" class="form-select">
                        <option value="">—</option>
                        @foreach(['Mr','Mrs','Ms','Dr','Prof','Eng'] as $t)
                            <option {{ old('emp_title')==$t?'selected':'' }}>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">First Name</label>
                    <input type="text" name="fname" class="form-control @error('fname') is-invalid @enderror" value="{{ old('fname') }}">
                    @error('fname')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Middle Name</label>
                    <input type="text" name="mname" class="form-control" value="{{ old('mname') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label required-star">Last Name</label>
                    <input type="text" name="lname" class="form-control @error('lname') is-invalid @enderror" value="{{ old('lname') }}">
                    @error('lname')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label required-star">Gender</label>
                    <select name="gender" class="form-select @error('gender') is-invalid @enderror">
                        <option value="">— Select —</option>
                        <option {{ old('gender')=='Male'?'selected':'' }}>Male</option>
                        <option {{ old('gender')=='Female'?'selected':'' }}>Female</option>
                    </select>
                    @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" value="{{ old('date_of_birth') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Marital Status</label>
                    <select name="marital_status" class="form-select">
                        <option value="">—</option>
                        @foreach(['Single','Married','Divorced','Widowed'] as $ms)
                            <option {{ old('marital_status')==$ms?'selected':'' }}>{{ $ms }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Nationality</label>
                    <input type="text" name="nationality" class="form-control" value="{{ old('nationality','Tanzanian') }}">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone_number" class="form-control" value="{{ old('phone_number') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="{{ old('email') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">TIN Number</label>
                    <input type="text" name="tin" class="form-control" value="{{ old('tin') }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label">ID Type</label>
                    <select name="id_type" class="form-select">
                        @foreach(['NIN','NIDA','Passport','Voter ID','Driver License'] as $t)
                            <option {{ old('id_type','NIN')==$t?'selected':'' }}>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">ID Number</label>
                    <input type="text" name="id_number" class="form-control" value="{{ old('id_number') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">NSSF No.</label>
                    <input type="text" name="nssf_no" class="form-control" value="{{ old('nssf_no') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">NHIF No.</label>
                    <input type="text" name="nhif_no" class="form-control" value="{{ old('nhif_no') }}">
                </div>

                <div class="col-12"><hr class="my-1"><p class="small fw-semibold text-muted mb-0">Employment Details</p></div>

                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select name="department_id" class="form-select" id="deptSelect">
                        <option value="">— Select —</option>
                        @foreach($departments as $d)
                            <option value="{{ $d->id }}" {{ old('department_id')==$d->id?'selected':'' }}>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Job Position</label>
                    <select name="job_position_id" class="form-select">
                        <option value="">— Select —</option>
                        @foreach($positions as $p)
                            <option value="{{ $p->id }}" {{ old('job_position_id')==$p->id?'selected':'' }}>{{ $p->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Branch</label>
                    <select name="company_branch_id" class="form-select">
                        <option value="">— Select —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('company_branch_id')==$b->id?'selected':'' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Employment Date</label>
                    <input type="date" name="employment_date" class="form-control" value="{{ old('employment_date') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Employment Status</label>
                    <select name="employment_status" class="form-select">
                        @foreach(['probation','employed','terminated','suspended','resigned'] as $st)
                            <option {{ old('employment_status','probation')==$st?'selected':'' }}>{{ ucfirst($st) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Emp Type</label>
                    <select name="emp_type" class="form-select">
                        <option value="">—</option>
                        @foreach(['Permanent','Contract','Intern','Casual','Volunteer'] as $et)
                            <option {{ old('emp_type')==$et?'selected':'' }}>{{ $et }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Emp Category</label>
                    <select name="emp_category" class="form-select">
                        <option {{ old('emp_category','Primary')=='Primary'?'selected':'' }}>Primary</option>
                        <option {{ old('emp_category')=='Secondary'?'selected':'' }}>Secondary</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Home Address</label>
                    <textarea name="home_address" class="form-control" rows="2">{{ old('home_address') }}</textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save & Continue</button>
                <a href="{{ route('hr.employees.index') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
