<?php

namespace Modules\Procurement\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\VatSetting;
use Modules\Procurement\Models\GoodsReceipt;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\QuotationRequest;
use Modules\Procurement\Models\Supplier;

/** Data for the printed procurement documents (LPO, goods received note, RFQ, quotation comparison). */
final class ProcurementDocuments
{
    private static function n(float|int|null $v, int $dp = 2): string
    {
        return $v === null ? '' : number_format((float) $v, $dp);
    }

    private static function q(float|int|null $v): string
    {
        return $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    }

    private static function supplier(?Supplier $s, string $label): array
    {
        return ['label' => $label, 'name' => $s?->name ?? '—', 'lines' => [$s?->address, collect([$s?->phone, $s?->email])->filter()->implode(' · '),
            collect([$s?->tin ? "TIN {$s->tin}" : null, $s?->vrn ? "VRN {$s->vrn}" : null])->filter()->implode(' · '), $s?->contact_person ? "Attn: {$s->contact_person}" : null]];
    }

    /** @return list<array{step: string, decision: string, by: string, at: string, comment: string}> */
    public static function approvals(Model $subject): array
    {
        $wf = $subject->workflow()->with('steps.actor')->first();

        return $wf ? $wf->steps->whereIn('status', ['approved', 'rejected', 'sent_back'])->map(fn ($s) => ['step' => $s->name, 'decision' => ucfirst(str_replace('_', ' ', $s->status)),
            'by' => $s->actor?->display_name ?? '', 'at' => $s->acted_at?->format('d M Y') ?? '', 'comment' => (string) $s->comment])->values()->all() : [];
    }

    private static function vrn(): ?string
    {
        $v = VatSetting::current();

        return $v->vat_registered ? $v->vat_number : null;
    }

