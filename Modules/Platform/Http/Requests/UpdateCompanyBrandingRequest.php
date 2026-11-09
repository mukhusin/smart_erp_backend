<?php

namespace Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** multipart/form-data: files plus colors. */
class UpdateCompanyBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $hex = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];

        return [
            /** PNG, JPG, WEBP or SVG, max 1 MB. Shown in the top bar, login page and documents. */
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:1024'],
            /** Login page photo. JPG, PNG or WEBP, max 4 MB, landscape works best (1920×1080). */
            'login_background' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_login_background' => ['sometimes', 'boolean'],
            /** Main color (buttons, links, active menu), e.g. "#0d6efd". Empty = default. */
            'primary_color' => $hex,
            /** Top bar color. Empty = same as primary. */
            'secondary_color' => $hex,
        ];
    }
}
