<?php

namespace Modules\Assets\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Assets\Actions\ManageAssets;
use Modules\Assets\Models\Asset;

class AssetRegisterProcess extends AssetProcess
{
    public const KEY = 'asset-register';

    public function name(): string
    {
        return 'Asset registration';
    }

    public function description(): string
    {
        return 'Assets entered by hand or uploaded (donations, transfers in, opening balances) before they join the register.';
    }

    protected function handler(): object
    {
        return app(ManageAssets::class);
    }

    protected function asset(Model $subject): Asset
    {
        return $subject;
    }

    /** @param Asset $subject */
    public function summary(Model $subject): array
    {
        return ['title' => "Register {$subject->number} {$subject->name}", 'lines' => array_values(array_filter([
            Asset::ACQUISITION[$subject->acquisition_type].' on '.$subject->acquisition_date->format('d M Y'),
            'Cost '.number_format($subject->cost, 2).($subject->opening_depreciation > 0 ? ' · depreciation before go-live '.number_format($subject->opening_depreciation, 2) : ''),
            $subject->holderName() ? 'Held by '.$subject->holderName() : null,
        ])), 'link' => "/assets/register/{$subject->id}"];
    }
}
