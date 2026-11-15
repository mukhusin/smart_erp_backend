<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\CompanyContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Records every create, change and delete of the model in audit_logs (old/new values, user, IP). */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->audit('created', null, $model->auditValues($model->getAttributes())));

        static::updated(function ($model) {
            $new = $model->auditValues($model->getChanges());
            if ($new) {
                $model->audit('updated', array_intersect_key($model->auditValues($model->getRawOriginal()), $new), $new);
            }
        });

        static::deleted(fn ($model) => $model->audit('deleted', $model->auditValues($model->getRawOriginal()), null));
    }

    public function auditType(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    public function auditEmployeeId(): ?int
    {
        return $this->getAttribute('employee_id');
    }

    protected function auditValues(array $values): array
    {
        $skip = array_merge(AuditLog::IGNORED, $this->getHidden(), $this->auditIgnore ?? []);

        return array_diff_key($values, array_flip($skip));
    }

    protected function audit(string $action, ?array $old, ?array $new): void
    {
        if (AuditLog::paused() || ! in_array($action, $this->auditEvents ?? ['created', 'updated', 'deleted'], true)) {
            return;
        }
        try {
            $employeeId = $this->auditEmployeeId();
            $company = $this->getAttribute('company_id') ?? app(CompanyContext::class)->id()
                ?? ($employeeId ? Employee::withoutGlobalScopes()->whereKey($employeeId)->value('company_id') : null);
            $request = request()->route() ? request() : null;

            AuditLog::create([
                'company_id' => $company,
                'auditable_type' => $this->auditType(),
                'auditable_id' => $this->getKey(),
                'action' => $action,
                'old_values' => $old ?: null,
                'new_values' => $new ?: null,
                'employee_id' => $employeeId,
                'user_id' => Auth::id(),
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'url' => $request ? Str::limit($request->method().' '.$request->path(), 500, '') : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit log write failed: '.$e->getMessage());
        }
    }
}
