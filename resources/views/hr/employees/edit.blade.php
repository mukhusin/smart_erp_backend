@extends('layouts.app')
@section('title','Edit Employee')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hr.employees.index') }}">Employees</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hr.employees.show', $employee) }}">{{ $employee->full_name }}</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-xl-9">
<div class="card">
    <div class="card-header bg-white py-3 fw-semibold">
        <i class="bi bi-pencil me-2 text-primary"></i>Edit — {{ $employee->full_name }}
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('hr.employees.update', $employee) }}" enctype="multipart/form-data">
            @csrf @method('PUT')

            <div class="row g-3">
                <div class="col-12 text-center mb-2">
                    <label for="profile_image" style="cursor:pointer">
                        <img id="photoPreview" src="{{ $employee->profile_image_url }}"
                             class="rounded-circle border" width="90" height="90" style="object-fit:cover">
                        <div class="small text-primary mt-1"><i class="bi bi-camera me-1"></i>Change Photo</div>
                    </label>
                    <input type="file" id="profile_image" name="profile_image" class="d-none" accept="image/*"
                           onchange="document.getElementById('photoPreview').src=URL.createObjectURL(this.files[0])">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Title</label>
                    <select name="emp_title" class="form-select">
                        <option value="">—</option>
                        @foreach(['Mr','Mrs','Ms','Dr','Prof','Eng'] as $t)
                            <option {{ old('emp_title',$employee->emp_title)==$t?'selected':'' }}>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label required-star">First Name</label>
                    <input type="text" name="fname" class="form-control" value="{{ old('fname',$employee->fname) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Middle Name</label>
                    <input type="text" name="mname" class="form-control" value="{{ old('mname',$employee->mname) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label required-star">Last Name</label>
                    <input type="text" name="lname" class="form-control" value="{{ old('lname',$employee->lname) }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label required-star">Gender</label>
                    <select name="gender" class="form-select">
                        <option {{ old('gender',$employee->gender)=='Male'?'selected':'' }}>Male</option>
                        <option {{ old('gender',$employee->gender)=='Female'?'selected':'' }}>Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" value="{{ old('date_of_birth',$employee->date_of_birth?->format('Y-m-d')) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Marital Status</label>
                    <select name="marital_status" class="form-select">
                        <option value="">—</option>
                        @foreach(['Single','Married','Divorced','Widowed'] as $ms)
                            <option {{ old('marital_status',$employee->marital_status)==$ms?'selected':'' }}>{{ $ms }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Nationality</label>
                    <input type="text" name="nationality" class="form-control" value="{{ old('nationality',$employee->nationality) }}">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone_number" class="form-control" value="{{ old('phone_number',$employee->phone_number) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="{{ old('email',$employee->email) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">TIN</label>
                    <input type="text" name="tin" class="form-control" value="{{ old('tin',$employee->tin) }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label">ID Type</label>
                    <select name="id_type" class="form-select">
                        @foreach(['NIN','NIDA','Passport','Voter ID','Driver License'] as $t)
                            <option {{ old('id_type',$employee->id_type)==$t?'selected':'' }}>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">ID Number</label>
                    <input type="text" name="id_number" class="form-control" value="{{ old('id_number',$employee->id_number) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">NSSF No.</label>
                    <input type="text" name="nssf_no" class="form-control" value="{{ old('nssf_no',$employee->nssf_no) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">NHIF No.</label>
                    <input type="text" name="nhif_no" class="form-control" value="{{ old('nhif_no',$employee->nhif_no) }}">
                </div>

                <div class="col-12"><hr class="my-1"><p class="small fw-semibold text-muted mb-0">Employment Details</p></div>

                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($departments as $d)
                            <option value="{{ $d->id }}" {{ old('department_id',$employee->department_id)==$d->id?'selected':'' }}>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Job Position</label>
                    <select name="job_position_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($positions as $p)
                            <option value="{{ $p->id }}" {{ old('job_position_id',$employee->job_position_id)==$p->id?'selected':'' }}>{{ $p->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Branch</label>
                    <select name="company_branch_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('company_branch_id',$employee->company_branch_id)==$b->id?'selected':'' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Employment Date</label>
                    <input type="date" name="employment_date" class="form-control" value="{{ old('employment_date',$employee->employment_date?->format('Y-m-d')) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Employment Status</label>
                    <select name="employment_status" class="form-select">
                        @foreach(['probation','employed','terminated','suspended','resigned'] as $st)
                            <option {{ old('employment_status',$employee->employment_status)==$st?'selected':'' }}>{{ ucfirst($st) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Emp Type</label>
                    <select name="emp_type" class="form-select">
                        <option value="">—</option>
                        @foreach(['Permanent','Contract','Intern','Casual','Volunteer'] as $et)
                            <option {{ old('emp_type',$employee->emp_type)==$et?'selected':'' }}>{{ $et }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Emp Category</label>
                    <select name="emp_category" class="form-select">
                        <option {{ old('emp_category',$employee->emp_category)=='Primary'?'selected':'' }}>Primary</option>
                        <option {{ old('emp_category',$employee->emp_category)=='Secondary'?'selected':'' }}>Secondary</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Home Address</label>
                    <textarea name="home_address" class="form-control" rows="2">{{ old('home_address',$employee->home_address) }}</textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                <a href="{{ route('hr.employees.show', $employee) }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
