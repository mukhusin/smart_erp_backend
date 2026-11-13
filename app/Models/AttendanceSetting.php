<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Whether a company uses biometric attendance (BioTime server) or not at all; the connection is stored encrypted. */
class AttendanceSetting extends Model
{
    use BelongsToCompany;

    public const SOURCES = ['none' => 'Not used', 'biotime' => 'Biometric (ZKTeco BioTime server)'];

    protected $fillable = ['company_id', 'source', 'connection', 'grace_minutes', 'sync_days', 'last_synced_at', 'last_error', 'updated_by'];

    protected $casts = ['connection' => 'encrypted:array', 'grace_minutes' => 'integer', 'sync_days' => 'integer', 'last_synced_at' => 'datetime'];

    protected $hidden = ['connection'];

    public function enabled(): bool
    {
        return $this->source === 'biotime';
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static(['source' => 'none', 'grace_minutes' => 0, 'sync_days' => 60]);
    }
}
