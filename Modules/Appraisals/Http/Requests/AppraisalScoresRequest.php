<?php

namespace Modules\Appraisals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AppraisalScoresRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'scores' => ['nullable', 'array'],
            'scores.tasks' => ['nullable', 'numeric'],
            'scores.attendance' => ['nullable', 'numeric'],
            /** Competency id => score. */
            'scores.competencies' => ['nullable', 'array'],
            'scores.competencies.*' => ['nullable', 'numeric'],
            /** Part key (tasks, attendance) => comment. */
            'scores.comments' => ['nullable', 'array'],
            'scores.comments.*' => ['nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:3000'],
            /** Self-assessment: send it for review. */
            'submit' => [$this->routeIs('appraisals.self') ? 'required' : 'prohibited', 'boolean'],
            /** Review: approve or return to the employee. */
            'decision' => [$this->routeIs('appraisals.review') ? 'required' : 'prohibited', 'in:approve,return'],
        ];
    }
}
