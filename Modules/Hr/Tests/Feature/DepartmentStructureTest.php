<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Employee;
use App\Models\HeadOfDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AccessChange;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class DepartmentStructureTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'admin']]]);
        setPermissionsTeamId($this->company->id);
        $this->actingAs($this->admin);
    }

    private function staff(string $name, int $department, ?int $section = null): Employee
    {
        $employee = Employee::withoutCompanyScope()->create([
            'company_id' => $this->company->id, 'employee_no' => strtoupper($name), 'fname' => $name, 'lname' => 'Test', 'gender' => 'Male',
            'department_id' => $department, 'section_id' => $section, 'employment_status' => 'confirmed',
        ]);
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->assignRole('employee');
        $employee->update(['user_id' => $user->id]);

        return $employee;
    }

    public function test_departments_sections_and_heads(): void
    {
        $nursing = $this->postJson('/api/v1/hr/structure/departments', ['name' => 'Nursing', 'short_name' => 'NRS'])->assertCreated()->json('data.id');
        $ward = $this->postJson('/api/v1/hr/structure/departments', ['name' => 'Ward 1', 'parent_id' => $nursing])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/hr/structure/departments', ['name' => 'Bed 1', 'parent_id' => $ward])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $first = $this->staff('first', $nursing);
        $second = $this->staff('second', $nursing, $ward);

        $this->postJson("/api/v1/hr/structure/departments/{$nursing}/head", ['employee_id' => $first->id, 'reason' => 'New department'])
            ->assertOk()->assertJsonPath('data.head.name', 'first Test')->assertJsonPath('meta.warnings', []);
        $this->assertTrue($first->user->refresh()->hasRole('hod'));
        $this->assertSame(AccessChange::APPLIED, AccessChange::where('user_id', $first->user_id)->latest('id')->value('status'));

        $this->postJson("/api/v1/hr/structure/departments/{$nursing}/head", ['employee_id' => $second->id, 'reason' => 'Rotation'])->assertOk();
        $this->assertFalse($first->user->refresh()->hasRole('hod'));
        $this->assertTrue($second->user->refresh()->hasRole('hod'));
        $this->getJson("/api/v1/hr/structure/departments/{$nursing}")->assertOk()
            ->assertJsonCount(2, 'data.head_history')
            ->assertJsonPath('data.head_history.0.active', true)
            ->assertJsonPath('data.head_history.1.reason', 'Rotation');

        $this->postJson("/api/v1/hr/structure/departments/{$ward}/head", ['employee_id' => $second->id])->assertOk();
        $this->assertTrue($second->user->refresh()->hasRole('section-head'));
        $this->assertSame(HeadOfDepartment::SECTION, HeadOfDepartment::where('department_id', $ward)->value('level'));

        $this->getJson('/api/v1/hr/structure/departments')->assertOk()
            ->assertJsonPath('data.0.name', 'Human Resources')
            ->assertJsonFragment(['name' => 'Nursing', 'staff_count' => 2])
            ->assertJsonFragment(['name' => 'Ward 1', 'staff_count' => 1]);

        $this->postJson("/api/v1/hr/structure/departments/{$ward}/head/remove", ['reason' => 'Left the ward'])->assertOk();
        $this->assertFalse($second->user->refresh()->hasRole('section-head'));
        $this->assertTrue($second->user->hasRole('hod'));

        $this->deleteJson("/api/v1/hr/structure/departments/{$ward}")->assertStatus(422);
        $empty = $this->postJson('/api/v1/hr/structure/departments', ['name' => 'Empty'])->json('data.id');
        $this->deleteJson("/api/v1/hr/structure/departments/{$empty}")->assertNoContent();
    }

    public function test_head_sees_department_staff_and_needs_permission_to_manage(): void
    {
        $nursing = $this->postJson('/api/v1/hr/structure/departments', ['name' => 'Nursing'])->json('data.id');
        $head = $this->staff('head', $nursing);
        $this->staff('nurse', $nursing);
        $this->postJson("/api/v1/hr/structure/departments/{$nursing}/head", ['employee_id' => $head->id])->assertOk();

        $this->actingAs($head->user->refresh())->getJson('/api/v1/hr/employees')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/hr/structure/departments')->assertOk();
        $this->postJson('/api/v1/hr/structure/departments', ['name' => 'X'])->assertForbidden();
    }
}
