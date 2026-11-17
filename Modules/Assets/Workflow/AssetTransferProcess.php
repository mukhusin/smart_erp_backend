<?php

namespace Modules\Assets\Workflow;

use Illuminate\Database\Eloquent\Model;
use Modules\Assets\Actions\ManageAssetTransfers;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetTransfer;

class AssetTransferProcess extends AssetProcess
{
    public const KEY = 'asset-transfer';

    public function name(): string
    {
        return 'Asset transfer';
    }

    public function description(): string
    {
        return 'Moving an asset to another department, branch or person, or out to another organisation.';
    }

    protected function handler(): object
    {
        return app(ManageAssetTransfers::class);
    }

    protected function asset(Model $subject): Asset
    {
        return $subject->asset;
    }

    /** @param AssetTransfer $subject */
    public function summary(Model $subject): array
    {
        $a = $subject->asset;

        return ['title' => "Transfer {$a->number} {$a->name}", 'lines' => array_values(array_filter([
            'From '.($a->holderName() ?? 'store').' to '.$subject->destination(),
            $subject->permanent ? 'Permanent' : 'Temporary, back by '.$subject->return_date?->format('d M Y'),
            $subject->reason,
        ])), 'link' => "/assets/register/{$a->id}"];
    }
}
