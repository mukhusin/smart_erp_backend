<?php

namespace Database\Seeders\Demo;

use App\Models\Bank;
use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\EmpSupervisor;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\JobPosition;
use App\Models\LeaveType;
use App\Models\LoanProvider;
use App\Models\NatureOfContract;
use App\Models\OvertimeRate;
use App\Models\PublicHoliday;
use App\Models\SalaryScale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Hr\Actions\AppointHead;
use Modules\Hr\Actions\ManageAttendance;
use Modules\Hr\Actions\ManageLeave;
use Modules\Hr\Actions\ManageLoans;
use Modules\Hr\Actions\ManageOvertime;
use Modules\Hr\Actions\ManagePayroll;

class DemoHrSeeder
{
    public const STRUCTURE = [
        'Directorate' => ['DIR', [], [['Director General', 6], ['Director of Finance', 5], ['Director of HR and Administration', 5], ['Director of Planning', 5], ['Personal Secretary', 2]]],
        'Human Resources' => ['HR', ['Recruitment', 'Payroll Office'], [['HR Manager', 4], ['HR Officer', 3], ['Payroll Officer', 3], ['Records Assistant', 1]]],
        'Finance' => ['FIN', ['Accounts', 'Billing', 'Budget'], [['Finance Manager', 4], ['Accountant', 3], ['Cashier', 2], ['Billing Officer', 2], ['Budget Officer', 3]]],
        'Planning' => ['PLN', ['Monitoring and Evaluation'], [['Planning Officer', 3], ['M&E Officer', 3]]],
        'Procurement and Supplies' => ['PMU', ['Procurement', 'Stores'], [['Procurement Manager', 4], ['Procurement Officer', 3], ['Storekeeper', 2]]],
        'Transport' => ['TRN', [], [['Transport Officer', 3], ['Driver', 1], ['Mechanic', 2]]],
        'Estates' => ['EST', ['Maintenance'], [['Asset Officer', 3], ['Electrician', 2], ['Plumber', 1]]],
        'Internal Audit' => ['IA', [], [['Internal Auditor', 4], ['Audit Assistant', 2]]],
        'ICT' => ['ICT', [], [['ICT Officer', 3], ['Systems Administrator', 3]]],
        'Nursing' => ['NUR', ['Wards', 'Theatre', 'Emergency'], [['Nursing Officer', 3], ['Registered Nurse', 2], ['Enrolled Nurse', 1]]],
        'Medical' => ['MED', ['Internal Medicine', 'Surgery', 'Paediatrics'], [['Specialist', 5], ['Medical Officer', 4], ['Intern Doctor', 2]]],
        'Laboratory' => ['LAB', ['Haematology', 'Microbiology'], [['Laboratory Technologist', 3], ['Laboratory Assistant', 1]]],
        'Pharmacy' => ['PHA', [], [['Pharmacist', 4], ['Pharmaceutical Technician', 2]]],
    ];

    public const DIRECTORS = [
        'Finance' => 'dfinance', 'Procurement and Supplies' => 'dfinance', 'Human Resources' => 'dhr', 'Transport' => 'dhr', 'Estates' => 'dhr', 'ICT' => 'dhr', 'Planning' => 'dplanning',
    ];

    public const GRADES = [1 => 650000, 2 => 900000, 3 => 1400000, 4 => 2300000, 5 => 3800000, 6 => 5500000];

