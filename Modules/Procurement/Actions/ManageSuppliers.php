<?php

namespace Modules\Procurement\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Notifications\Notice;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierBankAccount;
use Modules\Procurement\Models\SupplierDocument;
use Modules\Procurement\Workflow\SupplierBankAccountProcess;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngine;

class ManageSuppliers
{
    public function __construct(private readonly WorkflowEngine $workflow) {}

    public function save(?Supplier $supplier, array $data, User $by): Supplier
    {
        if (! empty($data['tin'])) {
            $dup = Supplier::where('tin', $data['tin'])->when($supplier, fn ($q) => $q->where('id', '!=', $supplier->id))->first();
            if ($dup) {
                throw ValidationException::withMessages(['tin' => "{$dup->name} ({$dup->code}) already has this TIN."]);
            }
        }

        return DB::transaction(function () use ($supplier, $data, $by) {
            $supplier ??= new Supplier(['code' => ($data['code'] ?? null) ?: $this->nextCode(), 'created_by' => $by->id, 'is_active' => true]);
            $supplier->fill(collect($data)->except(empty($data['code']) ? ['code'] : [])->all())->save();

            return $supplier->refresh();
        });
    }

    public function nextCode(): string
    {
        $last = Supplier::where('code', 'like', 'SUP-%')->lockForUpdate()->orderByDesc('code')->value('code');

        return 'SUP-'.str_pad((string) ((int) substr((string) $last, 4) + 1), 4, '0', STR_PAD_LEFT);
    }

    public function blacklist(Supplier $supplier, bool $blocked, ?string $reason, ?string $until): Supplier
    {
        if ($blocked && ! $reason) {
            throw ValidationException::withMessages(['reason' => 'Give the reason for blacklisting.']);
        }
        $supplier->update(['blacklisted' => $blocked, 'blacklist_reason' => $blocked ? $reason : null, 'blacklisted_until' => $blocked ? $until : null]);

        return $supplier->refresh();
    }

    public function addBankAccount(Supplier $supplier, array $data, User $by): SupplierBankAccount
    {
        return DB::transaction(function () use ($supplier, $data, $by) {
            $account = $supplier->bankAccounts()->create($data + ['status' => 'pending', 'requested_by' => $by->id, 'currency' => $data['currency'] ?? 'TZS']);
            if (! $this->workflow->start(SupplierBankAccountProcess::KEY, $account, $by)) {
                $this->bankApproved($account);
            }

            return $account->refresh();
        });
    }

    public function bankApproved(SupplierBankAccount $account): void
    {
        DB::transaction(function () use ($account) {
            $first = ! SupplierBankAccount::where('supplier_id', $account->supplier_id)->where('status', 'approved')->exists();
            if ($account->is_default || $first) {
                SupplierBankAccount::where('supplier_id', $account->supplier_id)->where('id', '!=', $account->id)->update(['is_default' => false]);
            }
            $account->update(['status' => 'approved', 'is_default' => $account->is_default || $first, 'decided_at' => now()]);
        });
    }

    public function bankRejected(SupplierBankAccount $account, ?string $comment, bool $withdrawn): void
    {
        $account->update(['status' => $withdrawn ? 'cancelled' : 'rejected', 'decision_comment' => $withdrawn ? null : $comment, 'decided_at' => now()]);
        if (! $withdrawn) {
            $account->requester?->notify(new Notice('Supplier bank account not approved', "The {$account->bank_name} account of {$account->supplier->name} was not approved: {$comment}", "/procurement/suppliers/{$account->supplier_id}", 'bank'));
        }
    }

    public function withdrawBankAccount(SupplierBankAccount $account, User $by): void
    {
        if ($account->status !== 'pending' || $account->requested_by !== $by->id) {
            throw ValidationException::withMessages(['account' => 'Only your bank account waiting for approval can be withdrawn.']);
        }
        $instance = $account->workflow()->where('status', WorkflowInstance::PENDING)->first();
        $instance ? $this->workflow->cancel($instance, $instance->initiator ?? $by) : $this->bankRejected($account, null, true);
    }

    public function disableBankAccount(SupplierBankAccount $account): void
    {
        if ($account->status !== 'approved') {
            throw ValidationException::withMessages(['account' => 'Only an approved account can be disabled.']);
        }
        $account->update(['status' => 'disabled', 'is_default' => false]);
    }

    public function addDocument(Supplier $supplier, array $data, ?UploadedFile $file, User $by): SupplierDocument
    {
        $path = $file ? $file->storeAs("procurement/{$supplier->company_id}/suppliers/{$supplier->id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), 'local') : null;

        return $supplier->documents()->create($data + ['path' => $path, 'file_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 200) : null, 'uploaded_by' => $by->id]);
    }

    public function deleteDocument(SupplierDocument $document): void
    {
        if ($document->path) {
            Storage::disk('local')->delete($document->path);
        }
        $document->delete();
    }
}
