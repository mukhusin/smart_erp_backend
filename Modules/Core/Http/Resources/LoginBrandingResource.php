<?php

namespace Modules\Core\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Models\LoginSlide;

/**
 * Public data for the login page. Contains nothing private.
 *
 * @property-read ?Company $resource
 */
class LoginBrandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ?Company $company */
        $company = $this->resource;

        return [
            /** Null when no (or an unknown or suspended) organization was asked for: show the neutral ERP branding. */
            'organization' => $company ? [
                'slug' => $company->slug,
                'name' => $company->name,
                'short_name' => $company->short_name,
                'logo_url' => $company->logo_url,
                'background_url' => $company->login_background_url,
                /** #rrggbb or null (ERP default). */
                'primary_color' => $company->primary_color,
                'secondary_color' => $company->secondary_color,
            ] : null,
            /** @var list<array{id: int, title: string, content: string|null, image_url: string|null}> */
            'slides' => LoginSlide::query()->visibleFor($company?->id)->limit(10)->get()
                ->map(fn (LoginSlide $s) => [
                    'id' => $s->id,
                    'title' => $s->title,
                    'content' => $s->content,
                    'image_url' => $s->image_url,
                ])->values()->all(),
        ];
    }
}
