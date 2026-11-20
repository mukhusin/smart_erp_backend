<?php

namespace Modules\Hr\Actions;

use App\Models\AllowanceBatch;
use App\Models\AllowanceBatchLine;
use App\Models\AllowanceCategory;
use App\Models\DailyAllowance;
use App\Models\Department;
use App\Models\DepartmentAllowance;
use App\Models\EmpLeave;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Support\EmployeeScope;
use Modules\Hr\Workflow\AllowanceBatchProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Daily batches of count-based allowances (e.g. theatre cases): a head enters a day's counts for their team, keeps
 * the batch as a draft, then sends it for approval as one request. Approved lines become approved allowances.
 * Employees on leave that day cannot be added when the category deducts leave.
 */
class ManageAllowanceBatches
{
    public function __construct(
        private readonly ManageAllowances $allowances,
        private readonly WorkflowEngine $workflow,
        private readonly EmployeeScope $scope,
    ) {}

    /** Claimed allowances that are not monthly. @return Collection<int, AllowanceCategory> */
    public function categories(): Collection
    {
        return AllowanceCategory::where('is_active', true)->where('generation', 'claim')->where('duration', '!=', 'month')->orderBy('category')->get();
    }

    /** Departments and sections the user has employees in. @return Collection<int, Department> */
    public function teams(User $user): Collection
    {
        $ids = $this->scope->apply(Employee::query(), $user)->where('employment_status', '!=', 'terminated')
            ->get(['department_id', 'section_id'])->flatMap(fn ($e) => [$e->department_id, $e->section_id])->filter()->unique();

        return Department::whereIn('id', $ids)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id']);
    }

    /**
     * The allowances each team has a rate for (its own, or its department's when it is a section).
     *
     * @param  Collection<int, Department>  $teams
     * @return Collection<int, array{category_id: int, category: string, department_id: int, department: string}>
     */
    public function choices(Collection $teams): Collection
    {
        $categories = $this->categories();
        $rates = DepartmentAllowance::whereIn('category_id', $categories->pluck('id'))->where('is_active', true)->get();

        return $categories->flatMap(fn (AllowanceCategory $c) => $teams->filter(fn (Department $t) => $rates->where('category_id', $c->id)->contains(fn ($r) => $this->covers($r, $t)))
            ->map(fn (Department $t) => ['category_id' => $c->id, 'category' => $c->category, 'department_id' => $t->id, 'department' => $t->name]))->values();
    }

    private function covers(DepartmentAllowance $rate, Department $team): bool
    {
        return (int) $rate->section_id === $team->id || (int) $rate->department_id === $team->id
            || (! $rate->section_id && $team->parent_id && (int) $rate->department_id === (int) $team->parent_id);
    }

    /** @param array{allowance_category_id: int, department_id: int, date: string, notes?: string|null} $data */
    public function create(array $data, User $by): AllowanceBatch
    {
        $category = $this->categories()->firstWhere('id', (int) $data['allowance_category_id']);
        if (! $category) {
            throw ValidationException::withMessages(['allowance_category_id' => 'Choose an allowance that is claimed with a count.']);
        }
        $team = Department::find($data['department_id']);
        if (! $team || ! $this->teamQuery($by, $team->id)->exists()) {
            throw ValidationException::withMessages(['department_id' => 'You have no employees in this department or section.']);
        }
        if ($this->choices(collect([$team]))->where('category_id', $category->id)->isEmpty()) {
            throw ValidationException::withMessages(['allowance_category_id' => "{$category->category} has no rate for {$team->name}."]);
        }
        $date = Carbon::parse($data['date'])->startOfDay();
        if ($date->isFuture()) {
            throw ValidationException::withMessages(['date' => 'Enter allowances for days that have passed.']);
        }
        $exists = AllowanceBatch::where('allowance_category_id', $category->id)->where('department_id', $data['department_id'])->whereDate('date', $date)->first();
        if ($exists) {
            throw ValidationException::withMessages(['date' => "There is already a {$category->category} batch for this team on {$date->format('d M Y')}."]);
        }

        return AllowanceBatch::create([
            'allowance_category_id' => $category->id, 'department_id' => $data['department_id'], 'date' => $date, 'status' => 'draft',
            'notes' => $data['notes'] ?? null, 'created_by' => $by->id,
        ]);
    }

    /** Employees of the batch's team the user may enter. */
    public function teamQuery(User $user, int $departmentId): Builder
    {
        return $this->scope->apply(Employee::query(), $user)->where('employment_status', '!=', 'terminated')
            ->where(fn ($q) => $q->where('department_id', $departmentId)->orWhere('section_id', $departmentId));
    }

    /** @return array<int, string> employee id => leave type name, for those on leave on the date */
    public function onLeave(Collection $employeeIds, Carbon $date): array
    {
        return EmpLeave::with('leaveType')->whereIn('employee_id', $employeeIds)->whereIn('status', EmpLeave::TAKEN)
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->get()
            ->mapWithKeys(fn ($l) => [$l->employee_id => $l->leaveType?->name ?? 'Leave'])->all();
    }

