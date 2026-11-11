<?php

namespace Modules\Hr\Http\Requests;

use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $unique = fn (string $column) => Rule::unique('banks', $column)->where('company_id', app(CompanyContext::class)->id())->ignore($this->route('bank')?->id);

        return [
            'name' => ['required', 'string', 'max:150', $unique('name')],
            /** Short code, e.g. CRDB. */
            'code' => ['nullable', 'string', 'max:20', $unique('code')],
            'swift_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]{8}([A-Za-z0-9]{3})?$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'This bank is already on the list.', 'code.unique' => 'Another bank has this code.', 'swift_code.regex' => 'A SWIFT code has 8 or 11 letters and digits.'];
    }
}
