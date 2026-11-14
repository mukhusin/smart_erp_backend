<?php

namespace Modules\Platform\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** multipart/form-data: files plus colors. */
class UpdateCompanyBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Multipart sends booleans as text ("true"/"false"/"1"/"0"). */
    protected function prepareForValidation(): void
    {
        foreach (['remove_logo', 'remove_login_background'] as $flag) {
            if ($this->has($flag)) {
                $this->merge([$flag => $this->boolean($flag)]);
            }
        }
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
            /** Sidebar style: plain, tinted or solid (top bar color). */
            'theme_sidebar' => ['sometimes', Rule::in(Company::THEME_AREAS['sidebar'])],
            /** Top bar style: plain, tinted or solid (top bar color). */
            'theme_navbar' => ['sometimes', Rule::in(Company::THEME_AREAS['navbar'])],
            /** Table headings: plain, tinted or solid (top bar color). */
            'theme_tables' => ['sometimes', Rule::in(Company::THEME_AREAS['tables'])],
            /** Cards: plain, accent (main color stripe on top) or tinted (header). */
            'theme_cards' => ['sometimes', Rule::in(Company::THEME_AREAS['cards'])],
            /** Home page module tiles: brand (company colors) or module (each module's own color). */
            'theme_tiles' => ['sometimes', Rule::in(Company::THEME_AREAS['tiles'])],
        ];
    }
}
