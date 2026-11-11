<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CompanySmsSetting extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'is_enabled', 'driver', 'sender_id', 'contract_reminders'];

    protected $casts = ['is_enabled' => 'boolean', 'contract_reminders' => 'boolean'];

    public static function current(): self
    {
        return static::firstOrNew([], ['is_enabled' => false, 'driver' => 'log', 'contract_reminders' => true]);
    }
}
