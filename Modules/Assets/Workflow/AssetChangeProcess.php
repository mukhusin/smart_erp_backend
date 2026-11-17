<?php

namespace Modules\Assets\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Assets\Actions\ManageAssetChanges;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetChange;

class AssetChangeProcess extends AssetProcess
{
    public const KEY = 'asset-change';

    public function name(): string
    {
        return 'Asset improvement, revaluation, impairment or life review';
    }

    public function description(): string
    {
        return 'Changes to the value or useful life of an asset on the register.';
    }

    protected function handler(): object
    {
        return app(ManageAssetChanges::class);
    }

    protected function asset(Model $subject): Asset
    {
        return $subject->asset;
    }

    /** @param AssetChange $subject */
    public function summary(Model $subject): array
    {
        $a = $subject->asset;

        return ['title' => AssetChange::KINDS[$subject->kind].": {$a->number} {$a->name}", 'lines' => array_values(array_filter([
            'Book value '.number_format((float) $subject->book_value, 2),
            $subject->kind === 'life' ? "Change of {$subject->months} months" : ($subject->kind === 'improvement' ? 'Adds '.number_format((float) $subject->amount, 2) : 'New value '.number_format((float) $subject->amount, 2)),
            $subject->valuer ? "Valuer: {$subject->valuer}" : null, $subject->reason,
        ])), 'link' => "/assets/register/{$a->id}"];
    }
}
