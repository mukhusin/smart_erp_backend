<?php

namespace Modules\Procurement\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Core\Support\Numbering;
use Modules\Procurement\Models\ProcurementMethod;
use Modules\Procurement\Models\ProcurementSetting;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Models\TenderAward;
use Modules\Procurement\Models\TenderBid;
use Modules\Procurement\Models\TenderComplaint;
use Modules\Procurement\Models\TenderMember;
use Modules\Procurement\Workflow\TenderAwardProcess;
use Modules\Procurement\Workflow\TenderDocumentProcess;
use Modules\Procurement\Workflow\TenderEvaluationProcess;
use Modules\Workflow\Services\WorkflowEngine;

/**
 * Tender from an approved requisition: document → approval → bids (sealed until opening) → opening record →
 * evaluation by a declared team → approved report → award by the tender board → standstill and complaints →
 * award letters → contract. The requisition keeps holding the budget until the contract is signed.
 */
class ManageTenders
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function create(PurchaseRequisition $r, array $data, User $by): Tender
    {
        if ($r->status !== 'approved') {
            $this->fail('requisition', 'A tender is made from an approved requisition with something still to order.');
        }
        $method = ProcurementMethod::where('is_active', true)->find($data['procurement_method_id']);
        if (! $method || ! in_array($method->sourcing, ['tender', 'single'], true)) {
            $this->fail('procurement_method_id', 'Choose an active tender or single-source method.');
        }
        $estimate = round($r->lines->sum(fn ($l) => $l->remaining() * $l->estimated_unit_cost), 2);
        if (! $method->allows($estimate) && empty($data['method_reason'])) {
            $this->fail('method_reason', "{$method->name} is not meant for ".number_format($estimate, 2).'. Give the reason for using it.');
        }
        if ($method->needs_reason && empty($data['method_reason'])) {
            $this->fail('method_reason', "{$method->name} needs a written reason.");
        }

        return DB::transaction(function () use ($r, $data, $by) {
            $t = Tender::create(['number' => Numbering::next(Tender::class, 'TND'), 'purchase_requisition_id' => $r->id, 'status' => 'draft', 'created_by' => $by->id] + $this->fields($data));
            $this->saveDetails($t, $r, $data);

            return $t->refresh();
        });
    }

    private function fields(array $data): array
    {
        return collect($data)->only(['procurement_method_id', 'title', 'document_type', 'instructions', 'method_reason', 'closing_at', 'validity_days', 'bid_security_amount'])->all();
    }

    private function saveDetails(Tender $t, PurchaseRequisition $r, array $data): void
    {
        $lines = $r->lines->keyBy('id');
        $t->lines()->delete();
        $rows = $data['lines'] ?? $r->lines->filter(fn ($l) => $l->remaining() > 0)->map(fn ($l) => ['purchase_requisition_line_id' => $l->id, 'lot' => 'Lot 1', 'quantity' => $l->remaining()])->values()->all();
        foreach ($rows as $i => $row) {
            $line = $lines[$row['purchase_requisition_line_id']] ?? null;
            if (! $line || (float) $row['quantity'] <= 0 || (float) $row['quantity'] > $line->remaining() + 0.0001) {
                $this->fail("lines.$i.quantity", 'Each line must be on the requisition, with at most what is still to order.');
            }
            $t->lines()->create(['purchase_requisition_line_id' => $line->id, 'lot' => trim((string) ($row['lot'] ?? '')) ?: 'Lot 1', 'quantity' => round((float) $row['quantity'], 4)]);
        }
        if (array_key_exists('criteria', $data)) {
            $t->criteria()->delete();
            foreach (array_values($data['criteria'] ?? []) as $i => $c) {
                $t->criteria()->create(['name' => $c['name'], 'kind' => $c['kind'], 'weight' => $c['kind'] === 'technical' ? round((float) ($c['weight'] ?? 0), 2) : 0, 'sort' => $i]);
            }
        }
    }

    public function update(Tender $t, array $data): Tender
    {
        if (! $t->editable()) {
            $this->fail('tender', 'Only a draft or returned tender document can be changed.');
        }

        return DB::transaction(function () use ($t, $data) {
            $t->update($this->fields($data));
            $this->saveDetails($t, $t->requisition()->with('lines')->first(), $data);

            return $t->refresh();
        });
    }

    public function submitDocument(Tender $t, User $by): Tender
    {
        if (! $t->editable()) {
            $this->fail('tender', 'This tender document is already submitted.');
        }
        $t->load(['lines', 'criteria', 'method']);
        if ($t->lines->isEmpty()) {
            $this->fail('tender', 'Add the items to tender.');
        }
        if ($t->closing_at->isPast()) {
            $this->fail('closing_at', 'The closing time must be in the future.');
        }
        $weights = round($t->criteria->where('kind', 'technical')->sum('weight'), 2);
        if (in_array($t->method->evaluation, ['technical', 'combined'], true) && $weights != 100.0) {
            $this->fail('criteria', 'The technical criteria weights must add up to 100 (now '.$weights.').');
        }

        return DB::transaction(function () use ($t, $by) {
            $t->update(['status' => 'document_review', 'decision_comment' => null]);
            if (! $this->workflow->start(TenderDocumentProcess::KEY, $t, $by)) {
                $this->documentApproved($t);
            }

            return $t->refresh();
        });
    }

    public function documentApproved(Tender $t): void
    {
        $t->update(['status' => 'published']);
        $t->creator?->notify(new Notice('Tender published', "{$t->number} {$t->title} is approved and open for bids until ".$t->closing_at->format('d M Y H:i').'.', "/procurement/tenders/{$t->id}", 'megaphone'));
    }

    public function documentRejected(Tender $t, ?string $comment, bool $withdrawn): void
    {
        $t->update(['status' => $withdrawn ? 'draft' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment]);
    }

    public function clarify(Tender $t, array $data, User $by): Tender
    {
        if ($t->status !== 'published') {
            $this->fail('tender', 'Clarifications and amendments are issued while the tender is open.');
        }
        DB::transaction(function () use ($t, $data, $by) {
            $closing = ! empty($data['closing_at']) ? Carbon::parse($data['closing_at']) : null;
            if ($closing && $closing->lt($t->closing_at)) {
                $this->fail('closing_at', 'An amendment can only move the closing time later.');
            }
            $t->clarifications()->create(['kind' => $data['kind'], 'question' => $data['question'] ?? null, 'answer' => $data['answer'], 'closing_at' => $closing, 'created_by' => $by->id]);
            if ($closing) {
                $t->update(['closing_at' => $closing]);
            }
        });

        return $t->refresh();
    }

    /** Bids are recorded as they arrive; prices stay sealed (encrypted, never shown) until the opening. */
    public function recordBid(Tender $t, array $data, ?UploadedFile $file): TenderBid
    {
        if ($t->status !== 'published') {
            $this->fail('tender', 'Bids are recorded while the tender is open.');
        }
        $received = Carbon::parse($data['received_at'] ?? now());
        if ($received->gt($t->closing_at)) {
            $this->fail('received_at', 'This bid arrived after the closing time and cannot be accepted.');
        }
        $supplier = Supplier::find($data['supplier_id']);
        if (! $supplier?->usable()) {
            $this->fail('supplier_id', 'Choose an active supplier that is not blacklisted.');
        }
        $lines = $t->lines()->get()->keyBy('id');
        $prices = collect($data['lines'] ?? [])->filter(fn ($l) => isset($lines[$l['tender_line_id']]) && $l['unit_price'] !== null && $l['unit_price'] !== '');
        if ($prices->isEmpty()) {
            $this->fail('lines', 'Enter the prices of at least one lot.');
        }
        foreach ($prices->groupBy(fn ($l) => $lines[$l['tender_line_id']]->lot) as $lot => $priced) {
            if ($priced->count() !== $lines->where('lot', $lot)->count()) {
                $this->fail('lines', "{$lot}: a bid must price every line of the lot it bids for.");
            }
        }

        return DB::transaction(function () use ($t, $data, $file, $supplier, $received, $prices) {
            $bid = TenderBid::updateOrCreate(['tender_id' => $t->id, 'supplier_id' => $supplier->id], [
                'received_at' => $received, 'currency' => $data['currency'] ?? null, 'discount_percent' => (float) ($data['discount_percent'] ?? 0), 'valid_until' => $data['valid_until'] ?? null,
                'bid_security' => $data['bid_security'] ?? null, 'documents_complete' => (bool) ($data['documents_complete'] ?? true), 'missing_documents' => $data['missing_documents'] ?? null,
                'notes' => $data['notes'] ?? null] + ($file ? ['path' => $file->store('tender-bids', 'local'), 'file_name' => $file->getClientOriginalName()] : []));
            $bid->lines()->delete();
            foreach ($prices as $p) {
                $bid->lines()->create(['tender_line_id' => $p['tender_line_id'], 'unit_price' => (string) round((float) $p['unit_price'], 2)]);
            }

            return $bid->refresh();
        });
    }

    public function addMember(Tender $t, array $data): TenderMember
    {
        if (in_array($t->status, ['contracted', 'cancelled'], true)) {
            $this->fail('tender', 'This tender is finished.');
        }
        if ($data['role'] === 'evaluation' && (int) $data['user_id'] === (int) $t->requisition->requested_by) {
            $this->fail('user_id', 'The person who requested the purchase cannot evaluate it.');
        }
        if ($data['role'] === 'evaluation' && (int) $data['user_id'] === (int) $t->created_by) {
            $this->fail('user_id', 'The officer who prepared the tender cannot evaluate it.');
        }
        $m = TenderMember::firstOrCreate(['tender_id' => $t->id, 'user_id' => $data['user_id'], 'role' => $data['role']], ['chair' => (bool) ($data['chair'] ?? false)]);
        $m->user?->notify(new Notice('Tender committee', "You are on the {$m->role} team for {$t->number} {$t->title}. Sign the conflict-of-interest declaration before you see the bids.", "/procurement/tenders/{$t->id}", 'people'));

        return $m;
    }

    public function removeMember(TenderMember $m): void
    {
        if ($m->declared_at && ! $m->conflict && TenderBid::whereHas('scores', fn ($s) => $s->where('scored_by', $m->user_id))->where('tender_id', $m->tender_id)->exists()) {
            $this->fail('member', 'This member has already scored bids.');
        }
        $m->delete();
    }

    /** Conflict-of-interest and confidentiality declaration; declaring a conflict removes the member from the work. */
    public function declare(Tender $t, User $user, bool $conflict, ?string $note): void
    {
        $members = TenderMember::where('tender_id', $t->id)->where('user_id', $user->id)->get();
        if ($members->isEmpty()) {
            $this->fail('tender', 'You are not on a committee of this tender.');
        }
        if ($conflict && ! $note) {
            $this->fail('conflict_note', 'Describe the conflict.');
        }
        foreach ($members as $m) {
            $m->update(['declared_at' => now(), 'conflict' => $conflict, 'conflict_note' => $note]);
        }
    }

    public function open(Tender $t, array $data, User $by): Tender
    {
        if ($t->status !== 'published') {
            $this->fail('tender', 'Only a tender open for bids can be opened.');
        }
        if ($t->closing_at->isFuture()) {
            $this->fail('tender', 'Bids can be opened only after the closing time ('.$t->closing_at->format('d M Y H:i').').');
        }
        DB::transaction(function () use ($t, $data, $by) {
            foreach ($t->bids()->with('lines.tenderLine')->get() as $bid) {
                $bid->update(['read_out_total' => $bid->priceFor()]);
            }
            $t->update(['status' => 'opened', 'opened_at' => now(), 'opened_by' => $by->id, 'opening_attendees' => $data['attendees'] ?? null]);
        });

        return $t->refresh();
    }

    public function member(Tender $t, User $user, string $role = 'evaluation'): ?TenderMember
    {
        return TenderMember::where('tender_id', $t->id)->where('user_id', $user->id)->where('role', $role)->first();
    }

    /** @param list<array{tender_criterion_id: int, score?: ?float, passed?: ?bool, comment?: ?string}> $scores */
    public function score(TenderBid $bid, array $scores, User $by): TenderBid
    {
        $t = $bid->tender;
        if ($t->status !== 'opened') {
            $this->fail('tender', 'Bids are scored after the opening and before the evaluation report is submitted.');
        }
        if (! $this->member($t, $by)?->cleared()) {
            $this->fail('tender', 'Only evaluation team members who signed the declaration without a conflict can score.');
        }
        $criteria = $t->criteria()->get()->keyBy('id');
        DB::transaction(function () use ($bid, $scores, $by, $criteria) {
            foreach ($scores as $s) {
                $c = $criteria[$s['tender_criterion_id']] ?? null;
                if (! $c) {
                    continue;
                }
                $bid->scores()->updateOrCreate(['tender_criterion_id' => $c->id], $c->kind === 'mandatory'
                    ? ['passed' => (bool) ($s['passed'] ?? false), 'score' => null, 'comment' => $s['comment'] ?? null, 'scored_by' => $by->id]
                    : ['score' => max(0, min(100, (float) ($s['score'] ?? 0))), 'passed' => null, 'comment' => $s['comment'] ?? null, 'scored_by' => $by->id]);
            }
        });

        return $bid->refresh();
    }

    /**
     * Ranking per lot from the scores: responsive bids only; lowest (or highest for disposals) price, technical score,
     * or weighted technical and financial scores.
     *
     * @return array<string, list<array{bid_id: int, supplier: string, price: float, technical: float|null, financial: float|null, combined: float|null, rank: int}>>
     */
    public function ranking(Tender $t): array
    {
        $t->loadMissing(['method', 'criteria', 'bids.supplier', 'bids.scores', 'bids.lines.tenderLine', 'lines']);
        $m = $t->method;
        $out = [];
        foreach ($t->lots() as $lot) {
            $lotLines = $t->lines->where('lot', $lot)->pluck('id');
            $rows = $t->bids->where('withdrawn', false)->filter(fn ($b) => $b->responsive !== false && $b->lines->whereIn('tender_line_id', $lotLines)->count() === $lotLines->count())
                ->map(fn ($b) => ['bid' => $b, 'price' => $b->priceFor($lot), 'technical' => $b->technical_score])->values();
            if (in_array($m->evaluation, ['technical', 'combined'], true)) {
                $rows = $rows->filter(fn ($r) => (float) $r['technical'] >= $m->pass_mark)->values();
            }
            $best = $m->highest_wins ? $rows->max('price') : $rows->min('price');
            $rows = $rows->map(function ($r) use ($m, $best) {
                $fin = $best && $r['price'] > 0 ? round($m->highest_wins ? $r['price'] / $best * 100 : $best / $r['price'] * 100, 2) : null;
                $r['financial'] = $m->evaluation === 'technical' ? null : $fin;
                $r['combined'] = $m->evaluation === 'combined' ? round($m->technical_weight * (float) $r['technical'] / 100 + (100 - $m->technical_weight) * (float) $fin / 100, 2) : null;

                return $r;
            });
            $sorted = match ($m->evaluation) {
                'combined' => $rows->sortByDesc('combined'),
                'technical' => $rows->sortByDesc('technical'),
                default => $m->highest_wins ? $rows->sortByDesc('price') : $rows->sortBy('price'),
            };
            $out[$lot] = $sorted->values()->map(fn ($r, $i) => ['bid_id' => $r['bid']->id, 'supplier' => $r['bid']->supplier->name, 'price' => $r['price'], 'technical' => $r['technical'],
                'financial' => $r['financial'], 'combined' => $r['combined'], 'rank' => $i + 1])->all();
        }

        return $out;
    }

    /** Works out responsiveness and technical scores from the team's scoring, then sends the report for approval. */
    public function submitEvaluation(Tender $t, array $data, User $by): Tender
    {
        if ($t->status !== 'opened') {
            $this->fail('tender', 'The evaluation report is submitted after the opening.');
        }
        $t->load(['criteria', 'bids.scores', 'members', 'method']);
        if (! $t->members->where('role', 'evaluation')->contains(fn ($m) => $m->cleared())) {
            $this->fail('tender', 'Appoint an evaluation team; at least one member must sign the declaration without a conflict.');
        }
        if ($t->bids->where('withdrawn', false)->isEmpty()) {
            $this->fail('tender', 'No bids were received; cancel the tender or re-advertise.');
        }
        if ($t->method->negotiation && ! empty($data['negotiation_minutes'])) {
            $t->negotiation_minutes = $data['negotiation_minutes'];
        }

        return DB::transaction(function () use ($t, $data, $by) {
            foreach ($t->bids->where('withdrawn', false) as $bid) {
                $mandatory = $t->criteria->where('kind', 'mandatory');
                foreach ($mandatory as $c) {
                    if ($bid->scores->firstWhere('tender_criterion_id', $c->id) === null) {
                        $this->fail('scores', "{$bid->supplier->name}: '{$c->name}' is not checked yet.");
                    }
                }
                $technical = $t->criteria->where('kind', 'technical')->sum(fn ($c) => $c->weight * (float) ($bid->scores->firstWhere('tender_criterion_id', $c->id)?->score ?? 0) / 100);
                $passed = $bid->documents_complete && $mandatory->every(fn ($c) => (bool) $bid->scores->firstWhere('tender_criterion_id', $c->id)?->passed);
                $bid->update(['responsive' => $passed, 'technical_score' => $t->criteria->where('kind', 'technical')->isNotEmpty() ? round($technical, 2) : null]);
            }
            $ranking = $this->ranking($t->fresh());
            foreach ($ranking as $rows) {
                foreach ($rows as $r) {
                    TenderBid::whereKey($r['bid_id'])->update(['financial_score' => $r['financial'], 'combined_score' => $r['combined'], 'rank' => $r['rank']]);
                }
            }
            $t->update(['status' => 'evaluation_review', 'evaluation_summary' => $data['summary'] ?? null, 'negotiation_minutes' => $t->negotiation_minutes, 'decision_comment' => null]);
            if (! $this->workflow->start(TenderEvaluationProcess::KEY, $t, $by)) {
                $this->evaluationApproved($t);
            }

            return $t->refresh();
        });
    }

    public function evaluationApproved(Tender $t): void
    {
        $t->update(['status' => 'evaluated']);
    }

    public function evaluationRejected(Tender $t, ?string $comment): void
    {
        $t->update(['status' => 'opened', 'decision_comment' => $comment]);
    }

    /** @param list<array{lot: string, tender_bid_id: int, amount?: ?float}> $awards */
    public function proposeAward(Tender $t, array $awards, User $by): Tender
    {
        if ($t->status !== 'evaluated') {
            $this->fail('tender', 'The award is proposed after the evaluation report is approved.');
        }
        $t->load(['method', 'bids', 'lines']);
        $ranking = $this->ranking($t);
        $rows = [];
        foreach ($awards as $i => $a) {
            $candidates = collect($ranking[$a['lot']] ?? []);
            $row = $candidates->firstWhere('bid_id', (int) $a['tender_bid_id']);
            if (! $row) {
                $this->fail("awards.$i", "{$a['lot']}: the bid chosen is not responsive or did not bid for this lot.");
            }
            $amount = isset($a['amount']) && $a['amount'] !== null ? round((float) $a['amount'], 2) : $row['price'];
            if ($amount != $row['price'] && ! $t->method->negotiation) {
                $this->fail("awards.$i", "{$a['lot']}: the amount can differ from the bid only after a negotiation the method allows.");
            }
            $rows[$a['lot']] = ['lot' => $a['lot'], 'tender_bid_id' => $row['bid_id'], 'supplier_id' => $t->bids->firstWhere('id', $row['bid_id'])->supplier_id, 'amount' => $amount];
        }
        if (count($rows) === 0) {
            $this->fail('awards', 'Choose a winner for at least one lot.');
        }

        return DB::transaction(function () use ($t, $rows, $by) {
            $t->awards()->delete();
            foreach ($rows as $r) {
                $t->awards()->create($r);
            }
            $t->update(['status' => 'award_review', 'decision_comment' => null]);
            if (! $this->workflow->start(TenderAwardProcess::KEY, $t, $by)) {
                $this->awardApproved($t);
            }

            return $t->refresh();
        });
    }

    public function awardApproved(Tender $t): void
    {
        $days = (int) $t->method->standstill_days;
        if ($days > 0) {
            $t->update(['status' => 'standstill', 'standstill_until' => today()->addDays($days)->toDateString()]);
        } else {
            $this->sendLetters($t);
        }
    }

    public function awardRejected(Tender $t, ?string $comment): void
    {
        $t->awards()->delete();
        $t->update(['status' => 'evaluated', 'decision_comment' => $comment]);
    }

    /** After the standstill (and with no complaint still open) the winners get their award letters. */
    public function endStandstill(Tender $t): Tender
    {
        if ($t->status !== 'standstill') {
            $this->fail('tender', 'This tender is not in its standstill period.');
        }
        if ($t->standstill_until && $t->standstill_until->isFuture()) {
            $this->fail('tender', 'The standstill period runs until '.$t->standstill_until->format('d M Y').'.');
        }
        if ($t->complaints()->where('outcome', 'pending')->exists()) {
            $this->fail('tender', 'Decide the open complaints first.');
        }
        $this->sendLetters($t);

        return $t->refresh();
    }

    private function sendLetters(Tender $t): void
    {
        $days = ProcurementSetting::current()->award_acceptance_days ?: 7;
        $t->awards()->whereNull('letter_sent_at')->update(['letter_sent_at' => now(), 'accept_by' => today()->addDays($days)->toDateString()]);
        $t->update(['status' => 'awarded']);
        $t->creator?->notify(new Notice('Tender awarded', "{$t->number}: award letters are out. Record each winner's acceptance, then make the contract.", "/procurement/tenders/{$t->id}", 'trophy'));
    }

    public function respond(TenderAward $award, bool $accepted): TenderAward
    {
        if ($award->tender->status !== 'awarded' || $award->accepted_at || $award->declined_at) {
            $this->fail('award', 'This award letter is not waiting for an answer.');
        }
        DB::transaction(function () use ($award, $accepted) {
            $award->update($accepted ? ['accepted_at' => now()] : ['declined_at' => now()]);
            if (! $accepted) {
                $award->tender->update(['status' => 'evaluated', 'decision_comment' => "{$award->supplier->name} declined {$award->lot}; choose the next bidder."]);
                $award->delete();
            }
        });

        return $award;
    }

    public function complaint(Tender $t, array $data, User $by): TenderComplaint
    {
        if (! in_array($t->status, ['opened', 'evaluation_review', 'evaluated', 'award_review', 'standstill', 'awarded'], true)) {
            $this->fail('tender', 'Complaints are recorded after the opening and before the contract.');
        }

        return $t->complaints()->create(['supplier_id' => $data['supplier_id'] ?? null, 'received_on' => $data['received_on'] ?? today(), 'description' => $data['description'], 'created_by' => $by->id]);
    }

    public function decideComplaint(TenderComplaint $c, array $data): TenderComplaint
    {
        if ($c->outcome !== 'pending') {
            $this->fail('complaint', 'This complaint is already decided.');
        }
        DB::transaction(function () use ($c, $data) {
            $action = $data['outcome'] === 'upheld' ? ($data['action'] ?? 'reopen') : null;
            $c->update(['outcome' => $data['outcome'], 'action' => $action, 'decision' => $data['decision'], 'decided_at' => now()]);
            $t = Tender::find($c->tender_id);
            if ($action === 'reopen') {
                $t->awards()->delete();
                $t->update(['status' => 'opened', 'standstill_until' => null, 'decision_comment' => "Complaint upheld: {$data['decision']}"]);
            } elseif ($action === 'cancel') {
                $this->cancel($t, "Complaint upheld: {$data['decision']}");
            }
        });

        return $c->refresh();
    }

    public function cancel(Tender $t, string $reason): Tender
    {
        if (in_array($t->status, ['contracted', 'cancelled'], true) || $t->contracts()->exists()) {
            $this->fail('tender', 'A tender with a contract or already cancelled cannot be cancelled.');
        }
        $t->update(['status' => 'cancelled', 'cancel_reason' => $reason]);

        return $t->refresh();
    }

    /** Daily: end standstill periods that have run out without open complaints. */
    public function closeStandstills(): int
    {
        $n = 0;
        foreach (Tender::where('status', 'standstill')->whereDate('standstill_until', '<=', today())->get() as $t) {
            if (! $t->complaints()->where('outcome', 'pending')->exists()) {
                $this->sendLetters($t);
                $n++;
            }
        }

        return $n;
    }
}
