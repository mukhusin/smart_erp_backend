<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventorySettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'opening_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'donation_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'expiry_warning_days' => ['required', 'integer', 'min:1', 'max:730'],
            'slow_moving_days' => ['required', 'integer', 'min:30', 'max:1095'],
        ];
    }
}
