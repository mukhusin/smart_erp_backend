<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeNumberFormatSegment extends Model
{
    public const TYPES = [
        'text' => 'Fixed text',
        'sequence' => 'Running number',
        'year' => 'Year',
        'department' => 'Department code',
    ];

    protected $fillable = ['format_id', 'position', 'type', 'value', 'length', 'start_at'];

    protected $casts = ['position' => 'integer', 'length' => 'integer', 'start_at' => 'integer'];

    public function format()
    {
        return $this->belongsTo(EmployeeNumberFormat::class, 'format_id');
    }
}
