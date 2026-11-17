<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class TenderComplaint extends Model
{

    public const OUTCOMES = ['pending' => 'Being reviewed', 'dismissed' => 'Dismissed', 'upheld' => 'Upheld'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    protected $fillable = ['tender_id', 'supplier_id', 'received_on', 'description', 'outcome', 'action', 'decision', 'decided_at', 'created_by'];

    protected $casts = ['received_on' => 'date', 'decided_at' => 'datetime'];
}
