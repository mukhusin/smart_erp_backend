<?php

namespace Modules\Appraisals\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AppraisalSetting extends Model
{
    use Auditable, BelongsToCompany;

    public const PARTS = [
        ['key' => 'tasks', 'label' => 'Tasks and objectives', 'weight' => 60, 'enabled' => true],
        ['key' => 'competencies', 'label' => 'Competencies and behaviour', 'weight' => 30, 'enabled' => true],
        ['key' => 'attendance', 'label' => 'Attendance and punctuality', 'weight' => 10, 'enabled' => true],
    ];

    public const BANDS = [
        ['min' => 90, 'label' => 'Outstanding'],
        ['min' => 75, 'label' => 'Very good'],
        ['min' => 60, 'label' => 'Good'],
        ['min' => 50, 'label' => 'Fair'],
        ['min' => 0, 'label' => 'Poor'],
    ];

    protected $fillable = ['company_id', 'scale', 'mid_year', 'parts', 'bands'];

    protected $casts = ['mid_year' => 'boolean', 'parts' => 'array', 'bands' => 'array'];

    public static function current(): self
    {
        return static::firstOrNew([], ['scale' => 'percent', 'mid_year' => true, 'parts' => self::PARTS, 'bands' => self::BANDS]);
    }

    public function config(): array
    {
        return ['scale' => $this->scale ?: 'percent', 'mid_year' => (bool) $this->mid_year, 'parts' => $this->parts ?: self::PARTS, 'bands' => $this->bands ?: self::BANDS];
    }
}
