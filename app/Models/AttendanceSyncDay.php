<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Sync state of one day of device punches: queued → fetched → processed (or failed). */
class AttendanceSyncDay extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'sync_date', 'status', 'punch_count', 'attempts', 'last_error', 'fetched_at', 'processed_at'];

    protected $casts = ['sync_date' => 'date', 'fetched_at' => 'datetime', 'processed_at' => 'datetime'];

    public static function for(string $date): self
    {
        return static::whereDate('sync_date', $date)->first() ?? static::create(['sync_date' => $date, 'status' => 'queued']);
    }
}
