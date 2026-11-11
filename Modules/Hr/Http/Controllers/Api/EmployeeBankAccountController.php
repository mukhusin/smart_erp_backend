<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Services\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Hr\Http\Resources\EmployeeResource;

/**
 * The account an employee is paid into (HRMS: one account per employee).
 *
 * @tags HR: Employees
 */
class EmployeeBankAccountController extends Controller
{
    /**
     * Set the employee's bank account. Replaces the current one (the old account is kept, inactive).
     */
    public function update(Request $request, Employee $employee): EmployeeResource
    {
        $data = $request->validate([
            'bank_id' => ['required', 'integer', Rule::exists('banks', 'id')->where('company_id', app(CompanyContext::class)->id())->where('is_active', true)],
            'branch' => ['nullable', 'string', 'max:150'],
            'account_number' => ['required', 'string', 'max:50', 'regex:/^[0-9A-Za-z\- ]+$/'],
            /** Defaults to the employee's name. */
            'account_name' => ['nullable', 'string', 'max:150'],
        ], ['bank_id.required' => 'Choose the bank.', 'account_number.regex' => 'Use digits and letters only.']);

        $number = preg_replace('/\s+/', '', $data['account_number']);
        $taken = EmployeeBankAccount::where('is_active', true)->where('bank_id', $data['bank_id'])->where('account_number', $number)
            ->where('employee_id', '!=', $employee->id)->whereIn('employee_id', Employee::withTrashed()->select('id'))->with('employee')->first();
        if ($taken) {
            throw ValidationException::withMessages(['account_number' => "This account belongs to {$taken->employee?->full_name} ({$taken->employee?->employee_no})."]);
        }

        DB::transaction(function () use ($employee, $data, $number) {
            $current = $employee->bankAccount()->first();
            $fields = ['bank_id' => $data['bank_id'], 'branch' => $data['branch'] ?? null, 'account_number' => $number, 'account_name' => ($data['account_name'] ?? null) ?: $employee->full_name];
            if ($current && $current->bank_id === (int) $data['bank_id'] && $current->account_number === $number) {
                $current->update($fields);

                return;
            }
            $current?->update(['is_active' => false]);
            $employee->bankAccounts()->create($fields + ['is_active' => true]);
        });

        return new EmployeeResource(app(EmployeeController::class)->profile($employee->refresh()));
    }

    /**
     * Remove the employee's bank account.
     */
    public function destroy(Employee $employee): Response
    {
        $employee->bankAccounts()->where('is_active', true)->update(['is_active' => false]);

        return response()->noContent();
    }
}
