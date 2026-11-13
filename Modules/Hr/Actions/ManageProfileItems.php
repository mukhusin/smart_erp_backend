<?php

namespace Modules\Hr\Actions;

use App\Models\Employee;
use App\Models\EmployeeDependant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\ProfileSections;
use Modules\Hr\Workflow\ProfileItemProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

/** Records an employee adds to their own profile: pending until HR approves them through the workflow. */
class ManageProfileItems
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    public function submit(Employee $employee, string $section, array $data, ?UploadedFile $file, User $by): Model
    {
        $config = ProfileSections::SECTIONS[$section];

        return DB::transaction(function () use ($employee, $config, $data, $file, $by) {
            $item = $employee->{$config['relation']}()->make(array_intersect_key($data, array_flip($config['fields'])));
            if ($file && $config['file']) {
                $item->{$config['file']['path']} = EmployeeFiles::store($file, $employee, $config['file']['folder']);
                $item->{$config['file']['name']} = $file->getClientOriginalName();
            }
            if (array_key_exists('is_next_of_kin', $data)) {
                $item->is_next_of_kin = (bool) $data['is_next_of_kin'];
            }
            $item->forceFill(['status' => 'pending', 'submitted_by' => $by->id])->save();

            if (! $this->workflow->start(ProfileItemProcess::KEY, $item, $by)) {
                $this->approve($item);
            }

            return $item->refresh();
        });
    }

    public function approve(Model $item): void
    {
        $item->forceFill(['status' => 'approved', 'decision_comment' => null])->save();
        if ($item instanceof EmployeeDependant && $item->is_next_of_kin) {
            $item->employee->dependants()->whereKeyNot($item->id)->update(['is_next_of_kin' => false]);
        }
    }

    public function reject(Model $item, ?string $comment): void
    {
        $item->forceFill(['status' => 'rejected', 'decision_comment' => $comment])->save();
    }

    /** The employee takes back a pending record, or removes one HR did not approve. */
    public function withdraw(Model $item, string $section, User $by): void
    {
        if (! in_array($item->status, ['pending', 'rejected'], true)) {
            throw ValidationException::withMessages(['item' => 'Approved records can only be changed by HR.']);
        }
        if ($item->status === 'pending') {
            $instance = WorkflowInstance::where('subject_type', $item->getMorphClass())->where('subject_id', $item->id)
                ->where('status', WorkflowInstance::PENDING)->first();
            if ($instance) {
                $this->workflow->cancel($instance, $by);
            }
        }
        $file = ProfileSections::SECTIONS[$section]['file'];
        if ($file) {
            EmployeeFiles::delete($item->{$file['path']});
        }
        $item->delete();
    }
}
