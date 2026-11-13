<?php

namespace App\Models;


use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Messaging extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'uuid', 'company_id', 'type', 'campaign', 'campaign_key', 'from_address', 'subject', 'message',
        'extra', 'response', 'scheduled_at', 'recipient_type', 'filters', 'skipped_count', 'sent_at',
        'department_id', 'section_id', 'created_by', 'status',
    ];

    protected $casts = ['scheduled_at' => 'datetime', 'sent_at' => 'datetime', 'filters' => 'array', 'skipped_count' => 'integer'];

    const TYPE_SMS   = 'SMS';
    const TYPE_EMAIL = 'Email';
    const TYPE_NOTICE = 'Notice';

    const STATUS_PENDING   = 'Pending';
    const STATUS_SENT      = 'Sent';
    const STATUS_DELIVERED = 'Delivered';
    const STATUS_FAILED    = 'Failed';
    const STATUS_CANCELLED = 'Cancelled';

    const RECIPIENT_INDIVIDUAL       = 'individual';
    const RECIPIENT_DEPARTMENT       = 'department';
    const RECIPIENT_SECTION          = 'section';
    const RECIPIENT_ACTIVE_CONTRACTS = 'active_contracts';
    const RECIPIENT_BRANCH           = 'branch';
    const RECIPIENT_ALL              = 'all';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->uuid ??= Str::uuid());
    }
    public function creator():    BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function section():    BelongsTo { return $this->belongsTo(Department::class, 'section_id'); }
    public function recipients(): HasMany   { return $this->hasMany(MessageRecipient::class); }
}
