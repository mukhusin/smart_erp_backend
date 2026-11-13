<?php

namespace Modules\Hr\Http\Requests;

class StaffMessagePreviewRequest extends StaffMessageRequest
{
    public function rules(): array
    {
        return collect(parent::rules())->except(['subject', 'message', 'scheduled_at'])->all();
    }
}
