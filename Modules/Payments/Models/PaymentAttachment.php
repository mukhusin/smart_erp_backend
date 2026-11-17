<?php

namespace Modules\Payments\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PaymentAttachment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'payment_request_id', 'imprest_retirement_id', 'path', 'name', 'size', 'uploaded_by'];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
