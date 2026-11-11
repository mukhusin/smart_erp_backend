<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Hr\Support\EmployeeOptions as O;

class EndContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** All reasons except `ended` and `change_of_contract` mark the employee as terminated. */
            'reason' => ['required', O::keys(O::CONTRACT_END_REASONS)],
            'date' => ['required', 'date'],
            'comment' => ['required', 'string', 'max:1000'],
            /** The user's own password, to confirm. */
            'password' => ['required', 'string', 'current_password'],
        ];
    }

    public function messages(): array
    {
        return ['password.current_password' => 'The password is incorrect.', 'comment.required' => 'Explain why the contract ends.'];
    }
}
