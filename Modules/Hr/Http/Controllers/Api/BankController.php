<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Hr\Http\Requests\BankRequest;

/**
 * Banks employees are paid into (HRMS Settings → Banks). Each company keeps its own list.
 *
 * @tags HR: Banks
 */
class BankController extends Controller
{
    /**
     * List banks with the number of employee accounts in each.
     *
     * @response array{data: list<array{id: int, name: string, code: string|null, swift_code: string|null, description: string|null, is_active: bool, accounts_count: int}>}
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Bank::withCount(['accounts' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('is_active')->orderBy('name')->get()->map(fn (Bank $b) => $this->row($b))]);
    }

    /**
     * Active banks, for employee bank account forms.
     *
     * @response array{data: list<array{id: int, name: string, code: string|null}>}
     */
    public function active(): JsonResponse
    {
        return response()->json(['data' => Bank::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code'])]);
    }

    /**
     * Add a bank.
     *
     * @response array{data: array{id: int, name: string, code: string|null, swift_code: string|null, description: string|null, is_active: bool, accounts_count: int}}
     */
    public function store(BankRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->row(Bank::create($request->validated() + ['is_active' => true]))], 201);
    }

    /**
     * Update a bank.
     *
     * @response array{data: array{id: int, name: string, code: string|null, swift_code: string|null, description: string|null, is_active: bool, accounts_count: int}}
     */
    public function update(BankRequest $request, Bank $bank): JsonResponse
    {
        $bank->update($request->validated());

        return response()->json(['data' => $this->row($bank->loadCount(['accounts' => fn ($q) => $q->where('is_active', true)]))]);
    }

    /**
     * Delete a bank. Banks with employee accounts cannot be deleted; deactivate them instead.
     */
    public function destroy(Bank $bank): Response
    {
        abort_if($bank->accounts()->exists(), 422, 'Employee accounts use this bank. Deactivate it instead.');
        $bank->delete();

        return response()->noContent();
    }

    private function row(Bank $b): array
    {
        return [
            'id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'swift_code' => $b->swift_code ? strtoupper($b->swift_code) : null,
            'description' => $b->description, 'is_active' => (bool) ($b->is_active ?? true), 'accounts_count' => (int) ($b->accounts_count ?? 0),
        ];
    }
}
