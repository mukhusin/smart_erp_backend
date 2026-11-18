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
     * @response array{data: array{hospital_patients: bool, retirement_age: int, task_max_step: int, profile_required: bool, profile_min_percent: int, profile_checks: list<string>}, profile_options: list<array{value: string, label: string}>}
     */
    public function show(): JsonResponse
    {
        return $this->out(HrSetting::current());
    }

    private function out(HrSetting $s): JsonResponse
    {
        return response()->json(['data' => ['hospital_patients' => (bool) $s->hospital_patients, 'retirement_age' => (int) ($s->retirement_age ?: 60), 'task_max_step' => (int) ($s->task_max_step ?? 30),
            'profile_required' => (bool) $s->profile_required, 'profile_min_percent' => (int) ($s->profile_min_percent ?: 75), 'profile_checks' => \Modules\Hr\Support\ProfileCompletion::checks()],
            'profile_options' => collect(\Modules\Hr\Support\ProfileCompletion::CHECKS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()]);
    }

    /**
     * Change the general HR settings.
     *
     * @response array{data: array{hospital_patients: bool, retirement_age: int, task_max_step: int, profile_required: bool, profile_min_percent: int, profile_checks: list<string>}, profile_options: list<array{value: string, label: string}>}
     */
    public function update(Request $request): JsonResponse
    {
        $settings = HrSetting::current();
        $settings->fill($request->validate([
            'hospital_patients' => ['required', 'boolean'], 'retirement_age' => ['sometimes', 'integer', 'between:40,80'], 'task_max_step' => ['sometimes', 'integer', 'between:0,100'],
            /** Staff must fill their profile to `profile_min_percent` before they can make any request. */
            'profile_required' => ['sometimes', 'boolean'],
            'profile_min_percent' => ['sometimes', 'integer', 'between:10,100'],
            /** What counts towards the profile: contact, next_of_kin, dependants, qualifications, documents, experiences, licences, personal. */
            'profile_checks' => ['sometimes', 'array', 'min:1'],
            'profile_checks.*' => ['string', 'in:'.implode(',', array_keys(\Modules\Hr\Support\ProfileCompletion::CHECKS))],
        ]))->save();

        return $this->out($settings);
    }
}
