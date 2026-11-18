<?php

namespace Modules\Workflow\Support;

/** What each process does by itself on each outcome; shown read-only next to the configurable actions. */
final class ProcessEffects
{
    private const MAP = [
        'payment-request' => ['started' => 'The amount is held on each budget line.', 'approved' => 'The held amount becomes committed; finance can prepare the voucher.', 'rejected' => 'The hold on the budget is released.'],
        'payment-voucher' => ['approved' => 'The expense is posted to the ledger against the budget lines (commitment becomes spent); payees become payable.', 'rejected' => 'Nothing is posted; the request goes back to approved.'],
        'payment-list' => ['approved' => 'Payables are settled from the bank in the ledger; the vouchers become paid.', 'rejected' => 'Nothing is paid.'],
        'payment-void' => ['approved' => 'The money is returned to the bank, the expense reversed and the budget committed again.', 'rejected' => 'The voucher stays paid.'],
        'imprest-retirement' => ['approved' => 'Spending is charged to the budget lines, returned money goes back to the bank, staff advances are cleared and the rest released.', 'rejected' => 'The retirement goes back to the holder.'],
        'petty-cash-voucher' => ['started' => 'The amount is held on the budget line.', 'approved' => 'The expense is posted and the cash box goes down.', 'rejected' => 'The hold is released.'],
        'petty-cash-replenishment' => ['approved' => 'Money moves from the bank to petty cash in the ledger.', 'rejected' => 'Nothing moves.'],
        'purchase-requisition' => ['started' => 'The amount is held on the budget lines.', 'approved' => 'Procurement can source it.', 'rejected' => 'The hold is released.'],
        'procurement-plan' => ['approved' => 'The plan becomes the approved plan; requisitions are checked against it.', 'rejected' => 'The plan goes back for changes.'],
        'purchase-order' => ['approved' => 'The commitment moves from the requisition to the order; the supplier can deliver.', 'rejected' => 'The requisition keeps its hold.'],
        'goods-receipt' => ['approved' => 'Goods are taken into stock or expense and the supplier becomes payable.', 'rejected' => 'Nothing is received.'],
        'department-budget' => ['approved' => 'The department budget goes forward for the organisation budget.'],
        'budget-approval' => ['approved' => 'The budget becomes the approved budget; its lines can be spent.'],
        'budget-transfer' => ['started' => 'The amount is held on the giving line.', 'approved' => 'The amount moves to the receiving line.', 'rejected' => 'The hold is released.'],
        'budget-release' => ['approved' => 'The quarter money is released and can be spent.'],
        'manual-journal' => ['approved' => 'The journal is posted to the ledger.', 'rejected' => 'Nothing is posted.'],
        'opening-balance' => ['approved' => 'The opening balances are posted.'],
        'year-end-close' => ['approved' => 'Income and expenses close to retained earnings and the year is locked.'],
        'bank-reconciliation' => ['approved' => 'The reconciliation is completed and its cleared items are locked.'],
        'payroll-sheet' => ['approved' => 'The payroll is final and is posted to the ledger when posting is on.'],
        'leave-request' => ['approved' => 'The leave days are taken from the balance.', 'rejected' => 'The balance is unchanged.'],
        'asset-disposal' => ['approved' => 'The asset leaves the register and the gain or loss is posted.'],
        'store-requisition' => ['approved' => 'The store can issue the items.'],
        'fuel-request' => ['approved' => 'The litres are reserved on the tank until issued.'],
        'maintenance-inspection' => ['approved' => 'A purchase requisition for the parts and work goes to procurement.'],
        'transport-request' => ['approved' => 'The transport office is told to allocate a vehicle.'],
        'fleet-reading-correction' => ['approved' => 'The odometer or hours are set to the corrected value.'],
    ];

    /** @return array{started: string|null, approved: string, rejected: string} */
    public static function for(string $process): array
    {
        $e = self::MAP[$process] ?? [];

        return ['started' => $e['started'] ?? null, 'approved' => $e['approved'] ?? 'The change asked for is applied.', 'rejected' => $e['rejected'] ?? 'Nothing changes; the requester is told.'];
    }
}
