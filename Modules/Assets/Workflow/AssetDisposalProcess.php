<?php

namespace Modules\Assets\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Assets\Actions\ManageAssetDisposals;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetDisposal;

class AssetDisposalProcess extends AssetProcess
{
    public const KEY = 'asset-disposal';

    public function name(): string
    {
        return 'Asset disposal';
    }

    public function description(): string
    {
        return 'Sale, auction, donation, write-off or retirement of an asset, approved by the disposal board.';
    }

    protected function handler(): object
    {
        return app(ManageAssetDisposals::class);
    }

    protected function asset(Model $subject): Asset
    {
        return $subject->asset;
    }

    /** @param AssetDisposal $subject */
    public function summary(Model $subject): array
    {
        $a = $subject->asset;

        return ['title' => "Dispose of {$a->number} {$a->name}", 'lines' => array_values(array_filter([
            AssetDisposal::METHODS[$subject->method].' on '.$subject->date->format('d M Y'),
            'Book value '.number_format((float) $subject->book_value, 2).($subject->proceeds > 0 ? ' · proceeds '.number_format($subject->proceeds, 2) : ''),
            $subject->minutes_name ? "Minutes: {$subject->minutes_name}" : null, $subject->reason,
        ])), 'link' => "/assets/register/{$a->id}"];
    }
}
