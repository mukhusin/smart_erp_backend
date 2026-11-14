<?php

namespace Modules\Hr\Support;

use App\Models\AttendanceSetting;
use App\Models\AttendanceTransa;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeLicence;
use App\Models\EmployeePlacement;
use App\Models\PayrollSheet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeOptions as O;
use Modules\Workflow\Services\WorkflowEngine;

/** Management overview: staff numbers, movement, leave, contracts, and payroll/attendance when the user may see them. */
class HrOverview
{
    public function __construct(private readonly EmployeeScope $scope, private readonly AccessService $access, private readonly EmployeeSheet $sheet, private readonly WorkflowEngine $workflow) {}

    public function build(User $user): array
    {
        $today = CarbonImmutable::today();
        $current = $this->scope->apply(Employee::where('employment_status', '!=', 'terminated'), $user);
        $active = $this->scope->apply(Employee::query()->active(), $user);
        $all = $this->scope->apply(Employee::query()->staff(), $user);
        $ids = (clone $active)->select('id');
        $allIds = (clone $all)->select('id');
        $leaving = array_diff(array_keys(O::CONTRACT_END_REASONS), O::CONTRACT_KEEPS_EMPLOYEE);
        $staff = (clone $active)->get(['id', 'gender', 'emp_type', 'employment_status', 'department_id', 'date_of_birth']);

        $data = [
            'scope' => $this->scope->describe($user),
            'my_approvals' => $this->workflow->inbox($user)->count(),
            'staff' => [
                'active' => $staff->count(),
                'female' => $staff->where('gender', 'Female')->count(),
                'male' => $staff->where('gender', 'Male')->count(),
                'probation' => $staff->where('employment_status', 'probation')->count(),
                'by_type' => collect(O::EMPLOYMENT_TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $staff->where('emp_type', $key)->count()])
                    ->filter(fn ($t) => $t['count'] > 0)->values()->all(),
                'joined_this_month' => (clone $all)->whereDate('employment_date', '>=', $today->startOfMonth())->whereDate('employment_date', '<=', $today)->count(),
                'left_this_month' => EmployeeContract::whereIn('employee_id', $allIds)->where('is_active', false)->whereIn('status', $leaving)
                    ->whereDate('date_of_change', '>=', $today->startOfMonth())->whereDate('date_of_change', '<=', $today)->distinct('employee_id')->count('employee_id'),
                'interns' => EmployeePlacement::whereIn('employee_id', (clone $current)->select('id'))->where('status', 'active')->count(),
                'retiring_12_months' => $staff->filter(fn ($e) => $e->date_of_birth && $e->date_of_birth->copy()->addYears(60)->lte($today->addYear()))->count(),
            ],
            'departments' => $this->departments($user),
            'movement' => $this->movement($all, $allIds, $leaving, $today),
            'leave' => [
                'on_leave_today' => EmpLeave::whereIn('employee_id', $ids)->whereIn('status', [EmpLeave::APPROVED, EmpLeave::ON_LEAVE])
                    ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->count(),
                'starting_7_days' => EmpLeave::whereIn('employee_id', $ids)->where('status', EmpLeave::APPROVED)
                    ->whereDate('start_date', '>', $today)->whereDate('start_date', '<=', $today->addDays(7))->count(),
                'pending' => EmpLeave::whereIn('employee_id', $ids)->where('status', EmpLeave::PENDING)->count(),
            ],
            'contracts' => [
                'ending_30_days' => $this->ending($ids, $today->addDays(30)),
                'ending_90_days' => $this->ending($ids, $today->addDays(O::RENEWAL_WINDOW_DAYS)),
                'without_contract' => (clone $current)->whereDoesntHave('contracts', fn ($q) => $q->whereIn('status', ['active', 'waiting']))
                    ->whereDoesntHave('placements', fn ($q) => $q->whereIn('status', ['active', 'upcoming']))->count(),
                'licences_expired' => EmployeeLicence::whereIn('employee_id', $ids)->whereDate('end_date', '<', $today)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_licences as n')->whereColumn('n.employee_id', 'employee_licences.employee_id')
                        ->whereColumn('n.professional_licence_id', 'employee_licences.professional_licence_id')->whereColumn('n.end_date', '>', 'employee_licences.end_date'))->count(),
            ],
            'salary' => null,
            'payroll' => null,
            'attendance' => null,
        ];

        if ($this->sheet->seesSalary($user)) {
            $bill = EmployeeContract::whereIn('employee_id', $ids)->where('is_active', true);
            $data['salary'] = ['monthly_basic' => (float) (clone $bill)->sum('basic_salary'), 'contracts' => (clone $bill)->count()];
        }

        if ($this->access->canUseModule($user, 'payroll') && $user->can('payroll.view')) {
            $data['payroll'] = PayrollSheet::query()->orderByRaw('year + 0 desc, month + 0 desc')->limit(6)->get()->reverse()->values()
                ->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'period' => sprintf('%04d-%02d', (int) $s->year, (int) $s->month),
                    'status' => $s->status, 'status_label' => PayrollSheet::STATUS_LABELS[$s->status] ?? $s->status,
                    'employees' => (int) $s->employees_count, 'gross' => (float) $s->gross_total, 'deductions' => (float) $s->deduction_total,
                    'net' => (float) $s->net_total, 'employer' => (float) $s->employer_total,
                ])->all();
        }

        if ($this->access->canUseModule($user, 'attendance') && $user->can('attendance.view') && AttendanceSetting::current()->enabled()) {
            $rows = AttendanceTransa::whereIn('employee_id', $ids)->whereDate('transaction_date', $today)->get(['status']);
            $data['attendance'] = [
                'date' => $today->toDateString(),
                'tracked' => (clone $active)->where('has_attendance', true)->count(),
                'statuses' => $rows->groupBy('status')->map(fn ($g, $s) => ['status' => $s ?: 'Not compiled', 'count' => $g->count()])->sortByDesc('count')->values()->all(),
            ];
        }

        return $data;
    }

    /** Joined and left per month over the last 12 months, for the employees the user may see. */
    public function movementFor(User $user): array
    {
        $all = $this->scope->apply(Employee::query()->staff(), $user);

        return $this->movement($all, (clone $all)->select('id'), array_diff(array_keys(O::CONTRACT_END_REASONS), O::CONTRACT_KEEPS_EMPLOYEE), CarbonImmutable::today());
    }

    private function ending($ids, CarbonImmutable $until): int
    {
        return EmployeeContract::whereIn('employee_id', $ids)->where('is_active', true)->whereNotNull('end_date')->whereDate('end_date', '<=', $until)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_contracts as w')->whereColumn('w.employee_id', 'employee_contracts.employee_id')
                ->where('w.status', 'waiting')->whereNull('w.deleted_at'))->count();
    }

    private function departments(User $user): array
    {
        return $this->scope->apply(Employee::query()->active()->with('department:id,name'), $user)->get(['id', 'department_id'])
            ->groupBy(fn ($e) => $e->department?->name ?? 'No department')
            ->map(fn ($g, $name) => ['name' => $name, 'count' => $g->count()])->sortByDesc('count')->values()->all();
    }

    private function movement($all, $allIds, array $leaving, CarbonImmutable $today): array
    {
        $from = $today->subMonths(11)->startOfMonth();
        $joined = (clone $all)->whereDate('employment_date', '>=', $from)->whereDate('employment_date', '<=', $today)->pluck('employment_date')
            ->countBy(fn ($d) => $d->format('Y-m'));
        $left = EmployeeContract::whereIn('employee_id', $allIds)->where('is_active', false)->whereIn('status', $leaving)
            ->whereDate('date_of_change', '>=', $from)->whereDate('date_of_change', '<=', $today)->pluck('date_of_change')
            ->countBy(fn ($d) => $d->format('Y-m'));

        return collect(range(0, 11))->map(fn ($i) => $from->addMonths($i)->format('Y-m'))
            ->map(fn ($m) => ['month' => $m, 'joined' => $joined[$m] ?? 0, 'left' => $left[$m] ?? 0])->all();
    }
}
