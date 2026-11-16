<?php

namespace Modules\Appraisals\Actions;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Appraisals\Models\Appraisal;
use Modules\Appraisals\Models\AppraisalCompetency;
use Modules\Appraisals\Models\AppraisalCycle;
use Modules\Appraisals\Models\AppraisalSetting;
use Modules\Core\Notifications\Notice;

class ManageCycles
{
    public function create(array $data, User $by): AppraisalCycle
    {
        return AppraisalCycle::create($data + ['status' => 'draft', 'config' => AppraisalSetting::current()->config(), 'created_by' => $by->id]);
    }

    public function update(AppraisalCycle $cycle, array $data): AppraisalCycle
    {
        if ($cycle->status === 'closed') {
            throw ValidationException::withMessages(['cycle' => 'This period is closed.']);
        }
        $cycle->update($data);

        return $cycle;
    }

    public function open(AppraisalCycle $cycle, array $departmentIds = []): int
    {
        if ($cycle->status === 'closed') {
            throw ValidationException::withMessages(['cycle' => 'This period is closed.']);
        }
        $settings = AppraisalSetting::current()->config();
        $competencies = AppraisalCompetency::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'description'])->toArray();
        $parts = array_filter($settings['parts'], fn ($p) => $p['enabled'] ?? true);
        if (! $parts) {
            throw ValidationException::withMessages(['cycle' => 'Switch on at least one score part in appraisal settings.']);
        }
        if (collect($parts)->contains('key', 'competencies') && ! $competencies) {
            throw ValidationException::withMessages(['cycle' => 'Add the competencies to rate first (Appraisal settings).']);
        }

        return DB::transaction(function () use ($cycle, $departmentIds, $settings, $competencies) {
            if ($cycle->status === 'draft') {
                $cycle->update(['status' => 'open', 'opened_at' => now(), 'config' => $settings + ['competencies' => $competencies]]);
            }
            $existing = $cycle->appraisals()->pluck('employee_id')->all();
            $staff = Employee::query()->active()->staff()->with('user')
                ->when($departmentIds, fn ($q) => $q->where(fn ($q) => $q->whereIn('department_id', $departmentIds)->orWhereIn('section_id', $departmentIds)))
                ->whereNotIn('id', $existing)->get();
            foreach ($staff as $e) {
                Appraisal::create(['appraisal_cycle_id' => $cycle->id, 'employee_id' => $e->id, 'department_id' => $e->department_id, 'status' => 'open']);
                $e->user?->notify(new Notice('Appraisal opened', "Your appraisal for {$cycle->name} is open.".($cycle->self_due ? ' Send your self-assessment by '.$cycle->self_due->format('d M Y').'.' : ''), '/appraisals/mine', 'star-half'));
            }

            return $staff->count();
        });
    }

    public function close(AppraisalCycle $cycle): AppraisalCycle
    {
        if ($cycle->status !== 'open') {
            throw ValidationException::withMessages(['cycle' => 'Only an open period can be closed.']);
        }
        $cycle->update(['status' => 'closed', 'closed_at' => now()]);

        return $cycle;
    }

    public function delete(AppraisalCycle $cycle): void
    {
        if ($cycle->status !== 'draft') {
            throw ValidationException::withMessages(['cycle' => 'Only a draft period can be deleted.']);
        }
        $cycle->delete();
    }
}
