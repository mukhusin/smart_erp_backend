<?php

namespace Modules\Core\Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

/** Company data must never leak between companies. */
class TenancyTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    public function test_users_only_see_their_own_company_rows(): void
    {
        [$a, $adminA] = $this->createCompany('Alpha');
        [$b, $adminB] = $this->createCompany('Beta');

        $this->actingAs($adminA);
        $this->assertSame([$a->id], Employee::pluck('company_id')->unique()->values()->all());
        $this->assertSame(['Human Resources'], Department::pluck('name')->all());
        $this->assertNull(Employee::find($adminB->employee->id), 'Beta employee must be invisible to Alpha');

        $this->actingAs($adminB);
        $this->assertSame([$b->id], Employee::pluck('company_id')->unique()->values()->all());
    }

    public function test_new_rows_get_the_current_company(): void
    {
        [$a, $adminA] = $this->createCompany('Alpha');
        $this->actingAs($adminA);

        $department = Department::create(['name' => 'Nursing']);

        $this->assertSame($a->id, $department->company_id);
    }

    public function test_same_employee_number_is_allowed_in_different_companies(): void
    {
        [, $adminA] = $this->createCompany('Alpha', ['admin' => ['employee_no' => 'ST1']]);
        [, $adminB] = $this->createCompany('Beta', ['admin' => ['employee_no' => 'ST1']]);

        $this->assertSame('ST1', $adminA->employee->employee_no);
        $this->assertSame('ST1', $adminB->employee->employee_no);
    }

    public function test_platform_admin_and_guests_see_no_company_data(): void
    {
        $this->createCompany('Alpha');

        $this->assertSame(0, Employee::count(), 'guest');

        $this->actingAs($this->createPlatformAdmin());
        $this->assertSame(0, Employee::count(), 'platform admin');
        $this->assertSame(1, Employee::withoutCompanyScope()->count(), 'explicit cross-company query');
    }

    public function test_jobs_can_work_for_one_company_explicitly(): void
    {
        [$a] = $this->createCompany('Alpha');
        [$b] = $this->createCompany('Beta');

        $names = app(CompanyContext::class)->run($b, fn () => Department::pluck('company_id')->unique()->all());

        $this->assertSame([$b->id], $names);
    }
}
