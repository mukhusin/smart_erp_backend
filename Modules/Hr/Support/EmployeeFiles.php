<?php

namespace Modules\Hr\Support;

use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Employee files on the private disk, served only through authenticated HR endpoints. */
final class EmployeeFiles
{
    public const DISK = 'local';

    public const DOCUMENT_RULES = ['mimes:pdf,jpg,jpeg,png', 'max:5120'];

    public static function store(UploadedFile $file, Employee $employee, string $folder): string
    {
        $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension());

        return $file->storeAs("hr/{$employee->company_id}/employees/{$employee->id}/{$folder}", $name, self::DISK);
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function replace(?string $old, UploadedFile $file, Employee $employee, string $folder): string
    {
        $path = self::store($file, $employee, $folder);
        self::delete($old);

        return $path;
    }

    public static function response(?string $path, ?string $name = null): StreamedResponse
    {
        abort_unless($path && Storage::disk(self::DISK)->exists($path), 404, 'File not found.');

        return Storage::disk(self::DISK)->response($path, $name ?? basename($path), [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{name: string, url: string}|null */
    public static function link(?string $path, ?string $name, string $url): ?array
    {
        return $path ? ['name' => $name ?: basename($path), 'url' => $url] : null;
    }
}
