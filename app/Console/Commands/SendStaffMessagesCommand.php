<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Hr\Actions\SendStaffMessages;

class SendStaffMessagesCommand extends Command
{
    protected $signature = 'hr:send-messages';

    protected $description = 'Send staff messages whose scheduled time has come';

    public function handle(SendStaffMessages $messages): int
    {
        $this->info('Messages queued: '.$messages->sendDue());

        return self::SUCCESS;
    }
}
