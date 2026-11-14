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
     * @response array{data: array{hospital_patients: bool}}
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => ['hospital_patients' => (bool) HrSetting::current()->hospital_patients]]);
    }

    /**
     * Change the general HR settings.
     *
     * @response array{data: array{hospital_patients: bool}}
     */
    public function update(Request $request): JsonResponse
    {
        $settings = HrSetting::current();
        $settings->fill($request->validate(['hospital_patients' => ['required', 'boolean']]))->save();

        return response()->json(['data' => ['hospital_patients' => (bool) $settings->hospital_patients]]);
    }
}
