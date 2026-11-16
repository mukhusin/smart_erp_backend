<?php

namespace Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** none (attendance not used) or biotime (biometric). */
            'source' => ['required', 'in:none,biotime'],
            'host' => ['required_if:source,biotime', 'nullable', 'string', 'max:150'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['required_if:source,biotime', 'nullable', 'string', 'max:100'],
            'username' => ['required_if:source,biotime', 'nullable', 'string', 'max:100'],
            /** Empty: keep the saved password. */
            'password' => ['nullable', 'string', 'max:200'],
            /** Minutes late allowed before lateness counts. */
            'grace_minutes' => ['nullable', 'integer', 'between:0,120'],
            /** How many days back the sync checks for missing days. */
            'sync_days' => ['nullable', 'integer', 'between:1,120'],
        ];
    }
}
