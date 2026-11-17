<?php

namespace Modules\Fleet\Actions;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Numbering;
use Modules\Fleet\Models\AgencyBatch;
use Modules\Fleet\Models\AgencyContract;
use Modules\Fleet\Models\Trip;
use Modules\Payments\Actions\ManageRequests;
use Modules\Procurement\Models\Supplier;

/** Transport companies on contract; their confirmed trips are gathered into a batch that raises one payment request. */
class ManageAgencies
{
    public function __construct(private readonly ManageRequests $requests) {}

    public function save(?AgencyContract $c, array $data, User $by): AgencyContract
    {
        if (Carbon::parse($data['ends_on'])->lt(Carbon::parse($data['starts_on']))) {
            throw ValidationException::withMessages(['ends_on' => 'The contract must end after it starts.']);
        }
        $c ??= new AgencyContract(['created_by' => $by->id]);
        $c->fill(collect($data)->only(['supplier_id', 'reference', 'mode', 'rate', 'currency', 'starts_on', 'ends_on', 'notes'])->all() + ['is_active' => (bool) ($data['is_active'] ?? true)])->save();

        return $c->refresh();
    }

    /** @return \Illuminate\Support\Collection<int, Trip> */
    public function unbatched(AgencyContract $c, string $from, string $to)
    {
        return $c->trips()->where('status', 'confirmed')->whereNull('fleet_agency_batch_id')->whereNotNull('agency_amount')
            ->whereDate('ended_at', '>=', $from)->whereDate('ended_at', '<=', $to)->orderBy('ended_at')->get();
    }

    public function batch(AgencyContract $c, array $data, User $by): AgencyBatch
    {
        $trips = $this->unbatched($c, $data['from_date'], $data['to_date']);
        if ($trips->isEmpty()) {
            throw ValidationException::withMessages(['from_date' => 'No confirmed trips of this contract in that period are waiting for payment.']);
        }
        $total = round($trips->sum('agency_amount'), 2);
        $supplier = Supplier::with(['bankAccounts' => fn ($q) => $q->where('status', 'approved')->orderByDesc('is_default')])->find($c->supplier_id);
        $bank = $supplier?->bankAccounts->first();

        return DB::transaction(function () use ($c, $data, $by, $trips, $total, $supplier, $bank) {
            $batch = AgencyBatch::create(['number' => Numbering::next(AgencyBatch::class, 'TB'), 'fleet_agency_contract_id' => $c->id, 'from_date' => $data['from_date'],
                'to_date' => $data['to_date'], 'total' => $total, 'created_by' => $by->id]);
            $period = Carbon::parse($data['from_date'])->format('d M').' – '.Carbon::parse($data['to_date'])->format('d M Y');
            $pr = $this->requests->save(null, [
                'department_id' => $data['department_id'], 'title' => mb_substr("Transport {$c->label()} {$period}", 0, 200),
                'purpose' => "Batch {$batch->number}: {$trips->count()} trip(s) ".$trips->pluck('number')->join(', '),
                'lines' => [['budget_item_id' => $data['budget_item_id'], 'description' => "Transport batch {$batch->number}", 'amount' => $total]],
                'payees' => [['name' => $supplier?->name ?? 'Transport company', 'kind' => 'supplier', 'method' => $bank ? 'bank' : 'cheque', 'bank_name' => $bank?->bank_name,
                    'account_name' => $bank?->account_name, 'account_number' => $bank?->account_number, 'amount' => $total]],
            ], $by);
            $pr->update(['supplier_id' => $c->supplier_id, 'source_type' => $batch->getMorphClass(), 'source_id' => $batch->id]);
            $batch->update(['payment_request_id' => $pr->id]);
            Trip::whereIn('id', $trips->pluck('id'))->update(['fleet_agency_batch_id' => $batch->id]);

            return $batch->refresh();
        });
    }
}
