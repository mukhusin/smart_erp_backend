<?php

namespace Modules\Workflow\Contracts;

use Illuminate\Database\Eloquent\Model;

interface HasAmount
{
    public function amount(Model $subject): ?float;
}
