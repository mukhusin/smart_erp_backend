<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hr\Http\Requests\DocumentRequest;
use Modules\Hr\Http\Resources\DocumentResource;
use Modules\Hr\Support\EmployeeFiles;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags HR: Documents
 */
class EmployeeDocumentController extends Controller
{
    /**
     * List an employee's documents, newest first.
     */
    public function index(Employee $employee): AnonymousResourceCollection
    {
        return DocumentResource::collection($employee->documents()->with('uploadedBy.employee')->latest('id')->get());
    }

    /**
     * Upload a document.
     */
    public function store(DocumentRequest $request, Employee $employee): DocumentResource
    {
        $document = $employee->documents()->make([
            'file_type' => $request->validated('file_type'),
            'description' => $request->validated('description'),
            'uploaded_by' => $request->user()->id,
        ]);
        $this->saveFile($request, $employee, $document);
        $document->save();

        return new DocumentResource($document->load('uploadedBy.employee'));
    }

    /**
     * Update a document's type and description, or replace its file.
     *
     * Sent as multipart form data. The file is kept unless a new one is sent.
     */
    public function update(DocumentRequest $request, Employee $employee, EmployeeDocument $document): DocumentResource
    {
        $document->fill(['file_type' => $request->validated('file_type'), 'description' => $request->validated('description')]);
        $this->saveFile($request, $employee, $document);
        $document->save();

        return new DocumentResource($document->load('uploadedBy.employee'));
    }

    /**
     * Delete a document.
     */
    public function destroy(Employee $employee, EmployeeDocument $document): Response
    {
        $document->delete();
        EmployeeFiles::delete($document->file_path);

        return response()->noContent();
    }

    /**
     * Download the document.
     */
    public function file(Employee $employee, EmployeeDocument $document): StreamedResponse
    {
        return EmployeeFiles::response($document->file_path, $document->file_name);
    }

    private function saveFile(DocumentRequest $request, Employee $employee, EmployeeDocument $document): void
    {
        if ($file = $request->file('file')) {
            $document->file_path = EmployeeFiles::replace($document->file_path, $file, $employee, 'documents');
            $document->file_name = $file->getClientOriginalName();
            $document->size = $file->getSize();
            $document->mime_type = $file->getMimeType();
        }
    }
}
