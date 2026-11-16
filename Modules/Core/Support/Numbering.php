<?php

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class Numbering
{
    /** @param class-string<Model> $model */
    public static function next(string $model, string $prefix, ?string $date = null): string
    {
        $start = $prefix.'-'.Carbon::parse($date ?? now())->format('Y').'-';
        $last = $model::where('number', 'like', $start.'%')->lockForUpdate()->orderByDesc('number')->value('number');

        return $start.str_pad((string) ((int) substr((string) $last, strlen($start)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
