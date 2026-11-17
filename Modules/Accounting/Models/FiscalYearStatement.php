<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYearStatement extends Model
{
    protected $fillable = ['fiscal_year_id', 'report', 'title', 'subtitle', 'columns', 'rows'];

    protected $casts = ['columns' => 'array', 'rows' => 'array'];

    public function year()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }
}
