<?php

namespace Modules\Hr\Jobs;

use App\Models\Company;
use App\Models\Messaging;
use App\Services\CompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Hr\Actions\SendStaffMessages;

class SendStaffMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(public readonly int $messagingId) {}

    public function handle(CompanyContext $context, SendStaffMessages $messages): void
    {
        $messaging = Messaging::withoutCompanyScope()->find($this->messagingId);
        if (! $messaging) {
            return;
        }
        $context->run(Company::findOrFail($messaging->company_id), fn () => $messages->deliver($messaging));
    }
}
