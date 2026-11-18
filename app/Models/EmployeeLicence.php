<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EmployeeLicence extends Model
{
    use Auditable;
    protected $fillable = ['employee_id', 'professional_licence_id', 'licence_no', 'classes', 'start_date', 'end_date', 'file_path', 'file_name'];

    protected $casts = ['classes' => 'array', 'start_date' => 'date', 'end_date' => 'date'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function setClassesAttribute($value): void
    {
        $classes = collect(is_string($value) ? json_decode($value, true) : $value)->map(fn ($c) => strtoupper(trim((string) $c)))->filter()->unique()->values()->all();
        $this->attributes['classes'] = $classes ? json_encode($classes) : null;
    }

    public function valid(): bool
    {
        return $this->end_date->gte(today()) && in_array($this->getRawOriginal('status') ?? 'approved', ['approved'], true);
    }

    public function professionalLicence()
    {
        return $this->belongsTo(ProfessionalLicence::class)->withTrashed();
    }
}
