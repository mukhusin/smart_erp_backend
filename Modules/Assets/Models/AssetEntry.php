<?php

namespace Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\JournalEntry;

class AssetEntry extends Model
{
    public const KINDS = ['acquisition' => 'Acquired', 'opening' => 'Depreciation before go-live', 'improvement' => 'Improvement', 'revaluation' => 'Revaluation',
        'impairment' => 'Impairment', 'depreciation' => 'Depreciation', 'disposal' => 'Disposal', 'transfer_out' => 'Transferred out'];

    protected $fillable = ['asset_id', 'date', 'kind', 'cost', 'depreciation', 'description', 'journal_entry_id', 'source_type', 'source_id'];

    protected $casts = ['date' => 'date', 'cost' => 'float', 'depreciation' => 'float'];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
