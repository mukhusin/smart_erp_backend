<?php

namespace Modules\Hr\Http\Controllers\Api\Leave;

use App\Http\Controllers\Controller;
use App\Models\HrSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Hr\Support\EmployeeNumbers;
use Modules\Hr\Support\LeavePeriods;

/**
 * Company leave year and new joiners' annual leave cycle.
 *
 * @tags Leave: Settings
 */
class LeaveSettingsController extends Controller
{
    /**
     * Leave year settings.
     *
     * @response array{data: array{leave_year_start_month: int, annual_cycle_from_joining: bool, joiners_from: string|null, joiners_from_default: string|null, current_year: int}}
     */
    public function show(LeavePeriods $periods): JsonResponse
    {
        return response()->json(['data' => $this->row($periods)]);
    }

    /**
     * Change the leave year settings. Balances are worked out again with the new periods.
     *
     * @response array{data: array{leave_year_start_month: int, annual_cycle_from_joining: bool, joiners_from: string|null, joiners_from_default: string|null, current_year: int}}
     */
    public function update(Request $request, EmployeeNumbers $numbers): JsonResponse
    {
        $data = $request->validate([
            /** Month the company leave year starts (1 = January, as HRMS). */
            'leave_year_start_month' => ['required', 'integer', 'between:1,12'],
            /** New joiners' annual leave runs in 12-month cycles from their joining date. */
            'annual_cycle_from_joining' => ['required', 'boolean'],
            /** Employees who joined on or after this date are new joiners. Empty: the day annual leave was set up. */
            'joiners_from' => ['nullable', 'date'],
        ]);
        $setting = HrSetting::query()->first() ?? new HrSetting(['renumber_on_contract_change' => $numbers->renumberOnContractChange()]);
        $setting->fill($data)->save();

        app()->forgetScopedInstances();

        return response()->json(['data' => $this->row(app(LeavePeriods::class))]);
    }

    private function row(LeavePeriods $periods): array
    {
        $s = $periods->settings();
        $default = $periods->joinersFromDefault();

        return [
            'leave_year_start_month' => (int) ($s->leave_year_start_month ?: 1), 'annual_cycle_from_joining' => (bool) ($s->annual_cycle_from_joining ?? true),
            'joiners_from' => $s->joiners_from?->toDateString(), 'joiners_from_default' => $default?->toDateString(), 'current_year' => $periods->companyYear(),
        ];
    }
}