    public static function order(PurchaseOrder $o): array
    {
        $o->loadMissing(['supplier', 'lines.unit', 'store', 'requisition', 'creator']);
        $vat = VatSetting::registered();

        return [
            'party' => self::supplier($o->supplier, 'Supplier'),
            'meta' => ['LPO number' => $o->number, 'Date' => $o->order_date->format('d M Y'), 'Requisition' => $o->requisition?->number, 'Deliver by' => $o->delivery_date?->format('d M Y'),
                'Deliver to' => $o->delivery_place ?: $o->store?->name, 'Payment terms' => $o->payment_terms_days !== null ? "{$o->payment_terms_days} days after invoice" : null,
                'Our VRN' => self::vrn(), 'Prepared by' => $o->creator?->display_name],
            'columns' => [['key' => 'i', 'label' => '#'], ['key' => 'description', 'label' => 'Description'], ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'quantity', 'label' => 'Quantity', 'num' => true], ['key' => 'price', 'label' => 'Unit price', 'num' => true], ['key' => 'amount', 'label' => 'Amount', 'num' => true]],
            'rows' => $o->lines->values()->map(fn ($l, $i) => ['i' => $i + 1, 'description' => $l->description, 'unit' => $l->unit?->code, 'quantity' => self::q($l->quantity),
                'price' => self::n($l->unit_price), 'amount' => self::n($l->amount)])->all(),
            'totals' => [['Total'.($vat ? ' (before VAT)' : ''), self::n($o->total)]],
            'notes' => [
                'Notes' => $o->notes,
                'Terms' => implode("\n", array_filter([
                    'Quote this LPO number on the delivery note and the invoice.',
                    'Goods are accepted only after inspection; rejected items are returned at the supplier\'s cost.',
                    $vat ? 'VAT, where it applies, is shown on your tax invoice in addition to the amounts above.' : null,
                    'The invoice is paid once it matches the goods accepted.',
                ])),
            ],
            'approvals' => self::approvals($o),
            'signatures' => ['Prepared by — name, signature, date', 'Approved by — name, signature, date', 'Accepted by supplier — name, signature, stamp'],
        ];
    }

    public static function receipt(GoodsReceipt $g): array
    {
        $g->loadMissing(['order.supplier', 'lines.orderLine.unit', 'store', 'creator']);

        return [
            'party' => self::supplier($g->order?->supplier, 'Delivered by'),
            'meta' => ['GRN number' => $g->number, 'Received on' => $g->received_on->format('d M Y'), 'LPO' => $g->order?->number, 'Delivery note' => $g->delivery_note,
                'Store' => $g->store?->name, 'Supplier invoice' => $g->supplier_invoice ? $g->supplier_invoice.($g->invoice_date ? ' of '.$g->invoice_date->format('d M Y') : '') : null,
                'Invoice amount' => $g->invoice_amount !== null ? self::n($g->invoice_amount).($g->invoice_vat > 0 ? ' (VAT '.self::n($g->invoice_vat).')' : '') : null,
                'Recorded by' => $g->creator?->display_name],
            'columns' => [['key' => 'i', 'label' => '#'], ['key' => 'description', 'label' => 'Item'], ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'delivered', 'label' => 'Delivered', 'num' => true], ['key' => 'accepted', 'label' => 'Accepted', 'num' => true], ['key' => 'rejected', 'label' => 'Rejected', 'num' => true],
                ['key' => 'remarks', 'label' => 'Batch / remarks'], ['key' => 'amount', 'label' => 'Value accepted', 'num' => true]],
            'rows' => $g->lines->values()->map(fn ($l, $i) => ['i' => $i + 1, 'description' => $l->orderLine?->description, 'unit' => $l->orderLine?->unit?->code,
                'delivered' => self::q($l->delivered_quantity), 'accepted' => self::q($l->accepted_quantity), 'rejected' => $l->rejected_quantity > 0 ? self::q($l->rejected_quantity) : '',
                'remarks' => collect([$l->batch_number ? "Batch {$l->batch_number}" : null, $l->expiry_date ? 'exp. '.$l->expiry_date->format('d M Y') : null, $l->rejection_reason])->filter()->implode(' · '),
                'amount' => self::n($l->amount)])->all(),
            'totals' => [['Value of goods accepted', self::n($g->total)]],
            'notes' => ['Notes' => $g->notes],
            'approvals' => self::approvals($g),
            'signatures' => ['Received by (store) — name, signature, date', 'Inspected by — name, signature, date', 'Delivered by (supplier) — name, signature'],
        ];
    }

    public static function rfq(QuotationRequest $q, ?Supplier $to): array
    {
        $q->loadMissing(['requisition.lines.unit', 'requisition.department', 'creator']);
        $vat = VatSetting::registered();

        return [
            'party' => $to ? self::supplier($to, 'To') : ['label' => 'To', 'name' => 'Invited suppliers', 'lines' => []],
            'meta' => ['RFQ number' => $q->number, 'Date' => $q->created_at->format('d M Y'), 'Closing date' => $q->closing_date->format('d M Y'),
                'Deliver to' => $q->requisition?->delivery_place ?: $q->requisition?->department?->name, 'Needed by' => $q->requisition?->needed_by?->format('d M Y'), 'Contact' => $q->creator?->display_name],
            'intro' => "You are invited to quote for the items below ({$q->title}). Fill in your unit prices, sign and return this form with your quotation by ".$q->closing_date->format('d M Y').'.',
            'columns' => [['key' => 'i', 'label' => '#'], ['key' => 'description', 'label' => 'Description and specification'], ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'quantity', 'label' => 'Quantity', 'num' => true], ['key' => 'price', 'label' => 'Unit price', 'num' => true], ['key' => 'amount', 'label' => 'Amount', 'num' => true]],
            'rows' => ($q->requisition?->lines ?? collect())->values()->map(fn ($l, $i) => ['i' => $i + 1, 'description' => $l->description.($l->specification ? " — {$l->specification}" : ''),
                'unit' => $l->unit?->code, 'quantity' => self::q($l->quantity), 'price' => '', 'amount' => ''])->all(),
            'totals' => array_values(array_filter([['Subtotal', ''], $vat ? ['VAT', ''] : null, ['Total', '']])),
            'notes' => ['Instructions' => implode("\n", array_filter([$q->instructions, 'State the delivery time, how long the prices are valid and your payment terms.',
                $vat ? 'Show VAT separately; attach your VAT and TIN certificates.' : 'Attach your TIN certificate.']))],
            'signatures' => ['Supplier name and stamp', 'Authorised signature', 'Date'],
        ];
    }

    public static function comparison(QuotationRequest $q): array
    {
        $q->loadMissing(['requisition.lines.unit', 'quotations.supplier', 'quotations.lines']);
        $quotes = $q->quotations->sortBy('total')->values();
        $columns = [['key' => 'description', 'label' => 'Item'], ['key' => 'quantity', 'label' => 'Qty', 'num' => true]];
        foreach ($quotes as $i => $qt) {
            $columns[] = ['key' => "s{$i}", 'label' => $qt->supplier?->name.($qt->responsive ? '' : ' (not responsive)'), 'num' => true];
        }
        $rows = ($q->requisition?->lines ?? collect())->values()->map(function ($l) use ($quotes) {
            $row = ['description' => $l->description, 'quantity' => self::q($l->quantity), '_best' => []];
            $prices = [];
            foreach ($quotes as $i => $qt) {
                $ql = $qt->lines->firstWhere('purchase_requisition_line_id', $l->id);
                $row["s{$i}"] = $ql ? self::n($ql->amount).($ql->awarded ? ' ✓' : '') : '—';
                if ($ql && $qt->responsive) {
                    $prices["s{$i}"] = (float) $ql->amount;
                }
            }
            if ($prices) {
                $row['_best'] = array_keys($prices, min($prices));
            }

            return $row;
        })->all();
        $total = ['description' => 'Total', 'quantity' => '', '_best' => []];
        $delivery = ['description' => 'Delivery (days) / valid until', 'quantity' => '', '_best' => []];
        foreach ($quotes as $i => $qt) {
            $total["s{$i}"] = self::n($qt->total);
            $delivery["s{$i}"] = trim(($qt->delivery_days ? "{$qt->delivery_days} d" : '').($qt->valid_until ? ' / '.$qt->valid_until->format('d M Y') : ''), ' /');
        }
        $responsive = $quotes->where('responsive', true);
        if ($responsive->isNotEmpty()) {
            $total['_best'] = ['s'.$quotes->search(fn ($x) => $x->id === $responsive->sortBy('total')->first()->id)];
        }

        return [
            'meta' => ['RFQ' => $q->number, 'Title' => $q->title, 'Requisition' => $q->requisition?->number, 'Closed' => $q->closing_date->format('d M Y'), 'Quotations' => (string) $quotes->count()],
            'columns' => $columns,
            'rows' => [...$rows, $total, $delivery],
            'notes' => ['Recommendation / award' => $q->award_comment, 'Key' => 'Lowest responsive price in bold; ✓ marks the awarded lines.'],
            'approvals' => self::approvals($q),
            'signatures' => ['Evaluated by — name, signature, date', 'Evaluated by — name, signature, date', 'Approved by — name, signature, date'],
        ];
    }
}