    public const PEOPLE = [
        ['dg', 'Dr. Grace', 'Mwakalinga', 'Female', 'Directorate', null, 'Director General', ['director-general'], 'head'],
        ['dfinance', 'Joseph', 'Mrema', 'Male', 'Directorate', null, 'Director of Finance', ['director-finance']],
        ['dhr', 'Rehema', 'Said', 'Female', 'Directorate', null, 'Director of HR and Administration', ['director-hr-admin']],
        ['dplanning', 'Emmanuel', 'Lyimo', 'Male', 'Directorate', null, 'Director of Planning', ['director-planning']],
        ['hrmanager', 'Neema', 'Kisanga', 'Female', 'Human Resources', null, 'HR Manager', ['hr-manager'], 'head'],
        ['hrofficer', 'Baraka', 'Mollel', 'Male', 'Human Resources', 'Recruitment', 'HR Officer', ['hr-officer'], 'section'],
        ['payroll', 'Zawadi', 'Njau', 'Female', 'Human Resources', 'Payroll Office', 'Payroll Officer', ['hr-officer'], 'section'],
        ['finmanager', 'Peter', 'Massawe', 'Male', 'Finance', null, 'Finance Manager', ['finance-manager'], 'head'],
        ['accountant', 'Halima', 'Mussa', 'Female', 'Finance', 'Accounts', 'Accountant', ['accountant'], 'section'],
        ['cashier', 'John', 'Mushi', 'Male', 'Finance', 'Accounts', 'Cashier', ['cashier']],
        ['billing', 'Agnes', 'Temba', 'Female', 'Finance', 'Billing', 'Billing Officer', ['billing-officer'], 'section'],
        ['budget', 'Daudi', 'Shirima', 'Male', 'Finance', 'Budget', 'Budget Officer', ['budget-officer'], 'section'],
        ['planning', 'Esther', 'Mbwambo', 'Female', 'Planning', null, 'Planning Officer', ['planning-officer'], 'head'],
        ['procmanager', 'Khamis', 'Bakari', 'Male', 'Procurement and Supplies', null, 'Procurement Manager', ['procurement-manager'], 'head'],
        ['procofficer', 'Lucy', 'Mapunda', 'Female', 'Procurement and Supplies', 'Procurement', 'Procurement Officer', ['procurement-officer'], 'section'],
        ['store', 'Hassan', 'Omari', 'Male', 'Procurement and Supplies', 'Stores', 'Storekeeper', ['storekeeper'], 'section'],
        ['transport', 'Selemani', 'Kombo', 'Male', 'Transport', null, 'Transport Officer', ['transport-officer'], 'head'],
        ['assets', 'Mariam', 'Hamisi', 'Female', 'Estates', null, 'Asset Officer', ['asset-officer'], 'head'],
        ['auditor', 'Godfrey', 'Swai', 'Male', 'Internal Audit', null, 'Internal Auditor', ['internal-auditor'], 'head'],
        ['ict', 'Abdul', 'Kassim', 'Male', 'ICT', null, 'Systems Administrator', [], 'head'],
        ['nursing', 'Felista', 'Kimaro', 'Female', 'Nursing', null, 'Nursing Officer', [], 'head'],
        ['medical', 'Dr. Ibrahim', 'Nyoni', 'Male', 'Medical', null, 'Specialist', [], 'head'],
        ['lab', 'Rose', 'Lema', 'Female', 'Laboratory', null, 'Laboratory Technologist', [], 'head'],
        ['pharmacy', 'Musa', 'Chande', 'Male', 'Pharmacy', null, 'Pharmacist', [], 'head'],
        ['nurse', 'Upendo', 'Massawe', 'Female', 'Nursing', 'Wards', 'Registered Nurse', [], 'section'],
        ['doctor', 'Dr. Salma', 'Abdallah', 'Female', 'Medical', 'Surgery', 'Medical Officer', [], 'section'],
    ];

    private const FIRST_F = ['Amina', 'Anna', 'Asha', 'Beatrice', 'Catherine', 'Doris', 'Elizabeth', 'Fatma', 'Faraja', 'Glory', 'Happiness', 'Imani', 'Jesca', 'Joyce', 'Lilian',
        'Mwanaisha', 'Mwajuma', 'Nasra', 'Paulina', 'Rehema', 'Sabra', 'Saida', 'Tatu', 'Witness', 'Yusta', 'Zuhura', 'Neema', 'Pendo', 'Subira', 'Veronica'];
    private const FIRST_M = ['Abdallah', 'Athumani', 'Baraka', 'Charles', 'Daniel', 'Elia', 'Frank', 'George', 'Hamza', 'Idd', 'Jackson', 'Juma', 'Kelvin', 'Linus', 'Mohamed',
        'Nassoro', 'Omary', 'Paul', 'Rajab', 'Ramadhani', 'Saidi', 'Stephen', 'Thomas', 'Vicent', 'Wilson', 'Yohana', 'Zakaria', 'Faustine', 'Gerald', 'Issa'];
    private const LAST = ['Mwakyusa', 'Mfinanga', 'Kileo', 'Urassa', 'Minja', 'Ngowi', 'Mtui', 'Kweka', 'Mallya', 'Moshi', 'Makame', 'Sanga', 'Mwita', 'Chacha', 'Magesa',
        'Mlay', 'Kiwelu', 'Mbilinyi', 'Haule', 'Komba', 'Nyirenda', 'Mwamba', 'Kapinga', 'Ndege', 'Mwinyi', 'Salim', 'Juma', 'Rashid', 'Pallangyo', 'Laizer', 'Sumari', 'Tarimo'];

