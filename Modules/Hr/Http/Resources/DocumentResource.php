<?php

namespace Modules\Hr\Http\Resources;

use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hr\Support\EmployeeOptions as O;

/**
 * @mixin EmployeeDocument
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** approved, pending (added by the employee, waiting for HR) or rejected */
            'approval_status' => $this->getAttribute('status') ?? 'approved',
            'decision_comment' => $this->decision_comment,
            'file_type' => $this->file_type,
            'file_type_label' => O::DOCUMENT_TYPES[$this->file_type] ?? $this->file_type,
            'description' => $this->description,
            'name' => $this->file_name,
            'url' => route('hr.employees.documents.file', [$this->employee_id, $this->id], false),
            /** Bytes. */
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            'uploaded_by' => $this->uploadedBy?->display_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
