<?php

namespace Modules\Hr\Http\Resources;

use App\Models\NatureOfContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hr\Support\EmployeeNumbers;
use Modules\Hr\Support\EmployeeOptions as O;

/**
 * @mixin NatureOfContract
 */
class ContractTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = O::empTypeOf($this->employment_type);

        return [
            'id' => $this->id,
            'contract_name' => $this->contract_name,
            'code' => $this->code,
            'employment_type' => $type ?? $this->employment_type,
            'employment_type_label' => O::EMPLOYMENT_TYPES[$type] ?? $this->employment_type,
            /** @var array{id: int, name: string, next_number: string}|null */
            'number_format' => $this->numberFormat ? [
                'id' => $this->numberFormat->id,
                'name' => $this->numberFormat->name,
                'next_number' => app(EmployeeNumbers::class)->nextIn($this->numberFormat->loadMissing('segments')),
            ] : null,
            'has_annual_leave' => $this->has_annual_leave,
            'has_payroll' => $this->has_payroll,
            'has_overtime' => $this->has_overtime,
            'has_allowance' => $this->has_allowance,
            'has_emergency_fund' => $this->has_emergency_fund,
            'is_active' => $this->is_active,
            'contract_description' => $this->contract_description,
        ];
    }
}
