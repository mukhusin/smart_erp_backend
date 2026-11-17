<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class ContractProgress extends Model
{

    public const KINDS = ['site_meeting' => 'Site meeting', 'progress_report' => 'Progress report', 'note' => 'Note'];

    protected $table = 'contract_progress';

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    protected $fillable = ['contract_id', 'date', 'kind', 'percent_complete', 'description', 'path', 'file_name', 'created_by'];

    protected $casts = ['date' => 'date'];
}
