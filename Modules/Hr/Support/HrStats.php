<?php

namespace Modules\Hr\Support;

use App\Models\ContractRenewal;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePlacement;
use App\Models\JobPosition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Hr\Support\EmployeeOptions as O;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The records behind each HR dashboard number, within the user's employee scope. */
class HrStats
{
    private const EMPLOYEE_COLUMNS = [
        ['key' => 'employee_no', 'label' => 'No.'], ['key' => 'name', 'label' => 'Name'], ['key' => 'gender', 'label' => 'Gender'],
        ['key' => 'department', 'label' => 'Department'], ['key' => 'section', 'label' => 'Section'], ['key' => 'position', 'label' => 'Position'],
        ['key' => 'type', 'label' => 'Type'], ['key' => 'status', 'label' => 'Status'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'email', 'label' => 'Email'],
        ['key' => 'employed', 'label' => 'Employed'], ['key' => 'contract_end', 'label' => 'Contract ends'],
    ];

    public function __construct(private readonly EmployeeScope $scope) {}

    public function run(string $stat, ?string $value, User $user): array
    {
        $today = CarbonImmutable::today();
        $active = fn () => $this->scope->apply(Employee::query()->active(), $user);
        $scoped = fn (Builder $q) => $this->scope->apply($q, $user);

        [$title, $result] = match ($stat) {
            'employees' => ['Active employees', $this->employees($active())],
            'probation' => ['Employees on probation', $this->employees($active()->where('employment_status', 'probation'))],
            'confirmed' => ['Confirmed employees', $this->employees($active()->where('employment_status', 'confirmed'))],
            'female' => ['Female employees', $this->employees($active()->where('gender', 'Female'))],
            'male' => ['Male employees', $this->employees($active()->where('gender', 'Male'))],
            'type' => [(O::EMPLOYMENT_TYPES[$value] ?? $this->missing()).' employees', $this->employees($active()->where('emp_type', $value))],
            'without_account' => ['Employees without an account', $this->employees($active()->whereNull('user_id'))],
            'invited' => ['Employees with invite pending', $this->employees($scoped(Employee::query()->invitePending()))],
            'without_contract' => ['Employees without a contract', $this->employees($scoped(Employee::query()->withoutContract()))],
            'contracts_ending' => ['Contracts ending in '.O::RENEWAL_WINDOW_DAYS.' days, not renewed', $this->employees($scoped(Employee::query()->contractEnding()))],
            'licences_expiring' => ['Licences expired or expiring', $this->employees($scoped(Employee::query()->licenceExpiring()))],
            'department' => ['Staff of '.(Department::find($value)?->name ?? $this->missing()),
                $this->employees($active()->where(fn ($q) => $q->where('department_id', $value)->orWhere('section_id', $value)))],
            'position' => ['Staff in '.(JobPosition::find($value)?->title ?? $this->missing()), $this->employees($active()->where('job_position_id', $value))],
            'placements_active' => ['Interns and residents in an active period', $this->placements($user, fn ($q) => $q->where('status', 'active'))],
            'placements_ending' => ['Interns and residents ending in 30 days', $this->placements($user, fn ($q) => $q->where('status', 'active')->whereDate('end_date', '<=', $today->addDays(30)))],
            'placements_upcoming' => ['Interns and residents starting soon', $this->placements($user, fn ($q) => $q->where('status', 'upcoming'))],
            'placements_internship' => ['Interns in an active period', $this->placements($user, fn ($q) => $q->where('status', 'active')->where('type', 'internship'))],
            'placements_residency' => ['Residents in an active period', $this->placements($user, fn ($q) => $q->where('status', 'active')->where('type', 'residency'))],
            'renewals_pending' => ['Renewals waiting for approval', $this->renewals($user, fn ($q) => $q->where('status', 'pending'))],
            'renewals_approved' => ['Renewals approved, contract to be created', $this->renewals($user, fn ($q) => $q->where('status', 'approved'))],
            'renewals_renewed' => ['Contracts renewed this year', $this->renewals($user, fn ($q) => $q->where('status', 'completed')->whereDate('updated_at', '>=', $today->startOfYear()))],
            'renewals_rejected' => ['Renewals rejected this year', $this->renewals($user, fn ($q) => $q->where('status', 'rejected')->whereDate('updated_at', '>=', $today->startOfYear()))],
            'departments' => ['Departments', $this->departments($user, sections: false, staffedOnly: false)],
            'sections' => ['Sections', $this->departments($user, sections: true, staffedOnly: false)],
            'departments_staffed' => ['Departments with staff', $this->departments($user, sections: false, staffedOnly: true)],
            'positions' => ['Active job positions', $this->positions($user, filledOnly: false)],
            'positions_filled' => ['Job positions with staff', $this->positions($user, filledOnly: true)],
            default => $this->missing(),
        };

        return ['key' => $stat, 'title' => $title, 'subtitle' => $this->scope->describe($user)['label'].' · '.$today->format('d M Y'),
            'columns' => $result['columns'], 'rows' => $result['rows'], 'total' => count($result['rows'])];
    }

    private function employees(Builder $query): array
    {
        $rows = $query->with(['department:id,name', 'section:id,name', 'jobPosition:id,title', 'activeContract'])->orderBy('employee_no')->get()
            ->map(fn (Employee $e) => [
                'employee_id' => $e->id, 'employee_no' => $e->employee_no, 'name' => $e->full_name, 'gender' => $e->gender,
                'department' => $e->department?->name, 'section' => $e->section?->name, 'position' => $e->jobPosition?->title,
                'type' => O::EMPLOYMENT_TYPES[$e->emp_type] ?? $e->emp_type, 'status' => O::EMPLOYMENT_STATUSES[$e->employment_status] ?? $e->employment_status,
                'phone' => $e->phone_number, 'email' => $e->email,
                'employed' => $e->employment_date?->toDateString(), 'contract_end' => $e->activeContract?->end_date?->toDateString(),
            ])->all();

        return ['columns' => self::EMPLOYEE_COLUMNS, 'rows' => $rows];
    }

