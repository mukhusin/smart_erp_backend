<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankFeedTransaction extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'cash_bank_account_id', 'external_id', 'date', 'description', 'reference', 'amount', 'bank_statement_line_id'];

    protected $casts = ['date' => 'date', 'amount' => 'float'];
}
