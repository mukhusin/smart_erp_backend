<?php

namespace Modules\Fleet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Fleet\Models\FleetUnit;

class UnitRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** vehicle or generator (only when registering). */
            'kind' => ['nullable', 'in:vehicle,generator'],
            /** Odometer (km) or hours at registration. */
            'reading' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'reading_date' => ['nullable', 'date', 'before_or_equal:today'],
            'vehicle_type_id' => ['nullable', 'integer', 'exists:fleet_vehicle_types,id'],
            'registration' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'chassis_number' => ['nullable', 'string', 'max:60'],
            'engine_number' => ['nullable', 'string', 'max:60'],
            'colour' => ['nullable', 'string', 'max:30'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:200'],
            'fuel_type' => ['nullable', 'in:'.implode(',', array_keys(FleetUnit::FUELS))],
            'tank_capacity' => ['nullable', 'numeric', 'min:0'],
            /** owned, donated, borrowed, hired or leased. */
            'ownership' => ['required', 'in:'.implode(',', array_keys(FleetUnit::OWNERSHIP))],
            'owner_name' => ['nullable', 'string', 'max:150'],
            /** The fixed asset of an owned or donated unit. */
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'company_branch_id' => ['nullable', 'integer', 'exists:company_branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'location' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
