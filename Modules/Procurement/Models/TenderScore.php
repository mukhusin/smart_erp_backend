<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class TenderScore extends Model
{


    protected $fillable = ['tender_bid_id', 'tender_criterion_id', 'score', 'passed', 'comment', 'scored_by'];

    protected $casts = ['score' => 'float', 'passed' => 'boolean'];
}
