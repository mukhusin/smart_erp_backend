<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class BankTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr']]]);
        setPermissionsTeamId($this->company->id);
        $this->actingAs($this->admin);
    }

    public function test_banks_are_per_company(): void
    {
        $crdb = $this->postJson('/api/v1/hr/banks', ['name' => 'CRDB Bank', 'code' => 'CRDB'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/hr/banks', ['name' => 'CRDB Bank'])->assertUnprocessable()->assertJsonValidationErrors(['name' => 'already on the list']);
        $this->postJson('/api/v1/hr/banks', ['name' => 'NMB', 'swift_code' => 'BAD'])->assertUnprocessable()->assertJsonValidationErrors('swift_code');

        [, $otherAdmin] = $this->createCompany('Beta', ['modules' => [['slug' => 'hr']]]);
        $this->actingAs($otherAdmin)->postJson('/api/v1/hr/banks', ['name' => 'CRDB Bank', 'code' => 'CRDB'])->assertCreated();
        $this->getJson('/api/v1/hr/banks')->assertJsonCount(1, 'data');
        $this->putJson("/api/v1/hr/banks/{$crdb}", ['name' => 'Hacked'])->assertNotFound();
    }

    public function test_employee_bank_account(): void
    {
        $crdb = $this->postJson('/api/v1/hr/banks', ['name' => 'CRDB Bank'])->json('data.id');
        $nmb = $this->postJson('/api/v1/hr/banks', ['name' => 'NMB Bank'])->json('data.id');
        $employee = Employee::withoutCompanyScope()->where('company_id', $this->company->id)->first();
        $other = Employee::withoutCompanyScope()->create(['company_id' => $this->company->id, 'employee_no' => 'E2', 'fname' => 'Juma', 'lname' => 'Ali', 'gender' => 'Male']);

        $this->putJson("/api/v1/hr/employees/{$employee->id}/bank-account", ['bank_id' => $crdb, 'branch' => 'Kariakoo', 'account_number' => '0150 1234 5678'])
            ->assertOk()->assertJsonPath('data.bank_account.bank.name', 'CRDB Bank')->assertJsonPath('data.bank_account.account_number', '015012345678')
            ->assertJsonPath('data.bank_account.account_name', $employee->full_name);

        $this->putJson("/api/v1/hr/employees/{$other->id}/bank-account", ['bank_id' => $crdb, 'account_number' => '015012345678'])
            ->assertUnprocessable()->assertJsonValidationErrors(['account_number' => 'belongs to']);
        $this->putJson("/api/v1/hr/employees/{$other->id}/bank-account", ['bank_id' => $nmb, 'account_number' => '015012345678'])->assertOk();

        $this->putJson("/api/v1/hr/employees/{$employee->id}/bank-account", ['bank_id' => $nmb, 'account_number' => '2222'])->assertOk()->assertJsonPath('data.bank_account.bank.name', 'NMB Bank');
        $this->assertSame(2, $employee->bankAccounts()->count());
        $this->assertSame(1, $employee->bankAccounts()->where('is_active', true)->count());

        $this->deleteJson("/api/v1/hr/banks/{$crdb}")->assertStatus(422);
        $this->getJson('/api/v1/hr/banks/active')->assertJsonCount(2, 'data');
    }
}
