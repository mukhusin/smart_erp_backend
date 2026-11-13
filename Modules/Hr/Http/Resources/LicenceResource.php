<?php

namespace Modules\Hr\Http\Resources;

use App\Models\EmployeeLicence;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hr\Support\EmployeeFiles;
use Modules\Hr\Support\EmployeeOptions as O;

/**
 * @mixin EmployeeLicence
 */
class LicenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $days = (int) CarbonImmutable::today()->diffInDays($this->end_date, false);
        $type = $this->professionalLicence;

        return [
            'id' => $this->id,
            /** approved, pending (added by the employee, waiting for HR) or rejected */
            'approval_status' => $this->getRawOriginal('status') ?? 'approved',
            'decision_comment' => $this->decision_comment,
            /** @var array{id: int, name: string, provider_name: string|null, short_name: string|null}|null */
            'licence' => $type ? ['id' => $type->id, 'name' => $type->name, 'provider_name' => $type->provider_name, 'short_name' => $type->short_name] : null,
            'licence_no' => $this->licence_no,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'days_left' => $days,
            /** `valid`, `expiring` (within 90 days) or `expired`. */
            'status' => $days < 0 ? 'expired' : ($days <= O::RENEWAL_WINDOW_DAYS ? 'expiring' : 'valid'),
            /** @var array{name: string, url: string}|null */
            'file' => EmployeeFiles::link($this->file_path, $this->file_name,
                route('hr.employees.licences.file', [$this->employee_id, $this->id], false)),
        ];
    }
}
