<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PublicHoliday extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'title', 'day_number', 'month_number', 'holiday_date', 'is_active', 'updated_by'];

    protected $casts = ['is_active' => 'boolean', 'holiday_date' => 'date'];

    public function updatedByEmp()
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }

    public function recurring(): bool
    {
        return $this->holiday_date === null;
    }

    public function fallsOn(CarbonInterface $date): bool
    {
        return $this->recurring()
            ? (int) $this->day_number === $date->day && (int) $this->month_number === $date->month
            : $this->holiday_date->isSameDay($date);
    }

    /** The date it falls on in the given year (null for a one-off holiday of another year). */
    public function dateIn(int $year): ?CarbonInterface
    {
        if (! $this->recurring()) {
            return $this->holiday_date->year === $year ? $this->holiday_date : null;
        }

        return checkdate((int) $this->month_number, (int) $this->day_number, $year)
            ? Carbon::create($year, (int) $this->month_number, (int) $this->day_number)->startOfDay() : null;
    }
}
