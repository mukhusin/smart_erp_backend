<?php

namespace Modules\Admin\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Admin\Http\Resources\AuditLogResource;

/**
 * Who created, changed or deleted employee, contract, pay and payroll records.
 *
 * @tags Admin: Audit log
 */
class AuditLogController extends Controller
{
    /**
     * List audit log entries, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'type' => ['nullable', 'string', 'max:60'], 'action' => ['nullable', 'in:created,updated,deleted'], 'record_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', 'in:created_at,-created_at'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditLog::with(['user', 'employee'])
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['record_id'] ?? null, fn ($q, $v) => $q->where('auditable_id', $v))
            ->when($f['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', now()->parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', now()->parse($v)->endOfDay()))
            ->when(trim((string) ($f['search'] ?? '')), fn ($q, $v) => $q->where(fn ($q) => $q
                ->whereIn('user_id', User::where('username', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")->select('id'))
                ->orWhereIn('employee_id', Employee::withoutGlobalScopes()->where('fname', 'like', "%{$v}%")->orWhere('lname', 'like', "%{$v}%")->orWhere('employee_no', 'like', "%{$v}%")->select('id'))
                ->orWhere('ip_address', 'like', "%{$v}%")));

        $direction = ($f['sort'] ?? '-created_at') === 'created_at' ? 'asc' : 'desc';

        return AuditLogResource::collection($query->orderBy('created_at', $direction)->orderBy('id', $direction)->paginate((int) ($f['per_page'] ?? 10)));
    }

    /**
     * Record types and actions for the filters.
     *
     * @response array{data: array{types: list<array{value: string, label: string}>, actions: list<array{value: string, label: string}>}}
     */
    public function options(): JsonResponse
    {
        $pairs = fn (array $list) => collect($list)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->sortBy('label')->values();

        return response()->json(['data' => ['types' => $pairs(AuditLog::TYPES), 'actions' => $pairs(AuditLog::ACTIONS)]]);
    }
}
