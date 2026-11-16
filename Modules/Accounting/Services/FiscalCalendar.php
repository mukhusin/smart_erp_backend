<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\FiscalPeriod;
use Modules\Accounting\Models\FiscalYear;

class FiscalCalendar
{
    public function createYear(string $start, ?string $name = null): FiscalYear
    {
        $from = Carbon::parse($start)->startOfDay();
        $to = $from->copy()->addYear()->subDay();
        if (FiscalYear::whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->exists()) {
            throw ValidationException::withMessages(['start_date' => 'This year overlaps a fiscal year that already exists.']);
        }

        return DB::transaction(function () use ($from, $to, $name) {
            $year = FiscalYear::create([
                'name' => $name ?: ($from->month === 1 && $from->day === 1 ? (string) $from->year : $from->year.'/'.substr((string) ($from->year + 1), -2)),
                'start_date' => $from, 'end_date' => $to, 'status' => 'open',
            ]);
            for ($i = 0; $i < 12; $i++) {
                $s = $from->copy()->addMonthsNoOverflow($i);
                $e = $i === 11 ? $to->copy() : $from->copy()->addMonthsNoOverflow($i + 1)->subDay();
                $year->periods()->create(['number' => $i + 1, 'name' => $s->format('M Y'), 'start_date' => $s, 'end_date' => $e, 'status' => 'open']);
            }

            return $year->load('periods');
        });
    }

    public function periodFor($date): FiscalPeriod
    {
        $year = FiscalYear::containing($date);
        $period = $year?->periods()->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
        if (! $period) {
            throw ValidationException::withMessages(['date' => 'There is no fiscal year for '.Carbon::parse($date)->format('d M Y').'. Create it in Accounting → Fiscal years.']);
        }

        return $period;
    }

    public function openPeriodFor($date): FiscalPeriod
    {
        $period = $this->periodFor($date);
        if ($period->status !== 'open' || $period->year->status !== 'open') {
            throw ValidationException::withMessages(['date' => "The period {$period->name} is closed; nothing can be posted in it."]);
        }

        return $period;
    }

    public function close(FiscalPeriod $period, User $by): FiscalPeriod
    {
        if (FiscalPeriod::where('fiscal_year_id', $period->fiscal_year_id)->where('number', '<', $period->number)->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['period' => 'Close the earlier periods of the year first.']);
        }
        $period->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id]);

        return $period;
    }

    public function reopen(FiscalPeriod $period): FiscalPeriod
    {
        if ($period->year->status !== 'open') {
            throw ValidationException::withMessages(['period' => 'The fiscal year is closed.']);
        }
        if (FiscalPeriod::where('fiscal_year_id', $period->fiscal_year_id)->where('number', '>', $period->number)->where('status', 'closed')->exists()) {
            throw ValidationException::withMessages(['period' => 'Reopen the later periods first.']);
        }
        $period->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

        return $period;
    }
}
