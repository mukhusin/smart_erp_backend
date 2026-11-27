<?php

namespace Modules\Core\Support;

use App\Services\CompanyContext;
use Modules\Receivables\Models\ReceivableSetting;

final class CompanyFeatures
{
    /** @return list<string> */
    public static function for(?int $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return app(CompanyContext::class)->run($companyId, fn () => array_values(array_filter([
            ReceivableSetting::current()->claims_enabled ? 'receivables.claims' : null,
            \Modules\Accounting\Models\VatSetting::registered() ? 'vat' : null,
            \App\Models\GratuitySetting::current()->enabled ? 'gratuity' : null,
            \Modules\Accounting\Models\AccountingSetting::current()->expense_classes ? 'expense-classes' : null,
        ])));
    }
}
