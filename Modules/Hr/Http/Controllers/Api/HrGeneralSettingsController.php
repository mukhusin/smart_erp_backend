<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HrSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * General HR settings of the company.
 *
 * @tags HR: Settings
 */
class HrGeneralSettingsController extends Controller
{
    /**
     * General HR settings. `hospital_patients`: the company runs a hospital whose patient system knows its staff (staff patient
     * IDs are kept and excuse of duty can come from the hospital); when off, patient IDs are not shown.
     *
     * @response array{data: array{hospital_patients: bool, retirement_age: int, task_max_step: int}}
     */
    public function show(): JsonResponse
    {
        $s = HrSetting::current();

        return response()->json(['data' => ['hospital_patients' => (bool) $s->hospital_patients, 'retirement_age' => (int) ($s->retirement_age ?: 60), 'task_max_step' => (int) ($s->task_max_step ?? 30)]]);
    }

    /**
     * Change the general HR settings.
     *
     * @response array{data: array{hospital_patients: bool, retirement_age: int, task_max_step: int}}
     */
    public function update(Request $request): JsonResponse
    {
        $settings = HrSetting::current();
        $settings->fill($request->validate(['hospital_patients' => ['required', 'boolean'], 'retirement_age' => ['sometimes', 'integer', 'between:40,80'], 'task_max_step' => ['sometimes', 'integer', 'between:0,100']]))->save();

        return response()->json(['data' => ['hospital_patients' => (bool) $settings->hospital_patients, 'retirement_age' => (int) ($settings->retirement_age ?: 60), 'task_max_step' => (int) ($settings->task_max_step ?? 30)]]);
    }
}
