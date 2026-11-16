<?php

namespace Modules\Accounting\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\AccountingPolicyChange;
use Modules\Accounting\Models\AccountingSetting;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Support\FrameworkCatalog;

/** The company's accounting framework and policies, as they apply on a given date. */
class AccountingPolicies
{
    /** @return array{framework: string, framework_name: string|null, policies: array<string, string>} */
    public function at(?string $date = null): array
    {
        $date ??= today()->toDateString();
        $change = AccountingPolicyChange::whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->orderByDesc('id')->first();
        if ($change) {
            return ['framework' => $change->framework, 'framework_name' => $change->framework_name, 'policies' => FrameworkCatalog::resolve($change->framework, $change->policies)];
        }
        $s = AccountingSetting::current();
        $framework = $s->framework ?: 'ifrs_sme';

        return ['framework' => $framework, 'framework_name' => $s->framework_name, 'policies' => FrameworkCatalog::resolve($framework, $s->policies)];
    }

    public function policy(string $key, ?string $date = null): string
    {
        return $this->at($date)['policies'][$key];
    }

    /** @return array<string, string> */
    public function wording(?string $date = null): array
    {
        return FrameworkCatalog::WORDING[$this->policy('terminology', $date)] ?? FrameworkCatalog::WORDING['nonprofit'];
    }

    public function hasPostings(): bool
    {
        return JournalEntry::whereIn('status', ['posted', 'reversed'])->exists();
    }

    /** Dates a change may take effect from: any date before the first posting, then only the start of an open fiscal year. */
    public function allowedStarts(): array
    {
        return FiscalYear::where('status', 'open')->orderBy('start_date')->pluck('start_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
    }

    public function change(array $data, User $by): AccountingPolicyChange
    {
        $framework = $data['framework'];
        $allowed = FrameworkCatalog::allowed($framework);
        foreach ($data['policies'] ?? [] as $key => $value) {
            if (! isset($allowed[$key]) || ! in_array($value, $allowed[$key], true)) {
                $label = FrameworkCatalog::POLICIES[$key]['label'] ?? $key;
                throw ValidationException::withMessages(["policies.{$key}" => "{$label}: this choice is not allowed under ".FrameworkCatalog::PROFILES[$framework]['label'].'.']);
            }
        }
        if ($framework === 'local' && empty($data['framework_name'])) {
            throw ValidationException::withMessages(['framework_name' => 'Name the local or regulatory framework, e.g. “NBAA – NGO”.']);
        }
        $effective = $data['effective_from'] ?? today()->toDateString();
        if ($this->hasPostings() && ! in_array(Carbon::parse($effective)->toDateString(), $this->allowedStarts(), true)) {
            throw ValidationException::withMessages(['effective_from' => 'Transactions are already posted, so a change takes effect from the start of an open fiscal year.']);
        }
        $resolved = FrameworkCatalog::resolve($framework, $data['policies'] ?? []);

        return DB::transaction(function () use ($framework, $data, $effective, $resolved, $by) {
            $change = AccountingPolicyChange::create(['framework' => $framework, 'framework_name' => $framework === 'local' ? $data['framework_name'] : null,
                'policies' => $resolved, 'effective_from' => $effective, 'reason' => $data['reason'] ?? null, 'changed_by' => $by->id]);
            if (Carbon::parse($effective)->lte(today())) {
                AccountingSetting::current()->fill(['framework' => $framework, 'framework_name' => $change->framework_name, 'policies' => $resolved])->save();
            }

            return $change;
        });
    }
}
