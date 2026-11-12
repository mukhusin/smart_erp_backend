<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContractRenewal;
use App\Models\NatureOfContract;
use App\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Hr\Actions\ManageRenewals;
use Modules\Hr\Http\Resources\ContractResource;
use Modules\Hr\Http\Resources\RenewalResource;
use Modules\Hr\Support\EmployeeScope;

/**
 * A renewal as approvers see it: the letter, current contract, proposed terms and their changes. HR and the
 * approver of the current step can change the terms.
 *
 * @tags HR: Contract renewals
 */
class RenewalReviewController extends Controller
{
    public function __construct(private readonly ManageRenewals $renewals) {}

    /**
     * Renewal details for approvers.
     *
     * @response array{data: array{renewal: RenewalResource, employee: array{id: int, name: string, employee_no: string|null, department: string|null, position: string|null}, current_contract: ContractResource|null, terms: array{nature_of_contract_id: int|null, type: string|null, period: int|null, basic_salary: float|null}, can_change_terms: bool, contract_types: list<array{id: int, name: string}>, changes: list<array{id: int, by: string|null, step: string|null, before: string, after: string, comment: string|null, at: string}>}}
     */
    public function show(Request $request, ContractRenewal $renewal): JsonResponse
    {
        $this->authorizeView($request, $renewal);
        $renewal->load(['employee.department', 'employee.jobPosition', 'natureOfContract', 'files', 'workflow.steps', 'contract.natureOfContract', 'changes.user.employee']);
        $employee = $renewal->employee;

        return response()->json(['data' => [
            'renewal' => (new RenewalResource($renewal))->resolve($request),
            'employee' => ['id' => $employee->id, 'name' => $employee->full_name, 'employee_no' => $employee->employee_no, 'department' => $employee->department?->name, 'position' => $employee->jobPosition?->title],
            'current_contract' => $renewal->contract ? (new ContractResource($renewal->contract))->resolve($request) : null,
            'terms' => [
                'nature_of_contract_id' => $renewal->nature_of_contract_id, 'type' => $renewal->natureOfContract?->contract_name,
                'period' => $renewal->period, 'basic_salary' => $renewal->basic_salary !== null ? (float) $renewal->basic_salary : null,
            ],
            'can_change_terms' => $this->renewals->canChangeTerms($renewal, $request->user()),
            'contract_types' => NatureOfContract::where('is_active', true)->orderBy('contract_name')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->contract_name]),
            'changes' => $renewal->changes->map(fn ($c) => [
                'id' => $c->id, 'by' => $c->user ? ($c->user->employee?->full_name ?? $c->user->display_name) : null, 'step' => $c->step_name,
                'before' => ManageRenewals::termsLabel($c->before), 'after' => ManageRenewals::termsLabel($c->after), 'comment' => $c->comment, 'at' => $c->created_at->toIso8601String(),
            ])->values(),
        ]]);
    }

    /**
     * Change the proposed terms (HR or the approver of the current step, while waiting for approval).
     *
     * @response array{data: RenewalResource}
     */
    public function terms(Request $request, ContractRenewal $renewal): JsonResponse
    {
        $data = $request->validate([
            'nature_of_contract_id' => ['required', 'integer', Rule::exists('nature_of_contracts', 'id')->where('company_id', app(CompanyContext::class)->id())->whereNull('deleted_at')],
            /** Months. */
            'period' => ['required', 'integer', 'min:1', 'max:120'],
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            /** Why the terms changed. */
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $renewal = $this->renewals->changeTerms($renewal, $data, $request->user());

        return response()->json(['data' => (new RenewalResource($renewal->load(['natureOfContract', 'files', 'workflow.steps', 'contract'])))->resolve($request)]);
    }

    private function authorizeView(Request $request, ContractRenewal $renewal): void
    {
        $user = $request->user();
        $allowed = $this->renewals->canChangeTerms($renewal, $user)
            || $renewal->employee?->user_id === $user->id
            || ($renewal->employee && app(EmployeeScope::class)->canBrowse($user) && app(EmployeeScope::class)->allows($user, $renewal->employee))
            || ($renewal->workflow && $renewal->workflow->steps()->where('acted_by', $user->id)->exists());
        abort_unless($allowed, 404);
    }
}
