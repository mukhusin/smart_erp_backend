<?php

namespace Modules\Admin\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->id();
        $user = Rule::exists('users', 'id')->where('company_id', $companyId)->where('is_active', true);

        return [
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('company_id', $companyId)],
            /** The person handing over (optional, for the record). */
            'from_user_id' => ['nullable', 'integer', $user],
            'to_user_id' => ['required', 'integer', 'different:from_user_id', $user],
            'starts_at' => ['required', 'date'],
            /** Empty = until ended manually. */
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
