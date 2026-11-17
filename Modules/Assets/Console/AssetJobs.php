<?php

namespace Modules\Assets\Console;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Modules\Assets\Models\Asset;
use Modules\Assets\Models\AssetTransfer;
use Modules\Assets\Services\Depreciation;
use Modules\Core\Notifications\Notice;

class AssetJobs extends Command
{
    protected $signature = 'assets:daily';

    protected $description = 'Post last month\'s depreciation on the 1st, remind about temporary transfers due back and assets still held by leavers.';

    public function handle(Depreciation $depreciation): int
    {
        foreach (Company::query()->get() as $company) {
            if (! $company->modules()->where('module_slug', 'assets')->where('is_enabled', true)->exists()) {
                continue;
            }
            app(CompanyContext::class)->run($company, function () use ($depreciation) {
                $officers = app(\Modules\Assets\Support\AssetAccess::class)->officers();
                if (today()->day === 1 && Asset::whereIn('status', Asset::ON_BOOKS)->whereNotNull('dep_from')->exists()) {
                    $period = today()->subMonthNoOverflow()->endOfMonth()->toDateString();
                    try {
                        if ($depreciation->nextPeriod()->toDateString() === $period) {
                            $depreciation->run($period, null);
                        }
                    } catch (ValidationException $e) {
                        foreach ($officers as $o) {
                            $o->notify(new Notice('Depreciation not posted', collect($e->errors())->flatten()->join(' '), '/assets/depreciation', 'exclamation-triangle'));
                        }
                    }
                }
                foreach (AssetTransfer::with('asset')->where('status', 'approved')->where('permanent', false)->whereDate('return_date', '<=', today()->addDays(3))
                    ->where(fn ($q) => $q->whereNull('reminded_on')->orWhereDate('reminded_on', '<', today()))->get() as $t) {
                    $users = $officers->merge(array_filter([$t->creator, $t->toEmployee?->user]))->unique('id');
                    foreach ($users as $u) {
                        $u->notify(new Notice('Temporary transfer due back', "{$t->asset->number} {$t->asset->name} is due back on ".$t->return_date->format('d M Y').'.', "/assets/register/{$t->asset_id}", 'arrow-return-left'));
                    }
                    $t->update(['reminded_on' => today()]);
                }
                $leavers = Employee::where('employment_status', 'terminated')->pluck('id');
                $held = Asset::whereIn('status', Asset::ON_BOOKS)->whereIn('employee_id', $leavers)->count();
                if ($held && today()->isMonday()) {
                    foreach ($officers as $o) {
                        $o->notify(new Notice('Assets held by leavers', "{$held} asset(s) are still held by employees who have left. Recover or reassign them.", '/assets/register?holder=leavers', 'person-dash'));
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
