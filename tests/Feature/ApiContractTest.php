<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiContractTest extends TestCase
{
    private const NO_INPUT = [
        'admin.handovers.revoke', 'appraisals.cycles.close', 'appraisals.withdraw', 'appraisals.cancel', 'workflow.cancel', 'attendance.sync',
        'attendance.employees.push-all', 'attendance.employees.push', 'auth.logout', 'hr.renewals.withdraw', 'hr.employees.invite', 'hr.messages.cancel',
        'hr.settings.renewal-letter.reset', 'hr.sick-sheets.cancel', 'self.sick-sheets.withdraw', 'leave.annual-rosters.publish', 'leave.absences.cancel',
        'self.advances.withdraw', 'loans.advances.cancel', 'self.overtime.withdraw', 'payroll.sheets.generate', 'payroll.sheets.submit', 'payroll.sheets.withdraw',
        'planning.annual.open', 'planning.annual.close', 'planning.department.action', 'platform.companies.resend-invite', 'roster.rosters.submit',
        'roster.rosters.withdraw', 'roster.rosters.changes.withdraw', 'self.renewals.withdraw', 'self.leave.withdraw', 'tasks.withdraw-update',
        'accounting.periods.close', 'accounting.periods.reopen', 'accounting.journals.submit', 'accounting.journals.withdraw',
        'budgeting.budgets.open', 'budgeting.budgets.submit', 'budgeting.budgets.close', 'budgeting.departments.action', 'budgeting.transfers.withdraw',
        'payments.requests.submit', 'payments.requests.withdraw', 'payments.vouchers.withdraw', 'payments.lists.withdraw',
        'receivables.invoices.issue', 'procurement.suppliers.banks.withdraw', 'procurement.suppliers.banks.disable', 'procurement.requisitions.submit', 'procurement.plans.submit', 'procurement.plans.withdraw', 'procurement.plans.revise', 'procurement.requisitions.withdraw', 'procurement.orders.submit', 'procurement.orders.withdraw', 'procurement.orders.acknowledge', 'procurement.receipts.submit', 'procurement.receipts.withdraw', 'procurement.receipts.cancel', 'inventory.requests.submit', 'inventory.requests.withdraw', 'inventory.requests.cancel', 'inventory.documents.submit', 'inventory.documents.withdraw', 'inventory.documents.cancel', 'inventory.documents.confirm', 'accounting.payroll.post', 'receivables.invoices.withdraw', 'receivables.credit-notes.withdraw',
        'accounting.opening.submit', 'accounting.opening.withdraw', 'accounting.year-end.close', 'accounting.year-end.withdraw',
        'assets.withdraw', 'assets.submit', 'assets.submit-drafts', 'assets.changes.withdraw', 'assets.transfers.withdraw', 'assets.disposals.withdraw', 'assets.transfers.return', 'self.assets.confirm',
        'procurement.tenders.submit', 'procurement.tenders.end-standstill', 'procurement.contracts.submit', 'procurement.contracts.withdraw', 'procurement.certificates.withdraw', 'procurement.variations.withdraw', 'procurement.contracts.milestones.pay',
        'audit.engagements.withdraw', 'audit.engagements.end',
        'accounting.reconciliations.auto-match', 'accounting.reconciliations.submit', 'accounting.reconciliations.withdraw', 'accounting.reconciliations.reopen',
        'payments.retirements.submit', 'payments.retirements.withdraw', 'payments.recoveries.cancel', 'payments.petty-cash.vouchers.withdraw', 'payments.petty-cash.top-ups.withdraw', 'accounting.cash-bank.feed.test', 'accounting.cash-bank.feed.sync', 'accounting.reconciliations.pull-feed', 'fleet.readings.withdraw', 'fleet.requests.withdraw', 'fleet.trips.confirm', 'fleet.fuel.withdraw', 'fleet.fuel-recharges.cancel', 'fleet.maintenance.withdraw',
    ];

    private const UPLOADS = ['hr.employees.sick-sheets.store', 'self.sick-sheets.store', 'self.profile.store', 'payments.requests.attach', 'procurement.suppliers.documents.store', 'inventory.items.import', 'procurement.rfqs.record', 'accounting.opening.import', 'assets.import', 'assets.disposals.store', 'self.assets.report', 'payments.retirements.attach', 'payments.petty-cash.vouchers.store', 'fleet.units.compliance', 'fleet.drivers.licences', 'fleet.accidents.store'];

    public function test_every_endpoint_that_takes_input_declares_its_request_body(): void
    {
        $contract = json_decode(file_get_contents(base_path('api-contract/openapi.json')), true);
        $missing = [];
        foreach ($contract['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $id = $operation['operationId'] ?? "{$method} {$path}";
                if (in_array($method, ['post', 'put', 'patch'], true) && ! isset($operation['requestBody'])
                    && ! in_array($id, self::NO_INPUT, true) && ! in_array($id, self::UPLOADS, true)) {
                    $missing[] = $id;
                }
            }
        }

        $this->assertSame([], $missing, 'These endpoints take input but the API contract has no request body, so the generated frontend client sends nothing. Validate with a FormRequest or inline $request->validate() in the controller method, then run scramble:export.');
    }
}
