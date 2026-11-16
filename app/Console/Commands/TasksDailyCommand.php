<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Modules\Core\Notifications\Notice;
use Modules\Tasks\Actions\ManageTasks;
use Modules\Tasks\Models\Task;

class TasksDailyCommand extends Command
{
    protected $signature = 'tasks:daily';

    protected $description = 'Create the next copy of recurring tasks and remind staff of tasks due tomorrow or overdue';

    public function handle(CompanyContext $context, ManageTasks $tasks): int
    {
        $repeated = $reminded = 0;
        foreach (Company::query()->pluck('id') as $companyId) {
            $context->run($companyId, function () use ($tasks, &$repeated, &$reminded) {
                Task::whereNotNull('recurrence')->where('next_created', false)->where('status', '!=', 'cancelled')->whereDate('due_date', '<=', today())
                    ->get()->each(function (Task $t) use ($tasks, &$repeated) {
                        $tasks->repeat($t) && $repeated++;
                    });

                Task::with(['employee.user', 'creator'])->whereIn('status', ['todo', 'in_progress', 'returned'])->whereDate('due_date', today()->addDay())->get()
                    ->each(function (Task $t) use (&$reminded) {
                        $t->employee?->user?->notify(new Notice('Task due tomorrow', "{$t->title} is due tomorrow ({$t->progress}% done).", "/tasks/{$t->id}", 'alarm'));
                        $reminded++;
                    });

                Task::with(['employee.user', 'creator'])->whereIn('status', ['todo', 'in_progress', 'returned'])->whereDate('due_date', today()->subDay())->get()
                    ->each(function (Task $t) use (&$reminded) {
                        $t->employee?->user?->notify(new Notice('Task overdue', "{$t->title} was due yesterday ({$t->progress}% done).", "/tasks/{$t->id}", 'exclamation-triangle'));
                        if ($t->assignedByOther()) {
                            $t->creator?->notify(new Notice('Task overdue', "{$t->employee?->full_name}: {$t->title} was due yesterday ({$t->progress}% done).", "/tasks/{$t->id}", 'exclamation-triangle'));
                        }
                        $reminded++;
                    });
            });
        }
        $this->info("Recurring tasks created: {$repeated}, reminders: {$reminded}");

        return self::SUCCESS;
    }
}