    private int $seq = 1;

    /** @var array<string, Department> */
    public array $departments = [];

    /** @var array<string, Department> */
    public array $sections = [];

    /** @var array<string, User> */
    public array $users = [];

    public function __construct(private readonly Demo $demo) {}

    public function run(): void
    {
        $this->structure();
        $this->people();
        $this->staff();
        $this->heads();
        $this->extras();
        $this->leave();
        $this->attendance();
        $this->overtime();
        $this->loans();
    }

    private function structure(): void
    {
        $hq = CompanyBranch::where('is_headquarters', true)->first();
        foreach (self::STRUCTURE as $name => [$short, $sections, $positions]) {
            $d = Department::firstOrCreate(['name' => $name, 'parent_id' => null], ['is_active' => true]);
            $d->update(['short_name' => $short, 'company_branch_id' => $hq->id]);
            $this->departments[$name] = $d;
            foreach ($sections as $s) {
                $this->sections[$s] = Department::firstOrCreate(['name' => $s, 'parent_id' => $d->id], ['is_active' => true, 'company_branch_id' => $hq->id]);
            }
            foreach ($positions as [$title, $grade]) {
                $p = JobPosition::firstOrCreate(['title' => $title], ['is_active' => true]);
                $p->update(['department_id' => $d->id]);
                SalaryScale::firstOrCreate(['name' => "Grade $grade - $title"], ['job_position_id' => $p->id, 'minimum' => self::GRADES[$grade] * 0.9, 'maximum' => self::GRADES[$grade] * 1.3]);
            }
        }
        foreach ([['New Year', 1, 1], ['Zanzibar Revolution Day', 12, 1], ['Karume Day', 7, 4], ['Union Day', 26, 4], ['Workers Day', 1, 5], ['Saba Saba', 7, 7],
            ['Nane Nane', 8, 8], ['Nyerere Day', 14, 10], ['Independence Day', 9, 12], ['Christmas Day', 25, 12], ['Boxing Day', 26, 12]] as [$t, $d, $m]) {
            PublicHoliday::firstOrCreate(['title' => $t], ['day_number' => $d, 'month_number' => $m, 'is_active' => true]);
        }
        foreach (['CRDB Bank Loans', 'NMB Personal Loans', 'Staff SACCOS'] as $l) {
            LoanProvider::firstOrCreate(['name' => $l], ['is_active' => true]);
        }
    }

    private function people(): void
    {
        $admin = User::where('username', 'hr')->first();
        $this->users['admin'] = $admin;
        foreach (self::PEOPLE as $p) {
            [$username, $f, $l, $g, $dept, $section, $title, $roles] = $p;
            $e = $this->employee($f, $l, $g, $dept, $section, $title, Carbon::create(2012 + crc32($username) % 10, 1 + crc32($username) % 12, 1));
            $u = $this->account($e, $username);
            foreach ($roles as $r) {
                $u->assignRole($r);
            }
            $this->users[$username] = $u;
        }
        $this->users['procmanager']->givePermissionTo('inventory.manage');
    }

