<?php

namespace Modules\Accounting\Listeners;

use Modules\Accounting\Models\PayrollPostingSetting;
use Modules\Accounting\Services\PayrollPosting;
use Modules\Hr\Events\PayrollApproved;
use Throwable;

class PostPayroll
{
    public function __construct(private readonly PayrollPosting $posting) {}

    public function handle(PayrollApproved $event): void
    {
        if (! PayrollPostingSetting::current()->enabled) {
            return;
        }
        try {
            $this->posting->post($event->sheet, $event->by);
        } catch (Throwable $e) {
            $message = $e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->join(' ') : $e->getMessage();
            $event->sheet->update(['posting_error' => mb_substr($message, 0, 500)]);
        }
    }
}
