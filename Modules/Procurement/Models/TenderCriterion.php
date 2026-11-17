<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class TenderCriterion extends Model
{

    public const KINDS = ['mandatory' => 'Pass or fail (documents, eligibility)', 'technical' => 'Technical, scored'];

    public $timestamps = false;

    protected $table = 'tender_criteria';

    protected $fillable = ['tender_id', 'name', 'kind', 'weight', 'sort'];

    protected $casts = ['weight' => 'float'];
}
