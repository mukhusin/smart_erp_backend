<?php

namespace Modules\Procurement\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\ItemCategory;
use Modules\Procurement\Actions\ManageSuppliers;
use Modules\Procurement\Http\Requests\BlacklistRequest;
use Modules\Procurement\Http\Requests\SupplierBankAccountRequest;
use Modules\Procurement\Http\Requests\SupplierDocumentRequest;
use Modules\Procurement\Http\Requests\SupplierRequest;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierBankAccount;
use Modules\Procurement\Models\SupplierDocument;
use Modules\Procurement\Support\ProcurementAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Procurement: suppliers
 */
class SupplierController extends Controller
{
    public function __construct(private readonly ProcurementAccess $access, private readonly ManageSuppliers $suppliers) {}

    /**
     * The supplier register.
     *
     * @response array{data: list<array{id: int, code: string, name: string, kind: string, kind_label: string, tin: string|null, phone: string|null, email: string|null, contact_person: string|null, special_group: string|null, is_active: bool, blacklisted: bool, has_bank: bool, expired_documents: int, expiring_documents: int}>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}}
     */
    #[QueryParameter('search', description: 'Code, name, TIN, phone or contact.', type: 'string')]
    #[QueryParameter('status', description: '`active`, `inactive`, `blacklisted`, `documents` (expired or expiring documents).', type: 'string')]
    #[QueryParameter('category_id', type: 'integer')]
    #[QueryParameter('sort', description: '`name`, `code`; prefix `-` for descending.', type: 'string', default: 'name')]
    #[QueryParameter('page', type: 'integer', default: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 10)]
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->seesSuppliers($request->user()), 403);
        $soon = today()->addDays(30)->toDateString();
        $sortParam = (string) $request->query('sort', 'name');
        $sort = in_array(ltrim($sortParam, '-'), ['name', 'code'], true) ? ltrim($sortParam, '-') : 'name';
        $page = Supplier::query()
            ->withCount(['bankAccounts as approved_banks' => fn ($q) => $q->where('status', 'approved'),
                'documents as expired_documents' => fn ($q) => $q->whereDate('expires_on', '<', today()),
                'documents as expiring_documents' => fn ($q) => $q->whereDate('expires_on', '>=', today())->whereDate('expires_on', '<=', $soon)])
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")->orWhere('tin', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")->orWhere('contact_person', 'like', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $s) => match ($s) {
                'active' => $q->where('is_active', true)->where('blacklisted', false),
                'inactive' => $q->where('is_active', false),
                'blacklisted' => $q->where('blacklisted', true),
                'documents' => $q->whereHas('documents', fn ($d) => $d->whereDate('expires_on', '<=', $soon)),
                default => $q,
            })
            ->when($request->query('category_id'), fn ($q, $c) => $q->whereJsonContains('categories', (int) $c))
            ->orderBy($sort, str_starts_with($sortParam, '-') ? 'desc' : 'asc')->orderBy('id')
            ->paginate(min((int) $request->query('per_page', 10), 200));

        return response()->json([
            'data' => collect($page->items())->map(fn (Supplier $s) => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'kind' => $s->kind, 'kind_label' => Supplier::KINDS[$s->kind] ?? $s->kind, 'tin' => $s->tin, 'phone' => $s->phone,
                'email' => $s->email, 'contact_person' => $s->contact_person, 'special_group' => $s->special_group ? (Supplier::SPECIAL_GROUPS[$s->special_group] ?? $s->special_group) : null,
                'is_active' => (bool) $s->is_active, 'blacklisted' => $s->isBlocked(), 'has_bank' => $s->approved_banks > 0,
                'expired_documents' => (int) $s->expired_documents, 'expiring_documents' => (int) $s->expiring_documents,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * A supplier with bank accounts and documents.
     *
     * @response array{data: array{id: int, code: string, name: string, kind: string, kind_label: string, tin: string|null, vrn: string|null, registration_number: string|null, phone: string|null, email: string|null, address: string|null, contact_person: string|null, special_group: string|null, special_group_label: string|null, categories: list<int>, category_names: list<string>, payment_terms_days: int|null, is_active: bool, blacklisted: bool, blacklist_reason: string|null, blacklisted_until: string|null, bank_accounts: list<array{id: int, bank_name: string, branch: string|null, account_name: string, account_number: string, swift_code: string|null, currency: string, is_default: bool, status: string, status_label: string, decision_comment: string|null, can_withdraw: bool}>, documents: list<array{id: int, type: string, type_label: string, number: string|null, issued_on: string|null, expires_on: string|null, state: string, file_name: string|null}>, can: array{edit: bool}}}
     */
    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless($this->access->seesSuppliers($request->user()), 403);
        $s = $supplier->load(['bankAccounts', 'documents']);
        $user = $request->user();

        return response()->json(['data' => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'kind' => $s->kind, 'kind_label' => Supplier::KINDS[$s->kind] ?? $s->kind, 'tin' => $s->tin, 'vrn' => $s->vrn,
            'registration_number' => $s->registration_number, 'phone' => $s->phone, 'email' => $s->email, 'address' => $s->address, 'contact_person' => $s->contact_person,
            'special_group' => $s->special_group, 'special_group_label' => $s->special_group ? (Supplier::SPECIAL_GROUPS[$s->special_group] ?? null) : null,
            'categories' => array_map('intval', $s->categories ?? []), 'category_names' => ItemCategory::whereIn('id', $s->categories ?? [])->orderBy('name')->pluck('name')->all(),
            'payment_terms_days' => $s->payment_terms_days, 'is_active' => (bool) $s->is_active, 'blacklisted' => $s->isBlocked(), 'blacklist_reason' => $s->blacklist_reason,
            'blacklisted_until' => $s->blacklisted_until?->toDateString(),
            'bank_accounts' => $s->bankAccounts->map(fn (SupplierBankAccount $b) => ['id' => $b->id, 'bank_name' => $b->bank_name, 'branch' => $b->branch, 'account_name' => $b->account_name,
                'account_number' => $b->account_number, 'swift_code' => $b->swift_code, 'currency' => $b->currency, 'is_default' => (bool) $b->is_default, 'status' => $b->status,
                'status_label' => SupplierBankAccount::STATUS_LABELS[$b->status] ?? $b->status, 'decision_comment' => $b->decision_comment,
                'can_withdraw' => $b->status === 'pending' && $b->requested_by === $user->id])->values(),
            'documents' => $s->documents->map(fn (SupplierDocument $d) => ['id' => $d->id, 'type' => $d->type, 'type_label' => SupplierDocument::TYPES[$d->type] ?? $d->type, 'number' => $d->number,
                'issued_on' => $d->issued_on?->toDateString(), 'expires_on' => $d->expires_on?->toDateString(), 'state' => $d->state(), 'file_name' => $d->file_name])->values(),
            'can' => ['edit' => $this->access->can($user, 'suppliers')],
        ]]);
    }

    /** @response array{data: array{id: int, code: string, name: string}} */
    public function store(SupplierRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'suppliers'), 403);
        $s = $this->suppliers->save(null, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name]], 201);
    }

    /** @response array{data: array{id: int, code: string, name: string}} */
    public function update(SupplierRequest $request, Supplier $supplier): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'suppliers'), 403);
        $s = $this->suppliers->save($supplier, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name]]);
    }

    /** @response array{data: array{id: int, blacklisted: bool}} */
    public function blacklist(BlacklistRequest $request, Supplier $supplier): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'manage'), 403, 'Blacklisting is done by the head of procurement.');
        $d = $request->validated();
        $s = $this->suppliers->blacklist($supplier, (bool) $d['blacklisted'], $d['reason'] ?? null, $d['until'] ?? null);

        return response()->json(['data' => ['id' => $s->id, 'blacklisted' => $s->isBlocked()]]);
    }

    /** @response array{data: array{id: int, status: string}} */
    public function storeBank(SupplierBankAccountRequest $request, Supplier $supplier): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'suppliers'), 403);
        $b = $this->suppliers->addBankAccount($supplier, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $b->id, 'status' => $b->status]], 201);
    }

    public function withdrawBank(Request $request, Supplier $supplier, SupplierBankAccount $account): JsonResponse
    {
        abort_unless($account->supplier_id === $supplier->id, 404);
        $this->suppliers->withdrawBankAccount($account, $request->user());

        return response()->json(['message' => 'Bank account withdrawn.']);
    }

    public function disableBank(Request $request, Supplier $supplier, SupplierBankAccount $account): JsonResponse
    {
        abort_unless($account->supplier_id === $supplier->id, 404);
        abort_unless($this->access->can($request->user(), 'suppliers'), 403);
        $this->suppliers->disableBankAccount($account);

        return response()->json(['message' => 'Bank account disabled.']);
    }

    /** @response array{data: array{id: int}} */
    public function storeDocument(SupplierDocumentRequest $request, Supplier $supplier): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'suppliers'), 403);
        $d = $this->suppliers->addDocument($supplier, collect($request->validated())->except('file')->all(), $request->file('file'), $request->user());

        return response()->json(['data' => ['id' => $d->id]], 201);
    }

    public function destroyDocument(Request $request, Supplier $supplier, SupplierDocument $document): JsonResponse
    {
        abort_unless($document->supplier_id === $supplier->id, 404);
        abort_unless($this->access->can($request->user(), 'suppliers'), 403);
        $this->suppliers->deleteDocument($document);

        return response()->json(['message' => 'Document removed.']);
    }

    public function file(Request $request, Supplier $supplier, SupplierDocument $document): StreamedResponse
    {
        abort_unless($document->supplier_id === $supplier->id && $document->path && Storage::disk('local')->exists($document->path), 404);
        abort_unless($this->access->seesSuppliers($request->user()), 403);

        return Storage::disk('local')->response($document->path, $document->file_name ?? 'document', ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Choice lists for supplier forms.
     *
     * @response array{data: array{kinds: list<array{value: string, label: string}>, groups: list<array{value: string, label: string}>, document_types: list<array{value: string, label: string}>, categories: list<array{id: int, name: string}>}}
     */
    public function lookups(Request $request): JsonResponse
    {
        abort_unless($this->access->seesSuppliers($request->user()), 403);
        $opt = fn (array $a) => collect($a)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values();

        return response()->json(['data' => [
            'kinds' => $opt(Supplier::KINDS), 'groups' => $opt(Supplier::SPECIAL_GROUPS), 'document_types' => $opt(SupplierDocument::TYPES),
            'categories' => ItemCategory::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
        ]]);
    }
}
