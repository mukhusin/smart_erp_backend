<?php
namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\EmployeeDependant;
use App\Models\EmployeeDocument;
use App\Models\EmployeeExperience;
use App\Models\EmployeeQualification;
use App\Models\JobPosition;
use App\Models\NatureOfContract;
use App\Models\SalaryScale;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmployeeController extends Controller
{
    public function __construct(protected CompanyContext $ctx) {}

    public function index(Request $request)
    {
        $query = Employee::with(['department','jobPosition','branch'])
            ->where('company_id', $this->ctx->id());

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('fname','like',"%$s%")
                  ->orWhere('lname','like',"%$s%")
                  ->orWhere('employee_no','like',"%$s%")
                  ->orWhere('email','like',"%$s%");
            });
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('employment_status')) {
            $query->where('employment_status', $request->employment_status);
        }

        $employees   = $query->orderBy('fname')->paginate(20)->withQueryString();
        $departments = Department::where('company_id',$this->ctx->id())->whereNull('parent_id')->orderBy('name')->get();

        return view('hr.employees.index', compact('employees','departments'));
    }

    public function create()
    {
        return view('hr.employees.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateEmployee($request);

        if ($request->hasFile('profile_image')) {
            $data['profile_image'] = $request->file('profile_image')->store('employees/photos', 'public');
        }

        $data['company_id']        = $this->ctx->id();
        $data['employee_no']       = $this->generateEmployeeNo();
        $data['employment_status'] = $data['employment_status'] ?? 'probation';

        $employee = Employee::create($data);

        return redirect()->route('hr.employees.show', $employee)->with('success', 'Employee created. Complete the profile by filling in the tabs below.');
    }

    public function show(Employee $employee)
    {
        $employee->load([
            'department','section','jobPosition','branch','activeContract.natureOfContract',
            'qualifications','experiences','dependants','bankAccounts.bank','documents',
            'contracts.natureOfContract','supervisors.supervisor',
        ]);
        $banks            = Bank::where('is_active',true)->orderBy('name')->get();
        $contractTypes    = NatureOfContract::where('company_id',$this->ctx->id())->get();
        $salaryScales     = SalaryScale::where('company_id',$this->ctx->id())->get();
        $supervisorOptions = Employee::where('company_id',$this->ctx->id())->where('id','!=',$employee->id)->orderBy('fname')->get();

        return view('hr.employees.show', compact('employee','banks','contractTypes','salaryScales','supervisorOptions'));
    }

    public function edit(Employee $employee)
    {
        return view('hr.employees.edit', array_merge(['employee' => $employee], $this->formData()));
    }

    public function update(Request $request, Employee $employee)
    {
        $data = $this->validateEmployee($request, $employee->id);

        if ($request->hasFile('profile_image')) {
            if ($employee->profile_image) Storage::disk('public')->delete($employee->profile_image);
            $data['profile_image'] = $request->file('profile_image')->store('employees/photos','public');
        }

        $employee->update($data);

        return redirect()->route('hr.employees.show', $employee)->with('success','Employee updated successfully.');
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return redirect()->route('hr.employees.index')->with('success','Employee removed.');
    }

    // ── Sub-resource store actions ────────────────────────────

    public function storeQualification(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'education_level' => 'required|string|max:100',
            'institution'     => 'nullable|string|max:150',
            'program'         => 'nullable|string|max:150',
            'date_of_start'   => 'nullable|string',
            'date_of_end'     => 'nullable|string',
            'end_year'        => 'nullable|string|max:10',
        ]);
        $employee->qualifications()->create($data);
        return back()->with('success','Qualification added.')->withFragment('tab-education');
    }

    public function destroyQualification(Employee $employee, EmployeeQualification $qualification)
    {
        $qualification->delete();
        return back()->with('success','Qualification removed.');
    }

    public function storeExperience(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'company_name' => 'required|string|max:150',
            'position'     => 'required|string|max:150',
            'start_date'   => 'required|string',
            'end_date'     => 'nullable|string',
            'salary'       => 'nullable|numeric',
            'description'  => 'nullable|string',
        ]);
        $employee->experiences()->create($data);
        return back()->with('success','Experience added.')->withFragment('tab-experience');
    }

    public function destroyExperience(Employee $employee, EmployeeExperience $experience)
    {
        $experience->delete();
        return back()->with('success','Experience removed.');
    }

    public function storeDependant(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'fullname'      => 'required|string|max:150',
            'gender'        => 'required|in:Male,Female',
            'relationship'  => 'required|string|max:50',
            'date_of_birth' => 'nullable|string',
            'phone'         => 'nullable|string|max:20',
            'email'         => 'nullable|email',
            'address'       => 'nullable|string',
        ]);
        $data['is_next_of_kin'] = $request->boolean('is_next_of_kin');
        $employee->dependants()->create($data);
        return back()->with('success','Dependant added.')->withFragment('tab-dependants');
    }

    public function destroyDependant(Employee $employee, EmployeeDependant $dependant)
    {
        $dependant->delete();
        return back()->with('success','Dependant removed.');
    }

    public function storeContract(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'nature_of_contract_id' => 'required|exists:nature_of_contracts,id',
            'salary_scale_id'       => 'nullable|exists:salary_scales,id',
            'basic_salary'          => 'required|numeric|min:0',
            'period'                => 'nullable|integer',
            'start_date'            => 'required|date',
            'end_date'              => 'nullable|date|after:start_date',
            'comment'               => 'nullable|string',
        ]);
        $employee->contracts()->where('is_active',true)->update(['is_active'=>false,'status'=>'superseded']);
        $data['is_active'] = true;
        $data['status']    = 'active';
        $contract = $employee->contracts()->create($data);
        $employee->update(['contract_id' => $contract->id, 'contract_status' => 'active']);
        return back()->with('success','Contract created and activated.')->withFragment('tab-contracts');
    }

    public function storeBankAccount(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'bank_id'        => 'required|exists:banks,id',
            'account_name'   => 'required|string|max:150',
            'account_number' => 'required|string|max:50',
            'branch'         => 'nullable|string|max:100',
        ]);
        $employee->bankAccounts()->create($data);
        return back()->with('success','Bank account added.')->withFragment('tab-bank');
    }

    public function destroyBankAccount(Employee $employee, EmployeeBankAccount $bankAccount)
    {
        $bankAccount->delete();
        return back()->with('success','Bank account removed.');
    }

    public function storeDocument(Request $request, Employee $employee)
    {
        $request->validate([
            'file_type'   => 'required|string|max:100',
            'document'    => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'description' => 'nullable|string',
        ]);
        $path = $request->file('document')->store("employees/{$employee->id}/documents", 'public');
        $employee->documents()->create([
            'file_name'   => $request->file('document')->getClientOriginalName(),
            'file_path'   => $path,
            'file_type'   => $request->file_type,
            'description' => $request->description,
        ]);
        return back()->with('success','Document uploaded.')->withFragment('tab-documents');
    }

    public function destroyDocument(Employee $employee, EmployeeDocument $document)
    {
        Storage::disk('public')->delete($document->file_path);
        $document->delete();
        return back()->with('success','Document removed.');
    }

    // ── Helpers ───────────────────────────────────────────────

    private function formData(): array
    {
        return [
            'departments'  => Department::where('company_id',$this->ctx->id())->whereNull('parent_id')->orderBy('name')->get(),
            'positions'    => JobPosition::where('company_id',$this->ctx->id())->orderBy('title')->get(),
            'branches'     => CompanyBranch::where('company_id',$this->ctx->id())->where('is_active',true)->orderBy('name')->get(),
        ];
    }

    private function validateEmployee(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'fname'              => 'required|string|max:100',
            'mname'              => 'nullable|string|max:100',
            'lname'              => 'required|string|max:100',
            'gender'             => 'required|in:Male,Female',
            'date_of_birth'      => 'nullable|date',
            'phone_number'       => 'nullable|string|max:20',
            'email'              => 'nullable|email|max:150',
            'id_number'          => ['nullable','string','max:50'],
            'id_type'            => 'nullable|string|max:30',
            'tin'                => 'nullable|string|max:20',
            'nssf_no'            => 'nullable|string|max:30',
            'nhif_no'            => 'nullable|string|max:30',
            'wcf_no'             => 'nullable|string|max:30',
            'nationality'        => 'nullable|string|max:50',
            'marital_status'     => 'nullable|string|max:30',
            'emp_title'          => 'nullable|string|max:20',
            'home_address'       => 'nullable|string',
            'place_of_birth'     => 'nullable|string|max:100',
            'employment_date'    => 'nullable|date',
            'employment_status'  => 'nullable|string|max:30',
            'emp_type'           => 'nullable|string|max:30',
            'emp_category'       => 'nullable|string|max:30',
            'department_id'      => 'nullable|exists:departments,id',
            'section_id'         => 'nullable|exists:departments,id',
            'job_position_id'    => 'nullable|exists:job_positions,id',
            'company_branch_id'  => 'nullable|exists:company_branches,id',
            'probation_start'    => 'nullable|date',
            'probation_period'   => 'nullable|integer',
            'profile_image'      => 'nullable|image|max:2048',
        ]);
    }

    private function generateEmployeeNo(): string
    {
        $last = Employee::where('company_id',$this->ctx->id())->orderBy('id','desc')->first();
        $next = $last ? ((int) ltrim(substr($last->employee_no, -5), '0') + 1) : 1;
        return 'EMP' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}
