<?php

namespace Modules\Accounting\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Http\Requests\TaxCodeRequest;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Accounting\Models\TaxCode;
use Modules\Accounting\Models\VatSetting;
use Modules\Payments\Models\PaymentVoucher;
use Modules\Receivables\Models\CreditNote;
use Modules\Receivables\Models\Invoice;
use Modules\Receivables\Models\InvoiceLine;
use Modules\Receivables\Models\ReceiptLine;

/**
 * @tags Accounting: VAT
 */
class VatController extends Controller
{
    /**
     * VAT registration, accounts and tax codes.
     *
     * @response array{data: array{vat_registered: bool, vat_number: string|null, output_account_id: int|null, input_account_id: int|null}, codes: list<array{id: int, code: string, name: string, kind: string, kind_label: string, rate: float, is_active: bool}>, kinds: list<array{value: string, label: string}>, accounts: list<array{id: int, code: string, name: string, type: string}>}
     */
    public function show(): JsonResponse
    {
        $s = VatSetting::current();

        return response()->json([
            'data' => ['vat_registered' => (bool) $s->vat_registered, 'vat_number' => $s->vat_number, 'output_account_id' => $s->output_account_id, 'input_account_id' => $s->input_account_id],
            'codes' => TaxCode::orderBy('code')->get()->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind, 'kind_label' => TaxCode::KINDS[$c->kind] ?? $c->kind,
                'rate' => $c->rate, 'is_active' => (bool) $c->is_active])->values(),
            'kinds' => collect(TaxCode::KINDS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'accounts' => Account::where('is_active', true)->where('is_postable', true)->whereIn('type', ['liability', 'asset'])->orderBy('code')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type])->values(),
        ]);
    }

    /** @response array{data: array{vat_registered: bool, vat_number: string|null, output_account_id: int|null, input_account_id: int|null}, codes: list<array{id: int, code: string, name: string, kind: string, kind_label: string, rate: float, is_active: bool}>, kinds: list<array{value: string, label: string}>, accounts: list<array{id: int, code: string, name: string, type: string}>} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            /** Charge VAT on sales and claim it on purchases. Leave off when the organisation is not VAT registered. */
            'vat_registered' => ['required', 'boolean'],
            'vat_number' => ['nullable', 'string', 'max:40'],
            'output_account_id' => ['nullable', 'required_if:vat_registered,true', 'integer', 'exists:accounts,id'],
            'input_account_id' => ['nullable', 'required_if:vat_registered,true', 'integer', 'exists:accounts,id'],
        ]);
        VatSetting::current()->fill($data)->save();
        if ($data['vat_registered']) {
            TaxCode::ensureDefaults();
        }

        return $this->show();
    }

    /** @response array{data: array{id: int}} */
    public function storeCode(TaxCodeRequest $request): JsonResponse
    {
        $c = TaxCode::create($this->codeData($request));

        return response()->json(['data' => ['id' => $c->id]], 201);
    }

    /** @response array{data: array{id: int}} */
    public function updateCode(TaxCodeRequest $request, TaxCode $code): JsonResponse
    {
        $code->update($this->codeData($request));

        return response()->json(['data' => ['id' => $code->id]]);
    }

    private function codeData(TaxCodeRequest $request): array
    {
        $data = $request->validated();
        if ($data['kind'] !== 'standard') {
            $data['rate'] = 0;
        }

        return $data;
    }

    /**
     * VAT summary for a period: output VAT on sales less credit notes, input VAT on purchases, and what is due.
     *
     * @response array{data: array{from: string, to: string, vat_number: string|null, sales: list<array{code: string, name: string, kind: string, net: float, vat: float}>, credit_notes: array{count: int, amount: float, vat: float}, cash_sales: list<array{code: string, name: string, net: float, vat: float}>, output_vat: float, purchases: list<array{id: int, kind: string, link: string, number: string, date: string, payee: string|null, vat_invoice_no: string|null, gross: float, vat: float}>, input_vat: float, net_vat: float, ledger: array{output: float, input: float}}}
     */
    #[QueryParameter('from', type: 'string', required: true)]
    #[QueryParameter('to', type: 'string', required: true)]
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        [$from, $to] = [$data['from'], $data['to']];
        $codes = TaxCode::get()->keyBy('id');
        $label = fn ($id) => $id && $codes->has($id) ? [$codes[$id]->code, $codes[$id]->name, $codes[$id]->kind] : ['—', 'No VAT code', 'none'];

        $sales = InvoiceLine::query()->whereHas('invoice', fn ($q) => $q->whereIn('status', Invoice::ISSUED)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->selectRaw('tax_code_id, SUM(amount) net, SUM(tax_amount) vat')->groupBy('tax_code_id')->get()
            ->map(function ($r) use ($label) {
                [$c, $n, $k] = $label($r->tax_code_id);

                return ['code' => $c, 'name' => $n, 'kind' => $k, 'net' => round((float) $r->net, 2), 'vat' => round((float) $r->vat, 2)];
            })->values();
        $notes = CreditNote::where('status', 'approved')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
        $credit = ['count' => (clone $notes)->count(), 'amount' => round((float) (clone $notes)->sum('amount'), 2), 'vat' => round((float) (clone $notes)->sum('tax_amount'), 2)];
        $cash = ReceiptLine::query()->whereNotNull('tax_code_id')->whereHas('receipt', fn ($q) => $q->whereIn('status', ['posted', 'void_pending'])->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))
            ->selectRaw('tax_code_id, SUM(amount) gross, SUM(tax_amount) vat')->groupBy('tax_code_id')->get()
            ->map(function ($r) use ($label) {
                [$c, $n] = $label($r->tax_code_id);

                return ['code' => $c, 'name' => $n, 'net' => round((float) $r->gross - (float) $r->vat, 2), 'vat' => round((float) $r->vat, 2)];
            })->values();
        $output = round($sales->sum('vat') - $credit['vat'] + $cash->sum('vat'), 2);
        $purchases = PaymentVoucher::with('request.payees')->whereIn('status', ['approved', 'paid'])->where('input_vat', '>', 0)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->orderBy('date')->get()->map(fn ($v) => ['id' => $v->id, 'kind' => 'Payment voucher', 'link' => "/payments/vouchers/{$v->id}", 'number' => $v->number, 'date' => $v->date->toDateString(),
                'payee' => $v->request?->payees->pluck('name')->join(', '), 'vat_invoice_no' => $v->vat_invoice_no, 'gross' => (float) $v->gross, 'vat' => (float) $v->input_vat])
            ->concat(\Modules\Payments\Models\PettyCashVoucher::where('status', 'approved')->where('input_vat', '>', 0)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()
                ->map(fn ($v) => ['id' => $v->id, 'kind' => 'Petty cash', 'link' => '/payments/petty-cash', 'number' => $v->number, 'date' => $v->date->toDateString(), 'payee' => $v->payee,
                    'vat_invoice_no' => $v->vat_invoice_no, 'gross' => (float) $v->amount, 'vat' => (float) $v->input_vat]))
            ->concat(\Modules\Payments\Models\ImprestRetirementLine::with('retirement')->where('input_vat', '>', 0)
                ->whereHas('retirement', fn ($q) => $q->where('status', 'approved')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))->get()
                ->map(fn ($l) => ['id' => $l->id, 'kind' => 'Imprest retirement', 'link' => "/payments/requests/{$l->retirement->payment_request_id}", 'number' => $l->retirement->number,
                    'date' => $l->retirement->date->toDateString(), 'payee' => $l->description, 'vat_invoice_no' => $l->vat_invoice_no, 'gross' => (float) $l->amount, 'vat' => (float) $l->input_vat]))
            ->sortBy('date')->values();
        $input = round($purchases->sum('vat'), 2);
        $s = VatSetting::current();
        $move = fn (?int $account, int $sign) => $account ? round($sign * (float) JournalEntryLine::where('account_id', $account)
            ->whereHas('entry', fn ($q) => $q->whereIn('status', ['posted', 'reversed'])->whereDate('date', '>=', $from)->whereDate('date', '<=', $to))->sum(\Illuminate\Support\Facades\DB::raw('credit - debit')), 2) : 0.0;

        return response()->json(['data' => [
            'from' => $from, 'to' => $to, 'vat_number' => $s->vat_number, 'sales' => $sales, 'credit_notes' => $credit, 'cash_sales' => $cash, 'output_vat' => $output,
            'purchases' => $purchases, 'input_vat' => $input, 'net_vat' => round($output - $input, 2),
            'ledger' => ['output' => $move($s->output_account_id, 1), 'input' => $move($s->input_account_id, -1)],
        ]]);
    }
}
