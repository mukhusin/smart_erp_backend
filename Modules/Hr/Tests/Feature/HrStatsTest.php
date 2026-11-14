<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class HrStatsTest extends TestCase
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

    public function test_every_dashboard_number_lists_its_records_and_downloads(): void
    {
        $admin = Employee::withoutCompanyScope()->where('company_id', $this->company->id)->first();
        $stats = ['employees', 'probation', 'confirmed', 'female', 'male', 'without_account', 'invited', 'without_contract', 'contracts_ending',
            'licences_expiring', 'placements_active', 'placements_ending', 'placements_upcoming', 'placements_internship', 'placements_residency',
            'renewals_pending', 'renewals_approved', 'renewals_renewed', 'renewals_rejected', 'departments', 'sections', 'departments_staffed',
            'positions', 'positions_filled'];

        foreach ($stats as $stat) {
            $this->getJson("/api/v1/hr/stats/{$stat}")->assertOk()->assertJsonPath('data.key', $stat)->assertJsonStructure(['data' => ['title', 'subtitle', 'columns', 'rows', 'total']]);
        }

        $this->getJson('/api/v1/hr/stats/type?value=full_time')->assertOk();
        $this->getJson("/api/v1/hr/stats/department?value={$admin->department_id}")->assertOk();
        $this->getJson("/api/v1/hr/stats/position?value={$admin->job_position_id}")->assertOk();
        $this->getJson('/api/v1/hr/stats/type?value=nope')->assertNotFound();
        $this->getJson('/api/v1/hr/stats/nope')->assertNotFound();

        $this->assertGreaterThan(0, $this->getJson('/api/v1/hr/stats/departments')->json('data.total'));
        $this->assertContains($admin->id, array_column($this->getJson('/api/v1/hr/stats/without_contract')->json('data.rows'), 'employee_id'));
        $this->get('/api/v1/hr/stats/departments/csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $pdf = $this->get('/api/v1/hr/stats/departments/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline;', $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        if ($out = getenv('PDF_SAMPLE')) {
            file_put_contents($out, $this->get('/api/v1/hr/stats/without_contract/pdf')->getContent());
        }
        $this->getJson('/api/v1/hr/summary')->assertOk()->assertJsonStructure(['data' => ['employees' => ['by_type'], 'departments' => ['top'], 'positions' => ['top']]]);
    }
}
