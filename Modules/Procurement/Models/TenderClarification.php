<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class TenderClarification extends Model
{

    public const KINDS = ['clarification' => 'Clarification', 'amendment' => 'Amendment'];

    protected $fillable = ['tender_id', 'kind', 'question', 'answer', 'closing_at', 'created_by'];

    protected $casts = ['closing_at' => 'datetime'];
}
