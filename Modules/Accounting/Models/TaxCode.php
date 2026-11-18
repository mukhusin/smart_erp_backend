<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxCode extends Model
{
    use Auditable, BelongsToCompany;

    public const KINDS = ['standard' => 'Standard rated', 'zero' => 'Zero rated', 'exempt' => 'Exempt'];

    public const DEFAULTS = [
        ['code' => 'S', 'name' => 'Standard rate', 'kind' => 'standard', 'rate' => 18],
        ['code' => 'Z', 'name' => 'Zero rated', 'kind' => 'zero', 'rate' => 0],
        ['code' => 'E', 'name' => 'Exempt', 'kind' => 'exempt', 'rate' => 0],
    ];

    protected $fillable = ['company_id', 'code', 'name', 'kind', 'rate', 'is_active'];

    protected $casts = ['rate' => 'float', 'is_active' => 'boolean'];

    public static function ensureDefaults(): void
    {
        if (static::query()->exists()) {
            return;
        }
        foreach (self::DEFAULTS as $d) {
            static::create($d + ['is_active' => true]);
        }
    }
}
