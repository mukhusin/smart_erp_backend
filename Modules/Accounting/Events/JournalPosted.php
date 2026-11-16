<?php

namespace Modules\Accounting\Events;

use App\Models\User;
use Modules\Accounting\Models\JournalEntry;

class JournalPosted
{
    public function __construct(public readonly JournalEntry $entry, public readonly ?User $by = null) {}
}
