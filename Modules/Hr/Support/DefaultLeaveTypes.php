<?php

namespace Modules\Hr\Support;

use App\Models\Company;
use App\Models\LeaveType;
use App\Services\CompanyContext;
use Modules\Hr\Workflow\LeaveRequestProcess;
use Modules\Hr\Workflow\OvertimeClaimProcess;
use Modules\Hr\Workflow\ProfileItemProcess;
use Modules\Workflow\Models\WorkflowTemplate;
use Spatie\Permission\Models\Role;

/**
 * Leave types a new company starts with (Tanzania, Employment and Labour Relations Act 2004). HR can change them.
 */
final class DefaultLeaveTypes
{
    public const TYPES = [
        ['code' => 'AL', 'name' => 'Annual leave', 'kind' => 'annual', 'max_days' => 28, 'day_count' => 'calendar', 'limit_per' => 'year', 'carry_over_days' => 28, 'notice_days' => 14, 'color' => 'blue'],
        ['code' => 'SL', 'name' => 'Sick leave', 'kind' => 'sick', 'max_days' => 126, 'day_count' => 'calendar', 'limit_per' => 'year', 'requires_document' => true, 'color' => 'red'],
        ['code' => 'ML', 'name' => 'Maternity leave', 'kind' => 'maternity', 'gender' => 'female', 'max_days' => 84, 'day_count' => 'calendar', 'limit_per' => 'request', 'requires_document' => true, 'color' => 'pink'],
        ['code' => 'PL', 'name' => 'Paternity leave', 'kind' => 'paternity', 'gender' => 'male', 'max_days' => 3, 'day_count' => 'working', 'limit_per' => 'request', 'requires_document' => true, 'color' => 'indigo'],
        ['code' => 'CL', 'name' => 'Compassionate leave', 'kind' => 'compassionate', 'max_days' => 4, 'day_count' => 'working', 'limit_per' => 'request', 'color' => 'slate'],
        ['code' => 'UL', 'name' => 'Leave without pay', 'kind' => 'unpaid', 'with_pay' => false, 'max_days' => 30, 'day_count' => 'working', 'limit_per' => 'request', 'notice_days' => 7, 'color' => 'amber'],
    ];

    public static function createForCompany(Company $company): void
    {
        app(CompanyContext::class)->run($company, function () {
            if (LeaveType::withTrashed()->exists()) {
                return;
            }
            foreach (self::TYPES as $type) {
                LeaveType::create($type + ['gender' => 'all', 'with_pay' => true, 'requires_document' => false, 'notice_days' => 0, 'is_active' => true]);
            }
            self::createWorkflow();
        });
    }

    /** Leave and overtime approval as HRMS: the supervisor (or head of department), then HR. */
    public static function createWorkflow(): void
    {
        self::supervisorThenHr(LeaveRequestProcess::KEY);
        self::supervisorThenHr(OvertimeClaimProcess::KEY);
        self::hrOnly(ProfileItemProcess::KEY);
    }

    public static function hrOnly(string $process): void
    {
        $hr = Role::where('name', 'hr-officer')->where('company_id', app(CompanyContext::class)->id())->first();
        if (! $hr || WorkflowTemplate::where('process', $process)->exists()) {
            return;
        }
        $template = WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true]);
        $template->steps()->create(['step_order' => 1, 'name' => 'HR', 'approver_type' => 'role', 'role_id' => $hr->id, 'allow_send_back' => false]);
    }

    public static function supervisorThenHr(string $process): void
    {
        if (WorkflowTemplate::where('process', $process)->exists()) {
            return;
        }
        $hr = Role::where('name', 'hr-officer')->where('company_id', app(CompanyContext::class)->id())->first();
        $template = WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true]);
        $template->steps()->create(['step_order' => 1, 'name' => 'Supervisor / Head of Department', 'approver_type' => 'supervisor', 'allow_send_back' => false, 'approve_label' => 'Recommend']);
        if ($hr) {
            $template->steps()->create(['step_order' => 2, 'name' => 'HR', 'approver_type' => 'role', 'role_id' => $hr->id, 'allow_send_back' => true]);
        }
    }
}
