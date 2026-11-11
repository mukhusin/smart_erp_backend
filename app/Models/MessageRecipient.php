<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageRecipient extends Model
{
    protected $fillable = ['uuid', 'messaging_id', 'employee_id', 'recipient_address', 'individual_message', 'status', 'response'];

    const STATUS_PENDING   = 'Pending';
    const STATUS_SENT      = 'Sent';
    const STATUS_DELIVERED = 'Delivered';
    const STATUS_FAILED    = 'Failed';

    public function messaging() { return $this->belongsTo(Messaging::class); }
    public function employee()  { return $this->belongsTo(Employee::class); }
}
