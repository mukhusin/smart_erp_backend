<?php

namespace Modules\Hr\Support;

use App\Models\Company;
use App\Models\LeaveType;
use App\Services\CompanyContext;
use Modules\Hr\Workflow\LeaveRequestProcess;
use Modules\Hr\Workflow\OvertimeClaimProcess;
use Modules\Hr\Workflow\ProfileItemProcess;
use Modules\Hr\Workflow\SalaryAdvanceProcess;
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
        self::supervisorThenHr(SalaryAdvanceProcess::KEY);
        self::departmentHead(\Modules\Hr\Workflow\DutyRosterProcess::KEY);
        self::departmentHead(\Modules\Hr\Workflow\DutyRosterChangeProcess::KEY);
        self::supervisorOnly(\Modules\Tasks\Workflow\TaskProgressProcess::KEY);
        self::appraisal();
        self::departmentPlan();
        self::manualJournal();
        self::budgeting();
        self::payments();
        self::receivables();
        self::procurement();
        self::assets();
    }

    public static function budgeting(): void
    {
        $company = app(CompanyContext::class)->id();
        $finance = Role::where('name', 'Chief Accountant')->where('company_id', $company)->first() ?? Role::where('name', 'company-admin')->where('company_id', $company)->first();
        $director = Role::where('name', 'director-general')->where('company_id', $company)->first() ?? $finance;
        if (! $finance) {
            return;
        }
        foreach (['department-budget' => ['Finance', $finance], 'budget-approval' => ['Director general', $director], 'budget-transfer' => ['Finance', $finance], 'budget-release' => ['Finance', $finance]] as $process => [$name, $role]) {
            if (WorkflowTemplate::where('process', $process)->exists()) {
                continue;
            }
            $template = WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true]);
            $template->steps()->create(['step_order' => 1, 'name' => $name, 'approver_type' => 'role', 'role_id' => $role->id, 'allow_send_back' => true]);
        }
    }

    public static function payments(): void
    {
        $company = app(CompanyContext::class)->id();
        $finance = Role::where('name', 'Chief Accountant')->where('company_id', $company)->first() ?? Role::where('name', 'company-admin')->where('company_id', $company)->first();
        $director = Role::where('name', 'director-general')->where('company_id', $company)->first();
        if (! $finance) {
            return;
        }
        $chains = [
            'payment-request' => [['Head of department', 'department_head', null, null], ['Finance', 'role', $finance->id, null]],
            'payment-voucher' => array_values(array_filter([['Chief accountant', 'role', $finance->id, null], $director ? ['Director general', 'role', $director->id, 10000000] : null])),
            'payment-list' => [['Chief accountant', 'role', $finance->id, null]],
            'payment-void' => array_values(array_filter([['Chief accountant', 'role', $finance->id, null], $director ? ['Director general', 'role', $director->id, null] : null])),
        ];
        foreach ($chains as $process => $steps) {
            if (WorkflowTemplate::where('process', $process)->exists()) {
                continue;
            }
            $template = WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true]);
            foreach ($steps as $i => [$name, $type, $role, $min]) {
                $template->steps()->create(['step_order' => $i + 1, 'name' => $name, 'approver_type' => $type, 'role_id' => $role, 'min_amount' => $min, 'allow_send_back' => true]);
            }
        }
    }

    public static function receivables(): void
    {
        $company = app(CompanyContext::class)->id();
        $finance = Role::where('name', 'Chief Accountant')->where('company_id', $company)->first() ?? Role::where('name', 'company-admin')->where('company_id', $company)->first();
        if (! $finance) {
            return;
        }
        foreach (['credit-note' => 'Chief accountant', 'receipt-void' => 'Chief accountant', 'supplier-bank-account' => 'Chief accountant'] as $process => $name) {
            if (WorkflowTemplate::where('process', $process)->exists()) {
                continue;
            }
            $template = WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true]);
            $template->steps()->create(['step_order' => 1, 'name' => $name, 'approver_type' => 'role', 'role_id' => $finance->id, 'allow_send_back' => false]);
        }
    }

    public static function procurement(): void
    {
        $company = app(CompanyContext::class)->id();
        $finance = Role::where('name', 'Chief Accountant')->where('company_id', $company)->first() ?? Role::where('name', 'company-admin')->where('company_id', $company)->first();
        $director = Role::where('name', 'director-general')->where('company_id', $company)->first();
        if (! $finance) {
            return;
        }
        $chains = [
            'purchase-requisition' => [['Head of department', 'department_head', null, null], ['Finance (budget check)', 'role', $finance->id, null]],
            'quotation-award' => [[$director ? 'Director general' : 'Chief accountant', 'role', ($director ?? $finance)->id, null]],
            'purchase-order' => array_values(array_filter([['Chief accountant', 'role', $finance->id, null], $director ? ['Director general', 'role', $director->id, 10000000] : null])),
            'goods-receipt' => [['Head of department (inspection)', 'department_head', null, null]],
            'store-requisition' => [['Head of department', 'department_head', null, null]],
            'stock-receipt' => [['Chief accountant', 'role', $finance->id, null]],
            'stock-adjustment' => [['Chief accountant', 'role', $finance->id, null]],
            'tender-document' => [[$director ? 'Tender board' : 'Chief accountant', 'role', ($director ?? $finance)->id, null]],
            'tender-evaluation' => [[$director ? 'Tender board' : 'Chief accountant', 'role', ($director ?? $finance)->id, null]],
            'tender-award' => [[$director ? 'Tender board' : 'Chief accountant', 'role', ($director ?? $finance)->id, null]],
            'contract-vetting' => array_values(array_filter([['Chief accountant (finance and legal check)', 'role', $finance->id, null], $director ? ['Director general (signs)', 'role', $director->id, null] : null])),
            'contract-variation' => [[$director ? 'Director general' : 'Chief accountant', 'role', ($director ?? $finance)->id, null]],
            'contract-certificate' => [['Head of department (work done)', 'department_head', null, null], ['Chief accountant', 'role', $finance->id, null]],
        ];
        foreach ($chains as $process => $steps) {
            if (WorkflowTemplate::where('process', $process)->exists()) {
                continue;
            }
            $template = WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true]);
            foreach ($steps as $i => [$name, $type, $role, $min]) {
                $template->steps()->create(['step_order' => $i + 1, 'name' => $name, 'approver_type' => $type, 'role_id' => $role, 'min_amount' => $min, 'allow_send_back' => true]);
            }
        }
    }

    public static function assets(): void
    {
        $company = app(CompanyContext::class)->id();
        $finance = Role::where('name', 'Chief Accountant')->where('company_id', $company)->first() ?? Role::where('name', 'company-admin')->where('company_id', $company)->first();
        $director = Role::where('name', 'director-general')->where('company_id', $company)->first();
        if (! $finance) {
            return;
        }
        $chains = [
            'asset-register' => [['Chief accountant', 'role', $finance->id]],
            'asset-change' => [['Chief accountant', 'role', $finance->id]],
            'asset-transfer' => [['Head of department', 'department_head', null]],
            'audit-engagement' => [[$director ? 'Director general' : 'Chief accountant', 'role', ($director ?? $finance)->id]],
            'asset-disposal' => array_values(array_filter([['Chief accountant', 'role', $finance->id], $director ? ['Director general (disposal board)', 'role', $director->id] : null])),
        ];
        foreach ($chains as $process => $steps) {
            if (WorkflowTemplate::where('process', $process)->exists()) {
                continue;
            }
            $template = WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true]);
            foreach ($steps as $i => [$name, $type, $role]) {
                $template->steps()->create(['step_order' => $i + 1, 'name' => $name, 'approver_type' => $type, 'role_id' => $role, 'allow_send_back' => true]);
            }
        }
    }

    public static function manualJournal(): void
    {
        $company = app(CompanyContext::class)->id();
        $role = Role::where('name', 'Chief Accountant')->where('company_id', $company)->first() ?? Role::where('name', 'company-admin')->where('company_id', $company)->first();
        if (! $role) {
            return;
        }
        foreach ([\Modules\Accounting\Workflow\ManualJournalProcess::KEY, \Modules\Accounting\Workflow\OpeningBalanceProcess::KEY, \Modules\Accounting\Workflow\YearEndCloseProcess::KEY] as $process) {
            if (WorkflowTemplate::where('process', $process)->exists()) {
                continue;
            }
            $template = WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true]);
            $template->steps()->create(['step_order' => 1, 'name' => 'Chief accountant', 'approver_type' => 'role', 'role_id' => $role->id, 'allow_send_back' => true]);
        }
    }

    public static function departmentPlan(): void
    {
        $company = app(CompanyContext::class)->id();
        $role = Role::where('name', 'director-general')->where('company_id', $company)->first() ?? Role::where('name', 'company-admin')->where('company_id', $company)->first();
        if (! $role || WorkflowTemplate::where('process', \Modules\Planning\Workflow\DepartmentPlanProcess::KEY)->exists()) {
            return;
        }
        $template = WorkflowTemplate::create(['process' => \Modules\Planning\Workflow\DepartmentPlanProcess::KEY, 'company_branch_id' => null, 'is_active' => true]);
        $template->steps()->create(['step_order' => 1, 'name' => 'Director general', 'approver_type' => 'role', 'role_id' => $role->id, 'allow_send_back' => false]);
    }

    public static function appraisal(): void
    {
        if (WorkflowTemplate::where('process', \Modules\Appraisals\Workflow\AppraisalProcess::KEY)->exists()) {
            return;
        }
        $company = app(CompanyContext::class)->id();
        $hr = Role::where('name', 'hr-manager')->where('company_id', $company)->first() ?? Role::where('name', 'hr-officer')->where('company_id', $company)->first();
        $template = WorkflowTemplate::create(['process' => \Modules\Appraisals\Workflow\AppraisalProcess::KEY, 'company_branch_id' => null, 'is_active' => true]);
        $template->steps()->create(['step_order' => 1, 'name' => 'Supervisor', 'approver_type' => 'supervisor', 'allow_send_back' => true]);
        $template->steps()->create(['step_order' => 2, 'name' => 'Head of department', 'approver_type' => 'department_head', 'allow_send_back' => true]);
        if ($hr) {
            $template->steps()->create(['step_order' => 3, 'name' => 'HR moderation', 'approver_type' => 'role', 'role_id' => $hr->id, 'allow_send_back' => true]);
        }
    }

    public static function supervisorOnly(string $process): void
    {
        if (WorkflowTemplate::where('process', $process)->exists()) {
            return;
        }
        $template = WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true]);
        $template->steps()->create(['step_order' => 1, 'name' => 'Supervisor', 'approver_type' => 'supervisor', 'allow_send_back' => false]);
    }

    public static function departmentHead(string $process): void
    {
        if (WorkflowTemplate::where('process', $process)->exists()) {
            return;
        }
        $template = WorkflowTemplate::create(['process' => $process, 'company_branch_id' => null, 'is_active' => true]);
        $template->steps()->create(['step_order' => 1, 'name' => 'Head of department', 'approver_type' => 'department_head', 'allow_send_back' => true]);
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
