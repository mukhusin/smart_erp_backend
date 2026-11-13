<?php

namespace Modules\Hr\Support;

use App\Models\AttendanceClaim;
use App\Models\AttendanceSetting;
use App\Models\AttendanceTransa;
use App\Models\CompanyBranch;
use App\Models\Department;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeLicence;
use App\Models\EmployeePlacement;
use App\Models\LeaveType;
use App\Models\Overtime;
use App\Models\ProfessionalLicence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Services\AccessService;
use Modules\Hr\Support\EmployeeOptions as O;

/** HR reports: each returns a title, columns and rows for the screen or CSV, within the user's employee scope. */
class HrReports
{
    public const REPORTS = [
        'headcount' => ['title' => 'Headcount by department', 'group' => 'Staff'],
        'contacts' => ['title' => 'Staff contacts (phones and emails)', 'group' => 'Staff'],
        'birthdays' => ['title' => 'Birthdays', 'group' => 'Staff'],
        'retirement' => ['title' => 'Retirement', 'group' => 'Staff'],
        'joiners' => ['title' => 'New employees', 'group' => 'Movement'],
        'leavers' => ['title' => 'Closed contracts and leavers', 'group' => 'Movement'],
        'contracts-ending' => ['title' => 'Contracts ending', 'group' => 'Contracts'],
        'licences' => ['title' => 'Professional licences', 'group' => 'Contracts'],
        'interns' => ['title' => 'Interns and residents', 'group' => 'Movement'],
        'on-leave' => ['title' => 'Staff on leave', 'group' => 'Staff'],
        'leave-requests' => ['title' => 'Leave requests', 'group' => 'Leave', 'module' => 'leave'],
        'leave-balances' => ['title' => 'Leave balances', 'group' => 'Leave', 'module' => 'leave'],
        'overtime' => ['title' => 'Overtime claims', 'group' => 'Overtime', 'module' => 'overtime'],
        'overtime-summary' => ['title' => 'Overtime per employee', 'group' => 'Overtime', 'module' => 'overtime'],
        'attendance-daily' => ['title' => 'Attendance by day', 'group' => 'Attendance', 'module' => 'attendance'],
        'attendance-summary' => ['title' => 'Attendance per employee', 'group' => 'Attendance', 'module' => 'attendance'],
        'attendance-claims' => ['title' => 'Lost-hours claims', 'group' => 'Attendance', 'module' => 'attendance'],
    ];

    private const ATTENDANCE_STATUSES = ['Present', 'Late', 'Absent', 'Not checked out', 'On leave', 'Admitted', 'Excuse of duty', 'Waiting'];

    public function __construct(
        private readonly EmployeeScope $scope,
        private readonly AccessService $access,
        private readonly EmployeeSheet $sheet,
        private readonly LeaveBalances $balances,
    ) {}

    /** @return list<array{key: string, title: string, group: string, filters: list<array<string, mixed>>}> */
    public function catalog(User $user): array
    {
        $keys = array_values(array_filter(array_keys(self::REPORTS), fn ($key) => $this->available($key, $user)));

        return array_map(fn ($key) => ['key' => $key, 'title' => self::REPORTS[$key]['title'], 'group' => self::REPORTS[$key]['group'], 'filters' => $this->filters($key)], $keys);
    }

    public function available(string $key, User $user): bool
    {
        $module = self::REPORTS[$key]['module'] ?? null;
        if (! $module) {
            return true;
        }

        return $this->access->canUseModule($user, $module) && ($module !== 'attendance' || AttendanceSetting::current()->enabled());
    }

