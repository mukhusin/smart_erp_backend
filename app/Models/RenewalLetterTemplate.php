<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RenewalLetterTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'language', 'subject', 'salutation', 'closing', 'number_label', 'department_label', 'section_label', 'position_label',
        'show_section', 'show_position', 'updated_by',
    ];

    protected $casts = ['show_section' => 'boolean', 'show_position' => 'boolean'];
}
