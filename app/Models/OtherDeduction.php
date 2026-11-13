<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Workflow\Models\WorkflowInstance;

/** A fixed amount taken from salary every month from a start month, for a number of months or until stopped. */
class OtherDeduction extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'employee_id', 'name', 'description', 'amount', 'start_month', 'months', 'stopped_at', 'status', 'decision_comment', 'requested_by'];

    protected $casts = ['amount' => 'float', 'start_month' => 'date', 'months' => 'integer', 'stopped_at' => 'date'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function workflow()
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')->latestOfMany();
    }

    public function endMonth(): ?Carbon
    {
        $ends = array_filter([
            $this->months ? $this->start_month->copy()->addMonthsNoOverflow($this->months - 1)->startOfMonth() : null,
            $this->stopped_at?->copy()->startOfMonth(),
        ]);

        return $ends ? collect($ends)->min() : null;
    }

    public function appliesTo(int $year, int $month): bool
    {
        $m = Carbon::create($year, $month, 1);
        $end = $this->endMonth();

        return $this->status === 'approved' && $this->start_month->copy()->startOfMonth()->lte($m) && (! $end || $end->gte($m));
    }
}