    private function staff(): void
    {
        $plan = [
            'Directorate' => [null => [['Personal Secretary', 3]]],
            'Human Resources' => ['Recruitment' => [['HR Officer', 2]], 'Payroll Office' => [['Payroll Officer', 1]], null => [['Records Assistant', 2]]],
            'Finance' => ['Accounts' => [['Accountant', 3], ['Cashier', 2]], 'Billing' => [['Billing Officer', 3]], 'Budget' => [['Budget Officer', 1]]],
            'Planning' => ['Monitoring and Evaluation' => [['M&E Officer', 2]]],
            'Procurement and Supplies' => ['Procurement' => [['Procurement Officer', 2]], 'Stores' => [['Storekeeper', 2]]],
            'Transport' => [null => [['Driver', 7], ['Mechanic', 2]]],
            'Estates' => ['Maintenance' => [['Electrician', 2], ['Plumber', 2]]],
            'Internal Audit' => [null => [['Audit Assistant', 2]]],
            'ICT' => [null => [['ICT Officer', 3]]],
            'Nursing' => ['Wards' => [['Registered Nurse', 8], ['Enrolled Nurse', 5]], 'Theatre' => [['Registered Nurse', 5]], 'Emergency' => [['Registered Nurse', 4], ['Enrolled Nurse', 2]]],
            'Medical' => ['Internal Medicine' => [['Specialist', 1], ['Medical Officer', 3]], 'Surgery' => [['Specialist', 2], ['Medical Officer', 2]], 'Paediatrics' => [['Medical Officer', 2], ['Intern Doctor', 2]]],
            'Laboratory' => ['Haematology' => [['Laboratory Technologist', 3]], 'Microbiology' => [['Laboratory Technologist', 2], ['Laboratory Assistant', 2]]],
            'Pharmacy' => [null => [['Pharmacist', 2], ['Pharmaceutical Technician', 3]]],
        ];
        $i = 0;
        foreach ($plan as $dept => $bySection) {
            foreach ($bySection as $section => $positions) {
                foreach ($positions as [$title, $count]) {
                    for ($n = 0; $n < $count; $n++, $i++) {
                        $g = $i % 2 ? 'Male' : 'Female';
                        $f = $g === 'Male' ? self::FIRST_M[$i % 30] : self::FIRST_F[$i % 30];
                        $l = self::LAST[($i * 7) % count(self::LAST)];
                        $e = $this->employee($f, $l, $g, $dept, $section ?: null, $title, Carbon::create(2014 + $i % 11, 1 + $i % 12, 1 + $i % 27), $i % 9 === 0 ? 'Arusha Branch' : null);
                        $this->account($e, Str::lower($f.'.'.$l).($i));
                    }
                }
            }
        }
    }

    private function employee(string $f, string $l, string $g, string $dept, ?string $section, string $title, Carbon $hired, ?string $branch = null): Employee
    {
        $d = $this->departments[$dept];
        $branchId = $branch ? CompanyBranch::where('name', $branch)->value('id') : $d->company_branch_id;
        $n = $this->seq++;
        $position = JobPosition::where('title', $title)->first();
        $e = Employee::create([
            'company_branch_id' => $branchId, 'department_id' => $d->id, 'section_id' => $section ? $this->sections[$section]->id : null, 'job_position_id' => $position->id,
            'employee_no' => 'KH'.str_pad((string) (100 + $n), 4, '0', STR_PAD_LEFT), 'attendance_code' => (string) (1000 + $n), 'fname' => $f, 'lname' => $l, 'gender' => $g,
            'date_of_birth' => Carbon::create(1970 + $n % 28, 1 + $n % 12, 1 + $n % 28), 'phone_number' => '07'.str_pad((string) (12000000 + $n * 7919 % 87999999), 8, '0', STR_PAD_LEFT),
            'email' => Str::lower(Str::ascii(str_replace('Dr. ', '', $f)).'.'.$l).$n.'@demo-hospital.local', 'nationality' => 'Tanzanian', 'marital_status' => $n % 3 ? 'Married' : 'Single',
            'employment_date' => $hired, 'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'has_annual_leave' => true, 'has_attendance' => true,
            'home_address' => ['Sinza', 'Mikocheni', 'Kinondoni', 'Mbezi', 'Tegeta', 'Kimara', 'Ubungo', 'Kariakoo'][$n % 8].', Dar es Salaam',
            'tin' => (string) (100000000 + $n * 3571), 'nssf_no' => 'NSSF'.(500000 + $n), 'nhif_no' => 'NHIF'.(800000 + $n),
        ]);
        $grade = collect(self::STRUCTURE[$dept][2])->firstWhere(0, $title)[1] ?? 2;
        $type = NatureOfContract::where('code', $n % 7 === 0 ? 'STC' : 'PNP')->first();
        $end = $type->code === 'STC' ? now()->addMonths(2 + $n % 14)->startOfDay() : $e->date_of_birth->copy()->addYearsNoOverflow(60)->subDay();
        $contract = EmployeeContract::create([
            'employee_id' => $e->id, 'nature_of_contract_id' => $type->id, 'salary_scale_id' => SalaryScale::where('job_position_id', $position->id)->value('id'),
            'contract_no' => 'CT/'.$e->employee_no, 'basic_salary' => self::GRADES[$grade] + ($n % 5) * 50000, 'period' => max(1, (int) ceil($hired->floatDiffInMonths($end->copy()->addDay()))),
            'start_date' => $hired, 'end_date' => $end, 'status' => 'active', 'is_active' => true,
        ]);
        $e->forceFill(['contract_id' => $contract->id, 'contract_status' => 'active'])->save();
        EmployeeBankAccount::create(['employee_id' => $e->id, 'bank_id' => Bank::orderBy('id')->skip($n % 4)->value('id'), 'account_name' => "$f $l",
            'account_number' => (string) (1500000000 + $n * 104729), 'branch' => 'Samora', 'is_active' => true]);

        return $e;
    }

