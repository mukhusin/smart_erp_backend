<?php

namespace Modules\Fleet\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FleetFiles
{
    public const DISK = 'local';

    public const RULES = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];

    public static function store(UploadedFile $file, int $companyId, string $folder): string
    {
        return $file->storeAs("fleet/{$companyId}/{$folder}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), self::DISK);
    }

    public static function response(string $path, string $name): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($path), 404, 'File not found.');

        return Storage::disk(self::DISK)->response($path, $name, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
