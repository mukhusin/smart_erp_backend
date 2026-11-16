<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Payments\Support\PaymentFiles;

class AttachmentRequest extends FormRequest
{
    public function rules(): array
    {
        return ['file' => PaymentFiles::RULES];
    }
}
