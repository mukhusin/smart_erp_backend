<?php

namespace Modules\Receivables\Support;

use Illuminate\Support\Collection;
use Modules\Receivables\Models\Invoice;

final class Ageing
{
    public const BUCKETS = ['current' => 'Not yet due', 'd30' => '1–30 days', 'd60' => '31–60 days', 'd90' => '61–90 days', 'over90' => 'Over 90 days'];

    public static function bucket(Invoice $invoice): string
    {
        $days = (int) $invoice->due_date->diffInDays(today(), false);

        return match (true) {
            $days <= 0 => 'current',
            $days <= 30 => 'd30',
            $days <= 60 => 'd60',
            $days <= 90 => 'd90',
            default => 'over90',
        };
    }

    /** @return array{current: float, d30: float, d60: float, d90: float, over90: float, total: float} */
    public static function sum(Collection $invoices): array
    {
        $out = array_fill_keys(array_keys(self::BUCKETS), 0.0);
        foreach ($invoices as $invoice) {
            $out[self::bucket($invoice)] += $invoice->balance();
        }
        $out = array_map(fn ($v) => round($v, 2), $out);
        $out['total'] = round(array_sum($out), 2);

        return $out;
    }
}
