<?php

namespace Modules\Platform\Actions;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Logo, login photo and theme colors of a company. Files go to the public disk under branding/{company}. */
class UpdateCompanyBranding
{
    public function handle(Company $company, array $data): Company
    {
        foreach (['logo' => 'remove_logo', 'login_background' => 'remove_login_background'] as $field => $remove) {
            $file = $data[$field] ?? null;
            if ($file instanceof UploadedFile || ! empty($data[$remove])) {
                if ($company->{$field}) {
                    Storage::disk('public')->delete($company->{$field});
                }
                $company->{$field} = $file instanceof UploadedFile
                    ? $file->store("branding/{$company->id}", 'public')
                    : null;
            }
        }

        foreach (['primary_color', 'secondary_color'] as $color) {
            if (array_key_exists($color, $data)) {
                $company->{$color} = $data[$color] ? strtolower($data[$color]) : null;
            }
        }

        $theme = $company->themeSettings();
        foreach (array_keys(Company::THEME_AREAS) as $area) {
            if (array_key_exists("theme_{$area}", $data)) {
                $theme[$area] = $data["theme_{$area}"];
            }
        }
        $company->theme = $theme;

        $company->save();

        return $company;
    }
}
