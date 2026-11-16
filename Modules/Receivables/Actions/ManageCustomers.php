<?php

namespace Modules\Receivables\Actions;

use Modules\Receivables\Models\Customer;

class ManageCustomers
{
    public function save(?Customer $customer, array $data): Customer
    {
        $customer ??= new Customer(['code' => $data['code'] ?? null ?: $this->nextCode(), 'is_active' => true]);
        $customer->fill(collect($data)->except(empty($data['code']) ? ['code'] : [])->all())->save();

        return $customer->refresh();
    }

    public function nextCode(): string
    {
        $last = Customer::where('code', 'like', 'CUS-%')->lockForUpdate()->orderByDesc('code')->value('code');

        return 'CUS-'.str_pad((string) ((int) substr((string) $last, 4) + 1), 4, '0', STR_PAD_LEFT);
    }
}