    private function placements(User $user, callable $where): array
    {
        $ids = $this->scope->apply(Employee::where('employment_status', '!=', 'terminated'), $user)->select('id');
        $query = EmployeePlacement::whereIn('employee_id', $ids)->with('employee.department:id,name', 'employee.jobPosition:id,title');
        $where($query);
        $rows = $query->orderBy('end_date')->get()->map(fn ($p) => [
            'employee_id' => $p->employee_id, 'employee_no' => $p->employee?->employee_no, 'name' => $p->employee?->full_name,
            'type' => EmployeePlacement::TYPES[$p->type] ?? $p->type, 'year' => $p->year, 'department' => $p->employee?->department?->name,
            'position' => $p->employee?->jobPosition?->title, 'start' => $p->start_date?->toDateString(), 'end' => $p->end_date?->toDateString(),
            'status' => EmployeePlacement::STATUSES[$p->status] ?? $p->status,
        ])->all();

        return ['columns' => [
            ['key' => 'employee_no', 'label' => 'No.'], ['key' => 'name', 'label' => 'Name'], ['key' => 'type', 'label' => 'Type'], ['key' => 'year', 'label' => 'Year'],
            ['key' => 'department', 'label' => 'Department'], ['key' => 'position', 'label' => 'Position'], ['key' => 'start', 'label' => 'Starts'],
            ['key' => 'end', 'label' => 'Ends'], ['key' => 'status', 'label' => 'Status'],
        ], 'rows' => $rows];
    }

    private function renewals(User $user, callable $where): array
    {
        $query = ContractRenewal::whereIn('employee_id', $this->scope->apply(Employee::query(), $user)->select('id'))
            ->with('employee.department:id,name', 'contract', 'natureOfContract');
        $where($query);
        $rows = $query->latest('id')->get()->map(fn ($r) => [
            'employee_id' => $r->employee_id, 'employee_no' => $r->employee?->employee_no, 'name' => $r->employee?->full_name,
            'department' => $r->employee?->department?->name, 'contract_end' => $r->contract?->end_date?->toDateString(),
            'contract_type' => $r->natureOfContract?->contract_name, 'period' => $r->period,
            'source' => $r->source === ContractRenewal::FROM_EMPLOYEE ? 'Employee' : 'HR',
            'status' => ContractRenewal::STATUSES[$r->status] ?? $r->status, 'requested' => $r->created_at?->toDateString(),
        ])->all();

        return ['columns' => [
            ['key' => 'employee_no', 'label' => 'No.'], ['key' => 'name', 'label' => 'Name'], ['key' => 'department', 'label' => 'Department'],
            ['key' => 'contract_end', 'label' => 'Current contract ends'], ['key' => 'contract_type', 'label' => 'New contract type'], ['key' => 'period', 'label' => 'Period'],
            ['key' => 'source', 'label' => 'Started by'], ['key' => 'status', 'label' => 'Status'], ['key' => 'requested', 'label' => 'Requested'],
        ], 'rows' => $rows];
    }

    private function departments(User $user, bool $sections, bool $staffedOnly): array
    {
        $staff = $this->staff($user);
        $counts = $staff->countBy($sections ? 'section_id' : 'department_id');
        $rows = Department::where('is_active', true)->when($sections, fn ($q) => $q->whereNotNull('parent_id'), fn ($q) => $q->whereNull('parent_id'))
            ->with('parent:id,name', 'branch:id,name', 'headOfDepartment')->withCount(['sections' => fn ($q) => $q->where('is_active', true)])->orderBy('name')->get()
            ->map(fn ($d) => [
                'department_id' => $d->id, 'name' => $d->name, 'parent' => $d->parent?->name, 'branch' => $d->branch?->name,
                'head' => $d->headOfDepartment?->employee?->full_name, 'sections' => $d->sections_count, 'staff' => $counts[$d->id] ?? 0,
            ])
            ->when($staffedOnly, fn (Collection $c) => $c->filter(fn ($r) => $r['staff'] > 0))->values()->all();

        return ['columns' => array_values(array_filter([
            ['key' => 'name', 'label' => $sections ? 'Section' : 'Department'], $sections ? ['key' => 'parent', 'label' => 'Department'] : null,
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'head', 'label' => 'Head'], $sections ? null : ['key' => 'sections', 'label' => 'Sections'],
            ['key' => 'staff', 'label' => 'Active staff'],
        ])), 'rows' => $rows];
    }

    private function positions(User $user, bool $filledOnly): array
    {
        $counts = $this->staff($user)->countBy('job_position_id');
        $rows = JobPosition::where('is_active', true)->with('department:id,name')->orderBy('title')->get()
            ->map(fn ($p) => ['position_id' => $p->id, 'name' => $p->title, 'department' => $p->department?->name, 'staff' => $counts[$p->id] ?? 0])
            ->when($filledOnly, fn (Collection $c) => $c->filter(fn ($r) => $r['staff'] > 0))->values()->all();

        return ['columns' => [['key' => 'name', 'label' => 'Position'], ['key' => 'department', 'label' => 'Department'], ['key' => 'staff', 'label' => 'Active staff']], 'rows' => $rows];
    }

    private function staff(User $user): Collection
    {
        return $this->scope->apply(Employee::query()->active(), $user)->get(['id', 'department_id', 'section_id', 'job_position_id']);
    }

    private function missing(): never
    {
        throw new NotFoundHttpException('Unknown statistic.');
    }
}
