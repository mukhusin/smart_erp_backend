<?php

namespace Modules\Accounting\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class VatSetting extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['company_id', 'vat_registered', 'vat_number', 'output_account_id', 'input_account_id'];

    protected $casts = ['vat_registered' => 'boolean'];

    public static function current(): self
    {
        return static::firstOrNew([], ['vat_registered' => false]);
    }

    public static function registered(): bool
    {
        return (bool) static::current()->vat_registered;
    }

    /** The settings when VAT is used; throws when VAT is on but its accounts are not chosen. */
    public static function ready(string $which): ?self
    {
        $s = static::current();
        if (! $s->vat_registered) {
            return null;
        }
        if (! $s->{$which.'_account_id'}) {
            throw ValidationException::withMessages(['vat' => 'Choose the VAT '.$which.' account in Accounting → VAT first.']);
        }

        return $s;
    }
}
