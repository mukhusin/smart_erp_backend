<?php

namespace Modules\Hr\Events;

use App\Models\PayrollSheet;
use App\Models\User;

class PayrollApproved
{
    public function __construct(public readonly PayrollSheet $sheet, public readonly ?User $by = null) {}
}