    /**
     * The team with what stops each employee being added.
     *
     * @return Collection<int, array{employee: Employee, rate: DepartmentAllowance|null, blocked: string|null}>
     */
    public function candidates(AllowanceBatch $batch, User $user, ?string $search = null): Collection
    {
        $employees = $this->teamQuery($user, $batch->department_id)->with('jobPosition')
            ->when($search, fn ($q, $t) => $q->where(fn ($q) => $q->where('fname', 'like', "%{$t}%")->orWhere('lname', 'like', "%{$t}%")->orWhere('employee_no', 'like', "%{$t}%")))
            ->orderBy('fname')->orderBy('lname')->get();
        $rates = DepartmentAllowance::where('category_id', $batch->allowance_category_id)->where('is_active', true)->get();
        $leave = $batch->category->deduct_leave ? $this->onLeave($employees->pluck('id'), $batch->date) : [];

        return $employees->map(function (Employee $e) use ($batch, $rates, $leave) {
            $rate = $this->allowances->rateFor($e, $batch->category, $rates);
            $blocked = match (true) {
                isset($leave[$e->id]) => "On {$leave[$e->id]}",
                ! $this->allowances->eligible($e) => 'Contract does not give allowances',
                ! $rate => 'No rate for their department and position',
                default => null,
            };

            return ['employee' => $e, 'rate' => $rate, 'blocked' => $blocked];
        });
    }

    public function setLine(AllowanceBatch $batch, int $employeeId, float $items, User $user): AllowanceBatchLine
    {
        $this->assertEditable($batch);
        $row = $this->candidates($batch, $user)->first(fn ($c) => $c['employee']->id === $employeeId);
        if (! $row) {
            throw ValidationException::withMessages(['employee_id' => 'This employee is not in the team.']);
        }
        if ($row['blocked']) {
            throw ValidationException::withMessages(['employee_id' => "{$row['employee']->full_name}: {$row['blocked']}."]);
        }
        $this->assertNotElsewhere($batch, collect([$employeeId]));
        $rate = $row['rate'];

        return AllowanceBatchLine::updateOrCreate(['allowance_batch_id' => $batch->id, 'employee_id' => $employeeId], [
            'items' => $items, 'amount_per_item' => $rate->amount, 'amount' => round($items * $rate->amount, 2), 'department_allowance_id' => $rate->id,
        ]);
    }

    public function removeLine(AllowanceBatch $batch, AllowanceBatchLine $line): void
    {
        $this->assertEditable($batch);
        abort_unless($line->allowance_batch_id === $batch->id, 404);
        $line->delete();
    }

    public function submit(AllowanceBatch $batch, User $user): AllowanceBatch
    {
        $this->assertEditable($batch);
        $batch->load('lines.employee', 'category');
        if ($batch->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Add at least one employee before sending.']);
        }
        $candidates = $this->candidates($batch, $user)->keyBy(fn ($c) => $c['employee']->id);
        foreach ($batch->lines as $line) {
            $c = $candidates[$line->employee_id] ?? null;
            $why = $c ? $c['blocked'] : 'No longer in the team';
            if ($why) {
                throw ValidationException::withMessages(['lines' => ($line->employee?->full_name ?? 'An employee').": {$why}. Remove them before sending."]);
            }
            $line->update(['amount_per_item' => $c['rate']->amount, 'amount' => round($line->items * $c['rate']->amount, 2), 'department_allowance_id' => $c['rate']->id]);
        }
        $this->assertNotElsewhere($batch, $batch->lines->pluck('employee_id'));
        $batch->update(['status' => 'pending', 'submitted_at' => now(), 'decided_at' => null, 'decision_comment' => null]);
        if (! $this->workflow->start(AllowanceBatchProcess::KEY, $batch, $user)) {
            $this->approve($batch);
        }

        return $batch->refresh();
    }

    public function approve(AllowanceBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            $batch->load('lines.employee', 'category');
            foreach ($batch->lines as $line) {
                $entry = DailyAllowance::create([
                    'employee_id' => $line->employee_id, 'department_allowance_id' => $line->department_allowance_id, 'allowance_category_id' => $batch->allowance_category_id,
                    'category' => $batch->category->category, 'duration' => $batch->category->duration, 'items' => $line->items, 'amount_per_item' => $line->amount_per_item,
                    'amount' => $line->amount, 'start_date' => $batch->date, 'end_date' => $batch->date, 'status' => 'approved', 'is_active' => true, 'is_hr_approved' => true,
                    'source' => 'batch', 'requested_by' => $batch->created_by, 'decided_at' => now(),
                ]);
                $line->update(['daily_allowance_id' => $entry->id]);
            }
            $batch->update(['status' => 'approved', 'decided_at' => now()]);
        });
    }

    public function reject(AllowanceBatch $batch, ?string $comment): void
    {
        $withdrawn = $comment === 'Withdrawn by the requester.';
        $batch->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => $withdrawn ? null : now()]);
    }

    public function delete(AllowanceBatch $batch): void
    {
        $this->assertEditable($batch);
        $batch->delete();
    }

    private function assertEditable(AllowanceBatch $batch): void
    {
        if (! $batch->editable()) {
            throw ValidationException::withMessages(['status' => 'Only a draft or a batch that was not approved can be changed.']);
        }
    }

    private function assertNotElsewhere(AllowanceBatch $batch, Collection $employeeIds): void
    {
        $other = AllowanceBatchLine::with('employee', 'batch.department')->whereIn('employee_id', $employeeIds)->where('allowance_batch_id', '!=', $batch->id)
            ->whereHas('batch', fn ($q) => $q->where('allowance_category_id', $batch->allowance_category_id)->whereDate('date', $batch->date)->where('status', '!=', 'rejected'))
            ->first();
        if ($other) {
            throw ValidationException::withMessages(['employee_id' => "{$other->employee->full_name} is already in the {$other->batch->department?->name} batch for this day."]);
        }
    }
}
