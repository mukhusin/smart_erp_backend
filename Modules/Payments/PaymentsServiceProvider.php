<?php

namespace Modules\Payments;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Modules\Hr\Events\PayrollApproved;
use Modules\Payments\Actions\ManageRecoveries;
use Modules\Payments\Console\ImprestReminders;

class PaymentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(PayrollApproved::class, function (PayrollApproved $event) {
            try {
                app(ManageRecoveries::class)->payrollApproved($event->sheet, $event->by);
            } catch (\Throwable $e) {
                Log::error('Imprest recovery after payroll failed: '.$e->getMessage(), ['sheet' => $event->sheet->id]);
            }
        });
        if ($this->app->runningInConsole()) {
            $this->commands([ImprestReminders::class]);
        }
    }
}
