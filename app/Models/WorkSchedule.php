<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WorkSchedule extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'days', 'break_minutes', 'is_default'];

    protected $casts = ['days' => 'array', 'break_minutes' => 'integer', 'is_default' => 'boolean'];

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    /** @return array{day: int, start: string, end: string}|null */
    public function dayOf(int $isoWeekday): ?array
    {
        return collect($this->days ?? [])->firstWhere('day', $isoWeekday);
    }
}