    private function account(Employee $e, string $username): User
    {
        $u = User::create(['company_id' => $e->company_id, 'company_branch_id' => $e->company_branch_id, 'username' => $username, 'email' => $e->email,
            'password' => $this->demo->password, 'is_active' => true, 'email_verified_at' => now()]);
        $u->assignRole('employee');
        $e->forceFill(['user_id' => $u->id])->save();

        return $u;
    }

    private function heads(): void
    {
        $appoint = app(AppointHead::class);
        foreach (self::PEOPLE as $p) {
            $type = $p[8] ?? null;
            if (! $type) {
                continue;
            }
            $e = $this->users[$p[0]]->employee;
            $unit = $type === 'section' ? $this->sections[$p[5]] : $this->departments[$p[4]];
            $appoint->appoint($unit, $e, 'Initial appointment', $this->users['admin']);
        }
        foreach ($this->sections as $name => $s) {
            if (! $s->headOfDepartment()->exists()) {
                $e = Employee::where('section_id', $s->id)->orderBy('id')->first();
                if ($e) {
                    $appoint->appoint($s, $e, 'Initial appointment', $this->users['admin']);
                }
            }
        }
        foreach ($this->departments as $name => $dep) {
            $headId = $dep->headOfDepartment()->value('employee_id');
            $deputy = Employee::where('department_id', $dep->id)->where('id', '!=', $headId)->whereNotNull('user_id')
                ->whereNotIn('id', \App\Models\HeadOfDepartment::where('is_active', true)->pluck('employee_id'))->orderBy('id')->first()
                ?? Employee::where('department_id', $dep->id)->where('id', '!=', $headId)->whereNotNull('user_id')->orderBy('id')->first();
            if ($deputy && $name !== 'Internal Audit') {
                $appoint->appointDeputy($dep, $deputy, 'Acting when the head is away', $this->users['admin']);
            }
        }
        foreach (self::DIRECTORS as $name => $key) {
            $headId = isset($this->departments[$name]) ? $this->departments[$name]->headOfDepartment()->value('employee_id') : null;
            $director = $this->users[$key]->employee?->id;
            if ($headId && $director) {
                EmpSupervisor::create(['employee_id' => $headId, 'supervisor_id' => $director, 'from_date' => '2026-07-01', 'status' => 'active']);
            }
        }
        foreach (Employee::whereNotNull('section_id')->get() as $e) {
            $head = \App\Models\HeadOfDepartment::where('department_id', $e->section_id)->where('is_active', true)->value('employee_id');
            if ($head && $head !== $e->id) {
                EmpSupervisor::create(['employee_id' => $e->id, 'supervisor_id' => $head, 'from_date' => $e->employment_date, 'status' => 'active']);
            }
        }
        app(\Modules\Core\Services\AccessService::class)->forget();
    }

    private function extras(): void
    {
        foreach (Department::whereNull('parent_id')->get() as $d) {
            OvertimeRate::firstOrCreate(['department_id' => $d->id, 'section_id' => null, 'job_position_id' => null], ['unit' => 'hour', 'amount' => 6000, 'is_active' => true]);
        }
    }

    private function leave(): void
    {
        $leave = app(ManageLeave::class);
        $al = LeaveType::where('code', 'AL')->first();
        $sl = LeaveType::where('code', 'SL')->first();
        $cl = LeaveType::where('code', 'CL')->first();
        $admin = $this->users['admin'];
        $employees = Employee::whereNotNull('contract_id')->orderBy('id')->get();
        foreach ($employees as $i => $e) {
            $plans = [];
            if ($i % 3 === 0) {
                $plans[] = [$al, now()->subMonths(5 + $i % 4)->startOfMonth()->addDays($i % 20), 14];
            }
            if ($i % 11 === 0) {
                $plans[] = [$al, now()->subDays(3 + $i % 5), 14];
            }
            if ($i % 13 === 0) {
                $plans[] = [$al, now()->addDays(10 + $i % 30), 10];
            }
            if ($i % 7 === 0) {
                $plans[] = [$cl, now()->subMonths(2)->addDays($i % 25), 3];
            }
            foreach ($plans as [$type, $start, $days]) {
                try {
                    $leave->request($e, ['leave_type_id' => $type->id, 'start_date' => $start->toDateString(), 'days' => $days, 'description' => $type->code === 'CL' ? 'Family bereavement' : 'Rest and family time',
                        'leave_address' => 'Moshi, Kilimanjaro', 'leave_phone' => $e->phone_number,
                        'details' => ['deceased_name' => 'Mzee '.$e->lname, 'relationship' => 'Parent', 'death_date' => $start->copy()->subDay()->toDateString()]], $admin, 'hr');
                } catch (\Throwable $ex) {
                    $this->demo->note("leave {$e->employee_no}: ".$ex->getMessage());
                }
            }
        }
        $this->demo->settle('leave-request', keepLatest: 12);
        $leave->updateStatuses();
    }

