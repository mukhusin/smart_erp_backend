<?php

namespace Modules\Hr\Http\Resources;

use App\Models\ProfessionalLicence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProfessionalLicence
 */
class LicenceTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider_name' => $this->provider_name,
            'short_name' => $this->short_name,
            'is_active' => $this->is_active,
        ];
    }
}
