<?php

namespace Modules\Fleet\Actions;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Numbering;
use Modules\Fleet\Models\Hire;
use Modules\Fleet\Models\HirePayment;
use Modules\Payments\Actions\ManageRequests;
use Modules\Procurement\Models\Supplier;

class ManageHires
{
    public function __construct(private readonly ManageUnits $units, private readonly ManageRequests $requests) {}

    public function create(array $data, User $by): Hire
    {
        if (Carbon::parse($data['ends_on'])->lt(Carbon::parse($data['starts_on']))) {
            throw ValidationException::withMessages(['ends_on' => 'The hire must end after it starts.']);
        }
        $supplier = Supplier::findOrFail($data['supplier_id']);

        return DB::transaction(function () use ($data, $by, $supplier) {
            $hire = Hire::create(['number' => Numbering::next(Hire::class, 'HIR', $data['starts_on']), 'status' => 'active', 'created_by' => $by->id, 'currency' => $data['currency'] ?? 'TZS']
                + collect($data)->only(['supplier_id', 'reference', 'vehicle_type_id', 'starts_on', 'ends_on', 'rate_mode', 'rate', 'notes'])->all());
            foreach ($data['vehicles'] as $v) {
                $this->addVehicle($hire, $v, $by, $supplier);
            }

            return $hire->refresh();
        });
    }

    public function addVehicle(Hire $hire, array $v, User $by, ?Supplier $supplier = null): void
    {
        if ($hire->status !== 'active') {
            throw ValidationException::withMessages(['hire' => 'Vehicles are added to a running hire.']);
        }
        $supplier ??= $hire->supplier;
        $unit = $this->units->save(null, ['kind' => 'vehicle', 'vehicle_type_id' => $hire->vehicle_type_id, 'registration' => $v['registration'], 'name' => $v['name'],
            'make' => $v['make'] ?? null, 'model' => $v['model'] ?? null, 'seats' => $v['seats'] ?? null, 'fuel_type' => $v['fuel_type'] ?? null, 'ownership' => 'hired',
            'owner_name' => $supplier?->name ?? 'Hire company', 'department_id' => $v['department_id'] ?? null, 'reading' => $v['reading'] ?? 0, 'reading_date' => $hire->starts_on->min(today())->toDateString(),
            'notes' => "Hired under {$hire->number}"], $by);
        $unit->update(['fleet_hire_id' => $hire->id]);
    }

    /** Ends the hire (on its date or early): the vehicles leave the fleet. */
    public function end(Hire $hire, string $date, ?string $note, User $by): Hire
    {
        if ($hire->status !== 'active') {
            throw ValidationException::withMessages(['hire' => 'This hire has already ended.']);
        }
        $date = Carbon::parse($date)->max($hire->starts_on)->toDateString();
        DB::transaction(function () use ($hire, $date, $note, $by) {
            $hire->update(['status' => 'ended', 'ended_on' => $date, 'end_note' => $note]);
            foreach ($hire->units()->where('status', '!=', 'disposed')->get() as $unit) {
                $this->units->setStatus($unit, 'disposed', "Hire {$hire->number} ended".($note ? ": {$note}" : ''), $by);
            }
        });

        return $hire->refresh();
    }

    /** Ends hires whose end date has passed. */
    public function endDue(): int
    {
        $n = 0;
        foreach (Hire::with('units')->where('status', 'active')->whereDate('ends_on', '<', today())->get() as $hire) {
            $by = User::find($hire->created_by);
            if ($by) {
                $this->end($hire, $hire->ends_on->toDateString(), null, $by);
                $n++;
            }
        }

        return $n;
    }

    /** Pays a period of the hire (never the same days twice): one draft payment request to the supplier. */
    public function pay(Hire $hire, array $data, User $by): HirePayment
    {
        $from = Carbon::parse($data['from_date'])->max($hire->starts_on);
        $to = Carbon::parse($data['to_date'])->min($hire->lastDay());
        if ($to->lt($from)) {
            throw ValidationException::withMessages(['from_date' => 'The period is outside the hire ('.$hire->starts_on->format('d M Y').' – '.$hire->lastDay()->format('d M Y').').']);
        }
        if ($paid = $hire->payments()->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)->first()) {
            throw ValidationException::withMessages(['from_date' => 'Part of this period is already paid ('.$paid->from_date->format('d M').' – '.$paid->to_date->format('d M Y').').']);
        }
        $count = $hire->units()->count();
        if ($count === 0) {
            throw ValidationException::withMessages(['hire' => 'The hire has no vehicles.']);
        }
        $days = (int) $from->diffInDays($to) + 1;
        $quantity = $hire->rate_mode === 'month' ? round($days / 30 * $count, 2) : (float) ($days * $count);
        $amount = round($quantity * $hire->rate, 2);
        $supplier = Supplier::with(['bankAccounts' => fn ($q) => $q->where('status', 'approved')->orderByDesc('is_default')])->findOrFail($hire->supplier_id);
        $bank = $supplier->bankAccounts->first();

        return DB::transaction(function () use ($hire, $data, $by, $from, $to, $quantity, $amount, $supplier, $bank, $count) {
            $p = $hire->payments()->create(['from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'quantity' => $quantity, 'amount' => $amount, 'created_by' => $by->id]);
            $period = $from->format('d M').' – '.$to->format('d M Y');
            $pr = $this->requests->save(null, ['department_id' => $data['department_id'], 'title' => mb_substr("Vehicle hire {$hire->number} {$supplier->name} {$period}", 0, 200),
                'purpose' => "{$count} vehicle(s), {$quantity} ".($hire->rate_mode === 'month' ? 'vehicle-months' : 'vehicle-days').' at '.number_format($hire->rate, 2),
                'lines' => [['budget_item_id' => $data['budget_item_id'], 'description' => "Hire {$hire->number} {$period}", 'amount' => $amount]],
                'payees' => [['name' => $supplier->name, 'kind' => 'supplier', 'method' => $bank ? 'bank' : 'cheque', 'bank_name' => $bank?->bank_name, 'account_name' => $bank?->account_name,
                    'account_number' => $bank?->account_number, 'amount' => $amount]]], $by);
            $pr->update(['supplier_id' => $supplier->id, 'source_type' => $hire->getMorphClass(), 'source_id' => $hire->id]);
            $p->update(['payment_request_id' => $pr->id]);
            $share = round($amount / $count, 2);
            foreach ($hire->units as $unit) {
                $unit->costs()->create(['date' => $to->toDateString(), 'category' => 'hire', 'amount' => $share, 'description' => "Hire {$hire->number} {$period}",
                    'document_type' => $hire->getMorphClass(), 'document_id' => $hire->id, 'created_by' => $by->id]);
            }

            return $p->refresh();
        });
    }
}
