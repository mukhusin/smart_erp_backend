<?php

namespace Modules\Workflow\Services;

use App\Services\ModuleAccessService;
use Modules\Admin\Workflow\UserAccessChangeProcess;
use Modules\Hr\Workflow\AllowanceClaimProcess;
use Modules\Hr\Workflow\AssignedAllowanceProcess;
use Modules\Hr\Workflow\AttendanceClaimProcess;
use Modules\Hr\Workflow\ContractRenewalProcess;
use Modules\Hr\Workflow\DutyRosterProcess;
use Modules\Hr\Workflow\LeaveRequestProcess;
use Modules\Hr\Workflow\LoanProcess;
use Modules\Hr\Workflow\OtherDeductionProcess;
use Modules\Hr\Workflow\OvertimeClaimProcess;
use Modules\Hr\Workflow\PayrollSheetProcess;
use Modules\Hr\Workflow\ProfileItemProcess;
use Modules\Hr\Workflow\SalaryAdjustmentProcess;
use Modules\Hr\Workflow\SalaryAdvanceProcess;
use Modules\Workflow\Contracts\WorkflowProcess;

/**
 * Every process that can need approval. Modules add theirs here
 * (leave request, overtime, salary adjustment, payroll sheet, contract renewal...).
 */
final class WorkflowCatalog
{
    /** @var list<class-string<WorkflowProcess>> */
    private const PROCESSES = [
        UserAccessChangeProcess::class,
        ContractRenewalProcess::class,
        DutyRosterProcess::class,
        LeaveRequestProcess::class,
        AllowanceClaimProcess::class,
        OvertimeClaimProcess::class,
        PayrollSheetProcess::class,
        LoanProcess::class,
        SalaryAdvanceProcess::class,
        AttendanceClaimProcess::class,
        AssignedAllowanceProcess::class,
        OtherDeductionProcess::class,
        SalaryAdjustmentProcess::class,
        ProfileItemProcess::class,
    ];

    /** @return array<string, WorkflowProcess> key => process */
    public function all(): array
    {
        $out = [];
        foreach (self::PROCESSES as $class) {
            $process = app($class);
            $out[$process->key()] = $process;
        }

        return $out;
    }

    public function get(string $key): WorkflowProcess
    {
        return $this->all()[$key] ?? throw new \InvalidArgumentException("Unknown workflow process [$key].");
    }

    /** Processes whose module the current company can use. @return array<string, WorkflowProcess> */
    public function forCurrentCompany(): array
    {
        $modules = app(ModuleAccessService::class)->allForDashboard()->where('has_access', true)->pluck('slug')->all();

        return array_filter($this->all(), fn (WorkflowProcess $p) => in_array($p->module(), $modules, true));
    }
}
