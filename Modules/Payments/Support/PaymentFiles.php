<?php

namespace Modules\Payments\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Payments\Models\PaymentRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PaymentFiles
{
    public const DISK = 'local';

    public const RULES = ['required', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx,xls,docx,doc', 'max:10240'];

    public static function store(UploadedFile $file, PaymentRequest $request): string
    {
        $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension());

        return $file->storeAs("payments/{$request->company_id}/requests/{$request->id}", $name, self::DISK);
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function response(string $path, string $name): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($path), 404, 'File not found.');

        return Storage::disk(self::DISK)->response($path, $name, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
