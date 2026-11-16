<?php

namespace Modules\Procurement\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Numbering;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Quotation;
use Modules\Procurement\Models\QuotationRequest;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Workflow\QuotationAwardProcess;
use Modules\Workflow\Services\WorkflowEngine;

class ManageQuotations
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    public function open(PurchaseRequisition $r, array $data, User $by): QuotationRequest
    {
        if ($r->status !== 'approved') {
            throw ValidationException::withMessages(['requisition' => 'Quotations are requested for an approved requisition that is not yet ordered.']);
        }
        if ($r->quotationRequests()->whereIn('status', ['open', 'pending'])->exists()) {
            throw ValidationException::withMessages(['requisition' => 'This requisition already has a request for quotations in progress.']);
        }

        return QuotationRequest::create($data + ['number' => Numbering::next(QuotationRequest::class, 'RFQ'), 'purchase_requisition_id' => $r->id, 'status' => 'open', 'created_by' => $by->id]);
    }

    /** @param list<array{purchase_requisition_line_id: int, unit_price: float, quantity: ?float, remarks: ?string}> $lines */
    public function record(QuotationRequest $rfq, array $data, ?UploadedFile $file): Quotation
    {
        if ($rfq->status !== 'open') {
            throw ValidationException::withMessages(['quotation' => 'Quotations are recorded while the request is open.']);
        }
        $supplier = Supplier::findOrFail($data['supplier_id']);
        if (! $supplier->usable()) {
            throw ValidationException::withMessages(['supplier_id' => "{$supplier->name} is inactive or blacklisted."]);
        }
        $reqLines = $rfq->requisition->lines->keyBy('id');

        return DB::transaction(function () use ($rfq, $data, $file, $reqLines) {
            $q = Quotation::updateOrCreate(['quotation_request_id' => $rfq->id, 'supplier_id' => $data['supplier_id']],
                collect($data)->only(['reference', 'valid_until', 'delivery_days', 'notes'])->all() + ['responsive' => (bool) ($data['responsive'] ?? true)]);
            if ($file) {
                $q->update(['path' => $file->storeAs("procurement/{$rfq->company_id}/rfq/{$rfq->id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'pdf'), 'local'),
                    'file_name' => mb_substr($file->getClientOriginalName(), 0, 200)]);
            }
            $q->lines()->delete();
            foreach ($data['lines'] as $l) {
                $line = $reqLines[$l['purchase_requisition_line_id']] ?? null;
                if (! $line) {
                    throw ValidationException::withMessages(['lines' => 'A quoted line is not on the requisition.']);
                }
                $qty = round((float) ($l['quantity'] ?? $line->remaining()), 4);
                $q->lines()->create(['purchase_requisition_line_id' => $line->id, 'unit_price' => round((float) $l['unit_price'], 2), 'quantity' => $qty,
                    'amount' => round($qty * (float) $l['unit_price'], 2), 'remarks' => $l['remarks'] ?? null]);
            }
            $q->update(['total' => round((float) $q->lines()->sum('amount'), 2)]);

            return $q->refresh();
        });
    }

    /** @param array<int, int> $awards requisition line id => quotation id */
    public function award(QuotationRequest $rfq, array $awards, ?string $comment, User $by): QuotationRequest
    {
        if ($rfq->status !== 'open') {
            throw ValidationException::withMessages(['rfq' => 'Only an open request can be awarded.']);
        }
        $responsive = $rfq->quotations()->where('responsive', true)->count();
        $min = ProcurementSetting::current()->min_quotes;
        if ($responsive < $min && ! $comment) {
            throw ValidationException::withMessages(['comment' => "Only {$responsive} responsive quotation(s), fewer than the minimum of {$min}. Explain why the award should still go ahead."]);
        }
        $quotes = $rfq->quotations()->with('lines')->get()->keyBy('id');

        return DB::transaction(function () use ($rfq, $awards, $comment, $by, $quotes) {
            foreach ($quotes as $q) {
                $q->lines()->update(['awarded' => false]);
            }
            foreach ($awards as $lineId => $quoteId) {
                $q = $quotes[$quoteId] ?? null;
                $ql = $q?->lines->firstWhere('purchase_requisition_line_id', (int) $lineId);
                if (! $q || ! $q->responsive || ! $ql) {
                    throw ValidationException::withMessages(['awards' => 'Award each line to a responsive quotation that priced it.']);
                }
                $ql->update(['awarded' => true]);
            }
            $rfq->update(['status' => 'pending', 'award_comment' => $comment]);
            if (! $this->workflow->start(QuotationAwardProcess::KEY, $rfq, $by)) {
                $this->awarded($rfq);
            }

            return $rfq->refresh();
        });
    }

    /** Approved award: one draft purchase order per winning supplier. */
    public function awarded(QuotationRequest $rfq): void
    {
        DB::transaction(function () use ($rfq) {
            $r = $rfq->requisition()->with('lines')->first();
            $rfq->load('quotations.lines', 'quotations.supplier');
            foreach ($rfq->quotations as $q) {
                $won = $q->lines->where('awarded', true);
                if ($won->isEmpty()) {
                    continue;
                }
                $po = PurchaseOrder::create(['number' => Numbering::next(PurchaseOrder::class, 'PO'), 'supplier_id' => $q->supplier_id, 'purchase_requisition_id' => $r->id,
                    'quotation_id' => $q->id, 'store_id' => $r->store_id, 'method' => 'quotation', 'order_date' => today(), 'delivery_date' => $q->delivery_days ? today()->addDays($q->delivery_days) : $r->needed_by,
                    'delivery_place' => $r->delivery_place, 'payment_terms_days' => $q->supplier->payment_terms_days, 'status' => 'draft', 'created_by' => $rfq->created_by]);
                foreach ($won as $ql) {
                    $line = $r->lines->firstWhere('id', $ql->purchase_requisition_line_id);
                    $po->lines()->create(['purchase_requisition_line_id' => $line->id, 'item_id' => $line->item_id, 'description' => $line->description, 'unit_id' => $line->unit_id,
                        'quantity' => $ql->quantity, 'unit_price' => $ql->unit_price, 'amount' => $ql->amount, 'budget_item_id' => $line->budget_item_id]);
                }
                $po->update(['total' => round((float) $po->lines()->sum('amount'), 2)]);
            }
            $rfq->update(['status' => 'awarded', 'decided_at' => now()]);
        });
    }

    public function awardRejected(QuotationRequest $rfq, ?string $comment): void
    {
        $rfq->update(['status' => 'open', 'award_comment' => $comment]);
    }

    public function cancel(QuotationRequest $rfq, string $reason): void
    {
        if (! in_array($rfq->status, ['open'], true)) {
            throw ValidationException::withMessages(['rfq' => 'Only an open request can be cancelled.']);
        }
        $rfq->update(['status' => 'cancelled', 'award_comment' => $reason]);
    }
}
