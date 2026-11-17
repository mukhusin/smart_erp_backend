<?php

namespace Modules\Procurement\Models;

use Modules\Payments\Models\PaymentRequest;

final class PaymentRequestIds
{
    /** Payment requests raised for a contract: certificates, milestones (advance, retention) and its orders' deliveries. @return list<int> */
    public static function forContract(Contract $c): array
    {
        $grns = GoodsReceipt::whereIn('purchase_order_id', $c->orders()->pluck('id'))->pluck('payment_request_id')->filter()->all();

        return array_values(array_unique(array_merge(
            ContractCertificate::where('contract_id', $c->id)->whereNotNull('payment_request_id')->pluck('payment_request_id')->all(),
            ContractMilestone::where('contract_id', $c->id)->whereNotNull('payment_request_id')->pluck('payment_request_id')->all(),
            $grns,
        )));
    }
}