    private function attendance(): void
    {
        $rows = [];
        $employees = Employee::orderBy('id')->get(['id', 'attendance_code']);
        for ($d = 30; $d >= 1; $d--) {
            $day = now()->subDays($d);
            if ($day->isWeekend()) {
                continue;
            }
            foreach ($employees as $i => $e) {
                if (($i + $d) % 17 === 0) {
                    continue;
                }
                $late = ($i + $d) % 9 === 0 ? 35 : ($i * 3 + $d) % 20;
                $rows[] = ['emp_code' => $e->attendance_code, 'punch_time' => $day->copy()->setTime(7, 40)->addMinutes($late)->toDateTimeString(), 'punch_state' => '0', 'terminal_alias' => 'Main gate'];
                $rows[] = ['emp_code' => $e->attendance_code, 'punch_time' => $day->copy()->setTime(16, 5)->addMinutes(($i + $d * 5) % 50)->toDateTimeString(), 'punch_state' => '1', 'terminal_alias' => 'Main gate'];
            }
        }
        \App\Models\AttendanceSetting::current()->fill(['source' => 'biotime', 'grace_minutes' => 15, 'sync_days' => 60,
            'connection' => ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'biotime_demo', 'username' => 'demo', 'password' => 'demo']])->save();
        $a = app(ManageAttendance::class);
        $a->addPunches($rows, 'import', $this->users['admin']->id);
        $a->compile(now()->subDays(30)->toDateString(), now()->subDay()->toDateString());
    }

    private function overtime(): void
    {
        $ot = app(ManageOvertime::class);
        $nurses = Employee::whereIn('department_id', [$this->departments['Nursing']->id, $this->departments['Medical']->id, $this->departments['Transport']->id])->orderBy('id')->get();
        foreach ($nurses as $i => $e) {
            if ($i % 2) {
                continue;
            }
            $date = now()->subDays(3 + $i % 25);
            if ($date->isWeekend()) {
                $date->subDays(2);
            }
            try {
                $ot->claim($e, ['date' => $date->toDateString(), 'start_time' => '17:00', 'end_time' => $i % 4 ? '20:00' : '22:00', 'task_title' => ['Emergency cases', 'Theatre list ran late', 'Patient transfer to MNH', 'Night cover'][$i % 4]],
                    $e->user ?? $this->users['admin'], 'employee');
            } catch (\Throwable $ex) {
                $this->demo->note("overtime {$e->employee_no}: ".$ex->getMessage());
            }
        }
        $this->demo->settle('overtime-claim', keepLatest: 6);
    }

    private function loans(): void
    {
        $loans = app(ManageLoans::class);
        $providers = LoanProvider::orderBy('id')->pluck('id');
        foreach (Employee::orderBy('id')->get() as $i => $e) {
            if ($i % 6) {
                continue;
            }
            try {
                $amount = [3000000, 5000000, 1500000, 8000000][$i % 4];
                $loans->createLoan($e, ['loan_provider_id' => $providers[$i % 3], 'amount' => $amount, 'instalment' => $amount / 24, 'date_of_loan' => now()->subMonths(4)->toDateString(),
                    'description' => 'Personal development loan'], $this->users['admin']);
            } catch (\Throwable $ex) {
                $this->demo->note("loan {$e->employee_no}: ".$ex->getMessage());
            }
        }
    }

    public function payroll(): void
    {
        $p = app(ManagePayroll::class);
        $by = $this->demo->user('payroll');
        auth()->setUser($this->demo->user('dhr'));
        foreach ([3, 2, 1] as $back) {
            $m = now()->subMonths($back);
            $sheet = $p->create($m->year, $m->month, $by);
            $sheet = $p->generate($sheet->refresh());
            $p->approve($sheet);
        }
        auth()->forgetUser();
    }
}