    /** @return list<array<string, mixed>> */
    private function filters(string $key): array
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth()->toDateString();
        $range = [
            ['key' => 'from', 'label' => 'From', 'type' => 'date', 'default' => $monthStart],
            ['key' => 'to', 'label' => 'To', 'type' => 'date', 'default' => $today->endOfMonth()->toDateString()],
        ];
        $common = [
            ['key' => 'branch_id', 'label' => 'Branch', 'type' => 'select', 'options' => CompanyBranch::query()->where('is_active', true)->orderBy('name')->get()->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->name])->all()],
            ['key' => 'department_id', 'label' => 'Department', 'type' => 'select', 'options' => $this->departmentOptions()],
        ];
        $options = fn (array $o) => array_map(fn ($v, $l) => ['value' => (string) $v, 'label' => $l], array_keys($o), $o);

        return array_merge(match ($key) {
            'headcount' => [],
            'contacts' => [['key' => 'emp_type', 'label' => 'Employee type', 'type' => 'select', 'options' => $options(O::EMPLOYMENT_TYPES)]],
            'birthdays' => [['key' => 'month', 'label' => 'Month', 'type' => 'select', 'default' => (string) $today->month,
                'options' => array_map(fn ($m) => ['value' => (string) $m, 'label' => CarbonImmutable::create(2000, $m, 1)->format('F')], range(1, 12))]],
            'retirement' => [
                ['key' => 'age', 'label' => 'Retirement age', 'type' => 'number', 'default' => '60'],
                ['key' => 'months', 'label' => 'Reaching it within (months)', 'type' => 'number', 'default' => '12'],
            ],
            'joiners' => [...$range, ['key' => 'emp_type', 'label' => 'Employee type', 'type' => 'select', 'options' => $options(O::EMPLOYMENT_TYPES)]],
            'leavers' => [...$range, ['key' => 'reason', 'label' => 'Reason', 'type' => 'select', 'options' => $options(O::CONTRACT_END_REASONS),
                'hint' => 'Empty = everyone who left, without contract changes.']],
            'contracts-ending' => [['key' => 'days', 'label' => 'Ending within (days)', 'type' => 'number', 'default' => (string) O::RENEWAL_WINDOW_DAYS]],
            'licences' => [
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'default' => 'expiring',
                    'options' => [['value' => 'expiring', 'label' => 'Expired or expiring'], ['value' => 'expired', 'label' => 'Expired'], ['value' => 'all', 'label' => 'All (latest per licence)']]],
                ['key' => 'days', 'label' => 'Expiring within (days)', 'type' => 'number', 'default' => (string) O::RENEWAL_WINDOW_DAYS],
                ['key' => 'licence_id', 'label' => 'Licence', 'type' => 'select', 'options' => ProfessionalLicence::query()->orderBy('name')->get()->map(fn ($l) => ['value' => (string) $l->id, 'label' => $l->name])->all()],
            ],
            'interns' => [...$range, ['key' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $options(EmployeePlacement::TYPES)]],
            'on-leave' => [['key' => 'date', 'label' => 'Date', 'type' => 'date', 'default' => $today->toDateString()]],
            'leave-requests' => [...$range,
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => $options(EmpLeave::STATUS_LABELS)],
                ['key' => 'leave_type_id', 'label' => 'Leave type', 'type' => 'select', 'options' => $this->leaveTypeOptions()]],
            'leave-balances' => [['key' => 'leave_type_id', 'label' => 'Leave type', 'type' => 'select', 'options' => $this->leaveTypeOptions(true),
                'default' => (string) (LeaveType::where('is_active', true)->where('kind', 'annual')->value('id') ?? LeaveType::where('is_active', true)->where('max_days', '>', 0)->value('id'))]],
            'overtime' => [...$range, ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => $options(Overtime::STATUS_LABELS)]],
            'overtime-summary' => [...$range],
            'attendance-daily' => [
                ['key' => 'from', 'label' => 'From', 'type' => 'date', 'default' => $today->toDateString()],
                ['key' => 'to', 'label' => 'To', 'type' => 'date', 'default' => $today->toDateString()],
                ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => array_map(fn ($st) => ['value' => $st, 'label' => $st], self::ATTENDANCE_STATUSES)],
                ['key' => 'emp_type', 'label' => 'Employee type', 'type' => 'select', 'options' => $options(O::EMPLOYMENT_TYPES)],
            ],
            'attendance-summary' => [
                ['key' => 'from', 'label' => 'From', 'type' => 'date', 'default' => $monthStart],
                ['key' => 'to', 'label' => 'To', 'type' => 'date', 'default' => $today->toDateString()],
                ['key' => 'emp_type', 'label' => 'Employee type', 'type' => 'select', 'options' => $options(O::EMPLOYMENT_TYPES)],
            ],
            'attendance-claims' => [...$range, ['key' => 'status', 'label' => 'Status', 'type' => 'select',
                'options' => $options(['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'])]],
        }, $common);
    }

    private function departmentOptions(): array
    {
        $all = Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id']);

        return $all->whereNull('parent_id')->flatMap(fn ($d) => collect([['value' => (string) $d->id, 'label' => $d->name]])
            ->merge($all->where('parent_id', $d->id)->map(fn ($s) => ['value' => (string) $s->id, 'label' => "{$d->name} / {$s->name}"])))->values()->all();
    }

    /** @return array{key: string, title: string, subtitle: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>, total: int} */
    public function run(string $key, array $input, User $user): array
    {
        abort_unless($this->available($key, $user), 404, 'This report is not available.');
        $values = collect($this->filters($key))->mapWithKeys(fn ($f) => [$f['key'] => ($input[$f['key']] ?? '') !== '' ? (string) $input[$f['key']] : ($f['default'] ?? null)])->all();
        [$subtitle, $columns, $rows] = $this->{'report'.str_replace('-', '', ucwords($key, '-'))}($values, $user);

        return ['key' => $key, 'title' => self::REPORTS[$key]['title'], 'subtitle' => $subtitle, 'columns' => $this->columns($columns), 'rows' => array_values($rows), 'total' => count($rows)];
    }

    public function csv(array $report): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$report['title']]);
        if ($report['subtitle']) {
            fputcsv($out, [$report['subtitle']]);
        }
        fputcsv($out, []);
        fputcsv($out, array_column($report['columns'], 'label'));
        foreach ($report['rows'] as $row) {
            fputcsv($out, array_map(fn ($c) => $row[$c['key']] ?? '', $report['columns']));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @param 'active'|'staff'|'duty'|'people'|bool $who */
    private function employees(array $v, User $user, bool|string $who = 'active'): Builder
    {
        $who = $who === true ? 'active' : ($who === false ? 'staff' : $who);
        $query = $this->scope->apply(Employee::query()->with(['department', 'section', 'jobPosition', 'branch']), $user);
        match ($who) {
            'active' => $query->active(),
            'staff' => $query->staff(),
            'duty' => $query->onDuty(),
            default => null,
        };
        if (! empty($v['branch_id'])) {
            $query->where('company_branch_id', (int) $v['branch_id']);
        }
        if (! empty($v['department_id'])) {
            $query->where(fn ($q) => $q->where('department_id', (int) $v['department_id'])->orWhere('section_id', (int) $v['department_id']));
        }
        if (! empty($v['emp_type'])) {
            $query->where('emp_type', $v['emp_type']);
        }

        return $query;
    }

    private function person(Employee $e): array
    {
        return [
            'employee_no' => $e->employee_no,
            'name' => trim(preg_replace('/\s+/', ' ', "{$e->emp_title} {$e->fname} {$e->mname} {$e->lname}")),
            'department' => trim(($e->department?->name ?? '').($e->section ? ' / '.$e->section->name : '')),
            'position' => $e->jobPosition?->title,
        ];
    }

    private function columns(array $keys): array
    {
        $labels = [
            'employee_no' => 'No.', 'name' => 'Name', 'department' => 'Department', 'position' => 'Position', 'branch' => 'Branch',
            'phone_number' => 'Phone', 'email' => 'Email', 'date_of_birth' => 'Date of birth', 'birthday' => 'Birthday', 'age' => 'Age',
            'retires_on' => 'Retires on', 'employment_date' => 'Employed', 'emp_type' => 'Type', 'contract_type' => 'Contract type',
            'start_date' => 'Start', 'end_date' => 'End', 'left_on' => 'Date', 'reason' => 'Reason', 'days_left' => 'Days left',
            'renewal' => 'Renewal', 'licence' => 'Licence', 'licence_no' => 'Licence no.', 'licence_status' => 'Status',
            'type' => 'Type', 'status' => 'Status', 'leave_type' => 'Leave', 'gender' => 'Gender',
            'total' => 'Total', 'female' => 'Female', 'male' => 'Male', 'probation' => 'On probation', 'full_time' => 'Full time',
            'part_time' => 'Part time', 'others' => 'Others', 'year' => 'Year',
            'days' => 'Days', 'waiting_for' => 'Waiting for', 'period' => 'Leave year', 'entitled' => 'Entitled', 'carried_over' => 'Carried over',
            'adjustments' => 'Adjusted', 'taken' => 'Taken', 'pending' => 'Requested', 'available' => 'Available', 'date' => 'Date',
            'task' => 'Task', 'hours' => 'Hours', 'amount' => 'Amount', 'claims' => 'Claims', 'planned' => 'Planned', 'check_in' => 'In',
            'check_out' => 'Out', 'late_minutes' => 'Late (min)', 'lost_hours' => 'Lost hours', 'lost_amount' => 'Lost amount',
            'recorded' => 'Days recorded', 'present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'not_checked_out' => 'Not checked out',
            'on_leave' => 'On leave', 'other' => 'Other', 'reason' => 'Reason', 'accepted' => 'Hours accepted',
        ];

        return array_map(fn ($k) => ['key' => $k, 'label' => $labels[$k] ?? ucfirst(str_replace('_', ' ', $k))], $keys);
    }

    private function reportHeadcount(array $v, User $user): array
    {
        $rows = $this->employees($v, $user)->get()->groupBy(fn ($e) => $e->department?->name ?? 'No department')->sortKeys()
            ->map(fn (Collection $g, $name) => [
                'department' => $name,
                'total' => $g->count(),
                'female' => $g->where('gender', 'Female')->count(),
                'male' => $g->where('gender', 'Male')->count(),
                'full_time' => $g->where('emp_type', 'full_time')->count(),
                'part_time' => $g->where('emp_type', 'part_time')->count(),
                'others' => $g->whereNotIn('emp_type', ['full_time', 'part_time'])->count(),
                'probation' => $g->where('employment_status', 'probation')->count(),
            ])->values();

        if ($rows->isNotEmpty()) {
            $sum = fn ($k) => $rows->sum($k);
            $rows->push(['department' => 'Total'] + collect(['total', 'female', 'male', 'full_time', 'part_time', 'others', 'probation'])->mapWithKeys(fn ($k) => [$k => $sum($k)])->all());
        }

        return ['Active staff', ['department', 'total', 'female', 'male', 'full_time', 'part_time', 'others', 'probation'], $rows->all()];
    }

    private function reportContacts(array $v, User $user): array
    {
        $rows = $this->employees($v, $user)->orderBy('fname')->get()
            ->map(fn ($e) => $this->person($e) + ['phone_number' => $e->phone_number, 'email' => $e->email]);

        return ['Active staff', ['employee_no', 'name', 'department', 'position', 'phone_number', 'email'], $rows->all()];
    }

    private function reportBirthdays(array $v, User $user): array
    {
        $month = max(1, min(12, (int) $v['month']));
        $today = CarbonImmutable::today();
        $rows = $this->employees($v, $user)->whereNotNull('date_of_birth')->get()
            ->filter(fn ($e) => (int) $e->date_of_birth->month === $month)
            ->sortBy(fn ($e) => $e->date_of_birth->day)
            ->map(fn ($e) => $this->person($e) + [
                'birthday' => $e->date_of_birth->format('j F'),
                'age' => $today->year - $e->date_of_birth->year,
                'phone_number' => $e->phone_number,
            ]);

        return [CarbonImmutable::create(2000, $month, 1)->format('F').' (age turning this year)', ['employee_no', 'name', 'department', 'position', 'birthday', 'age', 'phone_number'], $rows->all()];
    }

    private function reportRetirement(array $v, User $user): array
    {
        $age = max(40, min(80, (int) $v['age']));
        $until = CarbonImmutable::today()->addMonths(max(0, (int) $v['months']));
        $rows = $this->employees($v, $user)->whereNotNull('date_of_birth')->get()
            ->map(fn ($e) => [$e, CarbonImmutable::parse($e->date_of_birth)->addYears($age)])
            ->filter(fn ($p) => $p[1]->lte($until))
            ->sortBy(fn ($p) => $p[1]->timestamp)
            ->map(fn ($p) => $this->person($p[0]) + [
                'date_of_birth' => $p[1]->subYears($age)->toDateString(),
                'age' => CarbonImmutable::parse($p[0]->date_of_birth)->age,
                'retires_on' => $p[1]->toDateString(),
                'status' => $p[1]->isPast() ? 'Past retirement age' : 'Upcoming',
            ]);

        return ["Age {$age}, up to ".$until->format('j M Y'), ['employee_no', 'name', 'department', 'position', 'date_of_birth', 'age', 'retires_on', 'status'], $rows->all()];
    }

    private function reportJoiners(array $v, User $user): array
    {
        $rows = $this->employees($v, $user, false)->with('activeContract.natureOfContract')
            ->whereDate('employment_date', '>=', $v['from'])->whereDate('employment_date', '<=', $v['to'])->orderBy('employment_date')->get()
            ->map(fn ($e) => $this->person($e) + [
                'employment_date' => $e->employment_date?->toDateString(),
                'emp_type' => O::EMPLOYMENT_TYPES[$e->emp_type] ?? $e->emp_type,
                'contract_type' => $e->activeContract?->natureOfContract?->contract_name,
                'status' => O::EMPLOYMENT_STATUSES[$e->employment_status] ?? $e->employment_status,
            ]);

        return [$this->period($v), ['employee_no', 'name', 'department', 'position', 'employment_date', 'emp_type', 'contract_type', 'status'], $rows->all()];
    }

    private function reportLeavers(array $v, User $user): array
    {
        $reasons = $v['reason'] ? [$v['reason']] : array_diff(array_keys(O::CONTRACT_END_REASONS), O::CONTRACT_KEEPS_EMPLOYEE);
        $ids = $this->employees($v, $user, false)->select('id');
        $rows = EmployeeContract::with(['employee.department', 'employee.section', 'employee.jobPosition', 'natureOfContract'])
            ->whereIn('employee_id', $ids)->where('is_active', false)->whereIn('status', $reasons)
            ->whereDate('date_of_change', '>=', $v['from'])->whereDate('date_of_change', '<=', $v['to'])->orderBy('date_of_change')->get()
            ->map(fn ($c) => $this->person($c->employee) + [
                'contract_type' => $c->natureOfContract?->contract_name,
                'start_date' => $c->start_date?->toDateString(),
                'left_on' => $c->date_of_change?->toDateString(),
                'reason' => O::contractStatusLabel($c->status),
            ]);

        return [$this->period($v), ['employee_no', 'name', 'department', 'position', 'contract_type', 'start_date', 'left_on', 'reason'], $rows->all()];
    }

    private function reportContractsEnding(array $v, User $user): array
    {
        $today = CarbonImmutable::today();
        $until = $today->addDays(max(0, (int) $v['days']));
        $ids = $this->employees($v, $user)->select('id');
        $rows = EmployeeContract::with(['employee.department', 'employee.section', 'employee.jobPosition', 'natureOfContract'])
            ->whereIn('employee_id', $ids)->where('is_active', true)->whereNotNull('end_date')->whereDate('end_date', '<=', $until)->orderBy('end_date')->get();
        $waiting = EmployeeContract::whereIn('employee_id', $rows->pluck('employee_id'))->where('status', 'waiting')->pluck('employee_id')->flip();

        return ['Up to '.$until->format('j M Y'), ['employee_no', 'name', 'department', 'position', 'contract_type', 'end_date', 'days_left', 'renewal'], $rows->map(fn ($c) => $this->person($c->employee) + [
            'contract_type' => $c->natureOfContract?->contract_name,
            'end_date' => $c->end_date->toDateString(),
            'days_left' => (int) $today->diffInDays($c->end_date, false),
            'renewal' => isset($waiting[$c->employee_id]) ? 'Next contract ready' : 'Not renewed yet',
        ])->all()];
    }

    private function reportLicences(array $v, User $user): array
    {
        $today = CarbonImmutable::today();
        $until = $today->addDays(max(0, (int) $v['days']));
        $ids = $this->employees($v, $user)->select('id');
        $licences = EmployeeLicence::with(['employee.department', 'employee.section', 'employee.jobPosition', 'professionalLicence'])
            ->whereIn('employee_id', $ids)
            ->when($v['licence_id'], fn ($q, $id) => $q->where('professional_licence_id', (int) $id))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_licences as n')->whereColumn('n.employee_id', 'employee_licences.employee_id')
                ->whereColumn('n.professional_licence_id', 'employee_licences.professional_licence_id')->whereColumn('n.end_date', '>', 'employee_licences.end_date'))
            ->when($v['status'] === 'expired', fn ($q) => $q->whereDate('end_date', '<', $today))
            ->when($v['status'] === 'expiring', fn ($q) => $q->whereDate('end_date', '<=', $until))
            ->orderBy('end_date')->get();

        $subtitle = match ($v['status']) {
            'expired' => 'Expired', 'all' => 'Latest licence of each type', default => 'Expired or expiring by '.$until->format('j M Y')
        };

        return [$subtitle, ['employee_no', 'name', 'department', 'position', 'licence', 'licence_no', 'end_date', 'licence_status'], $licences->map(fn ($l) => $this->person($l->employee) + [
            'licence' => $l->professionalLicence?->name,
            'licence_no' => $l->licence_no,
            'end_date' => $l->end_date?->toDateString(),
            'licence_status' => ! $l->end_date ? 'No end date' : ($l->end_date->lt($today) ? 'Expired' : ($l->end_date->lte($until) ? 'Expiring' : 'Valid')),
        ])->all()];
    }

    private function reportInterns(array $v, User $user): array
    {
        $ids = $this->employees($v, $user, 'people')->select('id');
        $rows = EmployeePlacement::with(['employee.department', 'employee.section', 'employee.jobPosition'])
            ->whereIn('employee_id', $ids)->where('status', '!=', 'cancelled')
            ->when($v['type'], fn ($q, $t) => $q->where('type', $t))
            ->whereDate('start_date', '<=', $v['to'])->whereDate('end_date', '>=', $v['from'])->orderBy('start_date')->get()
            ->map(fn ($p) => $this->person($p->employee) + [
                'type' => EmployeePlacement::TYPES[$p->type] ?? $p->type,
                'year' => $p->year,
                'start_date' => $p->start_date?->toDateString(),
                'end_date' => $p->end_date?->toDateString(),
                'status' => EmployeePlacement::STATUSES[$p->status] ?? $p->status,
            ]);

        return [$this->period($v).' (placements running in the period)', ['employee_no', 'name', 'department', 'type', 'year', 'start_date', 'end_date', 'status'], $rows->all()];
    }

    private function reportOnLeave(array $v, User $user): array
    {
        $date = CarbonImmutable::parse($v['date'] ?: 'today')->toDateString();
        $ids = $this->employees($v, $user)->select('id');
        $rows = EmpLeave::with(['employee.department', 'employee.section', 'employee.jobPosition', 'leaveType'])
            ->whereIn('employee_id', $ids)->whereIn('status', [EmpLeave::APPROVED, EmpLeave::ON_LEAVE])
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->orderBy('end_date')->get()
            ->map(fn ($l) => $this->person($l->employee) + [
                'leave_type' => $l->leaveType?->name,
                'start_date' => CarbonImmutable::parse($l->start_date)->toDateString(),
                'end_date' => CarbonImmutable::parse($l->end_date)->toDateString(),
            ]);

        return ['On '.CarbonImmutable::parse($date)->format('j M Y'), ['employee_no', 'name', 'department', 'position', 'leave_type', 'start_date', 'end_date'], $rows->all()];
    }

    private function leaveTypeOptions(bool $yearlyOnly = false): array
    {
        return LeaveType::query()->where('is_active', true)->orderByRaw("kind = 'annual' desc")->orderBy('name')->get()
            ->filter(fn ($t) => ! $yearlyOnly || $t->yearly())
            ->map(fn ($t) => ['value' => (string) $t->id, 'label' => $t->name])->values()->all();
    }

    private function money(User $user): bool
    {
        return $this->sheet->seesSalary($user);
    }

    private function reportLeaveRequests(array $v, User $user): array
    {
        $ids = $this->employees($v, $user, false)->select('id');
        $rows = EmpLeave::with(['employee.department', 'employee.section', 'employee.jobPosition', 'leaveType', 'workflow.steps'])
            ->whereIn('employee_id', $ids)
            ->when($v['status'], fn ($q, $st) => $q->where('status', $st))
            ->when($v['leave_type_id'], fn ($q, $t) => $q->where('leave_type_id', (int) $t))
            ->whereDate('start_date', '<=', $v['to'])->whereDate('end_date', '>=', $v['from'])->orderBy('start_date')->get()
            ->map(fn ($l) => $this->person($l->employee) + [
                'leave_type' => $l->leaveType?->name,
                'start_date' => $l->start_date?->toDateString(),
                'end_date' => $l->end_date?->toDateString(),
                'days' => (float) $l->days,
                'status' => EmpLeave::STATUS_LABELS[$l->status] ?? $l->status,
                'waiting_for' => $l->status === EmpLeave::PENDING ? $l->workflow?->steps?->firstWhere('status', 'pending')?->name : null,
            ]);

        return [$this->period($v).' (leave overlapping the period)', ['employee_no', 'name', 'department', 'leave_type', 'start_date', 'end_date', 'days', 'status', 'waiting_for'], $rows->all()];
    }

    private function reportLeaveBalances(array $v, User $user): array
    {
        $type = LeaveType::find((int) $v['leave_type_id']);
        if (! $type) {
            return ['Choose a leave type', ['employee_no', 'name'], []];
        }
        $rows = $this->employees($v, $user)->orderBy('fname')->get()
            ->map(fn ($e) => [$e, $this->balances->for($e, $type)])
            ->filter(fn ($p) => $p[1]['eligible'] || $p[1]['taken'] > 0)
            ->map(fn ($p) => $this->person($p[0]) + [
                'period' => $p[1]['period_label'],
                'entitled' => $p[1]['entitled'],
                'carried_over' => $p[1]['carried_over'],
                'adjustments' => $p[1]['adjustments'],
                'taken' => $p[1]['taken'],
                'pending' => $p[1]['pending'],
                'available' => $p[1]['available'],
            ]);
        $this->balances->forget();

        return [$type->name.', current leave year of each employee', ['employee_no', 'name', 'department', 'period', 'entitled', 'carried_over', 'adjustments', 'taken', 'pending', 'available'], $rows->all()];
    }

    private function reportOvertime(array $v, User $user): array
    {
        $money = $this->money($user);
        $ids = $this->employees($v, $user, false)->select('id');
        $rows = Overtime::with(['employee.department', 'employee.section', 'employee.jobPosition', 'workflow.steps'])
            ->whereIn('employee_id', $ids)
            ->when($v['status'], fn ($q, $st) => $q->where('status', $st))
            ->whereDate('date_of_task_done', '>=', $v['from'])->whereDate('date_of_task_done', '<=', $v['to'])->orderBy('date_of_task_done')->get()
            ->map(fn ($o) => $this->person($o->employee) + [
                'date' => CarbonImmutable::parse($o->date_of_task_done)->toDateString(),
                'task' => $o->task_title,
                'hours' => round(((int) $o->overtime_minutes) / 60, 2),
                ...($money ? ['amount' => (float) $o->amount] : []),
                'status' => Overtime::STATUS_LABELS[$o->status] ?? $o->status,
                'waiting_for' => $o->status === 'pending' ? $o->workflow?->steps?->firstWhere('status', 'pending')?->name : null,
            ]);
        $columns = array_values(array_filter(['employee_no', 'name', 'department', 'date', 'task', 'hours', $money ? 'amount' : null, 'status', 'waiting_for']));

        return [$this->period($v), $columns, $rows->all()];
    }

    private function reportOvertimeSummary(array $v, User $user): array
    {
        $money = $this->money($user);
        $ids = $this->employees($v, $user, false)->select('id');
        $rows = Overtime::with(['employee.department', 'employee.section', 'employee.jobPosition'])
            ->whereIn('employee_id', $ids)->where('status', 'approved')
            ->whereDate('date_of_task_done', '>=', $v['from'])->whereDate('date_of_task_done', '<=', $v['to'])->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $g) => $this->person($g->first()->employee) + [
                'claims' => $g->count(),
                'hours' => round($g->sum('overtime_minutes') / 60, 2),
                ...($money ? ['amount' => round((float) $g->sum('amount'), 2)] : []),
            ])->sortByDesc('hours')->values();
        if ($rows->isNotEmpty()) {
            $rows->push(['employee_no' => '', 'name' => 'Total', 'department' => '', 'position' => '', 'claims' => $rows->sum('claims'),
                'hours' => round($rows->sum('hours'), 2), ...($money ? ['amount' => round($rows->sum('amount'), 2)] : [])]);
        }

        return [$this->period($v).' (approved claims)', array_values(array_filter(['employee_no', 'name', 'department', 'position', 'claims', 'hours', $money ? 'amount' : null])), $rows->all()];
    }

    private function reportAttendanceDaily(array $v, User $user): array
    {
        $money = $this->money($user);
        $ids = $this->employees($v, $user, 'duty')->where('has_attendance', true)->select('id');
        $time = fn ($t) => $t ? CarbonImmutable::parse($t)->format('H:i') : null;
        $rows = AttendanceTransa::with(['employee.department', 'employee.section', 'employee.jobPosition'])
            ->whereIn('employee_id', $ids)
            ->when($v['status'], fn ($q, $st) => $q->where('status', $st))
            ->whereDate('transaction_date', '>=', $v['from'])->whereDate('transaction_date', '<=', $v['to'])
            ->orderBy('transaction_date')->get()
            ->sortBy(fn ($t) => $t->transaction_date->toDateString().' '.$t->employee?->fname)
            ->map(fn ($t) => $this->person($t->employee) + [
                'date' => $t->transaction_date->toDateString(),
                'planned' => $t->planned_code ?: ($t->attendance_in_time ? $time($t->attendance_in_time).'–'.$time($t->attendance_out_time) : null),
                'check_in' => $time($t->punch_in_time),
                'check_out' => $time($t->punch_out_time),
                'status' => $t->status,
                'late_minutes' => (int) $t->punch_in_late_minutes ?: null,
                'lost_hours' => (float) $t->lost_hour ?: null,
                ...($money ? ['lost_amount' => ((float) $t->lost_amount ?: null)] : []),
            ]);

        return [$this->period($v), array_values(array_filter(['employee_no', 'name', 'department', 'date', 'planned', 'check_in', 'check_out', 'status', 'late_minutes', 'lost_hours', $money ? 'lost_amount' : null])), $rows->values()->all()];
    }

    private function reportAttendanceSummary(array $v, User $user): array
    {
        $money = $this->money($user);
        $employees = $this->employees($v, $user, 'duty')->where('has_attendance', true)->orderBy('fname')->get()->keyBy('id');
        $days = AttendanceTransa::whereIn('employee_id', $employees->keys())
            ->whereDate('transaction_date', '>=', $v['from'])->whereDate('transaction_date', '<=', $v['to'])
            ->get(['employee_id', 'status', 'punch_in_late_minutes', 'lost_hour', 'lost_amount'])->groupBy('employee_id');
        $known = ['Present', 'Late', 'Absent', 'Not checked out', 'On leave'];

        $rows = $employees->map(function ($e) use ($days, $known, $money) {
            $d = $days->get($e->id, collect());

            return $this->person($e) + [
                'recorded' => $d->count(),
                'present' => $d->where('status', 'Present')->count(),
                'late' => $d->where('status', 'Late')->count(),
                'absent' => $d->where('status', 'Absent')->count(),
                'not_checked_out' => $d->where('status', 'Not checked out')->count(),
                'on_leave' => $d->where('status', 'On leave')->count(),
                'other' => $d->whereNotIn('status', $known)->count(),
                'late_minutes' => (int) $d->sum('punch_in_late_minutes'),
                'lost_hours' => round((float) $d->sum('lost_hour'), 2),
                ...($money ? ['lost_amount' => round((float) $d->sum('lost_amount'), 2)] : []),
            ];
        })->values();

        $columns = ['employee_no', 'name', 'department', 'recorded', 'present', 'late', 'absent', 'not_checked_out', 'on_leave', 'other', 'late_minutes', 'lost_hours'];

        return [$this->period($v).' (other = days off, holidays, admitted, excused, waiting)', $money ? [...$columns, 'lost_amount'] : $columns, $rows->all()];
    }

    private function reportAttendanceClaims(array $v, User $user): array
    {
        $money = $this->money($user);
        $ids = $this->employees($v, $user, 'people')->select('id');
        $labels = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Withdrawn'];
        $rows = AttendanceClaim::with(['employee.department', 'employee.section', 'employee.jobPosition', 'transaction', 'reason'])
            ->whereIn('employee_id', $ids)
            ->when($v['status'], fn ($q, $st) => $q->where('claim_status', $st))
            ->whereHas('transaction', fn ($q) => $q->whereDate('transaction_date', '>=', $v['from'])->whereDate('transaction_date', '<=', $v['to']))
            ->get()
            ->sortBy(fn ($c) => $c->transaction?->transaction_date?->toDateString())
            ->map(fn ($c) => $this->person($c->employee) + [
                'date' => $c->transaction?->transaction_date?->toDateString(),
                'reason' => $c->reason?->reason ?? $c->description,
                'lost_hours' => (float) $c->lost_hour,
                'accepted' => (float) $c->hr_accepted,
                ...($money ? ['lost_amount' => (float) $c->lost_amount] : []),
                'status' => $labels[$c->claim_status] ?? $c->claim_status,
            ]);

        return [$this->period($v).' (by attendance day)', array_values(array_filter(['employee_no', 'name', 'department', 'date', 'reason', 'lost_hours', 'accepted', $money ? 'lost_amount' : null, 'status'])), $rows->values()->all()];
    }

    private function period(array $v): string
    {
        return CarbonImmutable::parse($v['from'])->format('j M Y').' – '.CarbonImmutable::parse($v['to'])->format('j M Y');
    }
}
