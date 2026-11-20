<?php

namespace Modules\Hr\Tests\Feature;

use App\Models\DailyAllowance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeTimetable;
use App\Models\JobPosition;
use App\Models\LeaveType;
use App\Models\NatureOfContract;
use App\Models\RosterCode;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Hr\Actions\ManageLeave;
use Modules\Workflow\Models\WorkflowTemplate;
use Tests\Support\CreatesCompanies;
use Tests\TestCase;

class AllowanceTest extends TestCase
{
    use CreatesCompanies, RefreshDatabase;

    private $company;

    private User $admin;

    private User $user;

    private Department $ward;

    private Employee $nurse;

    private Employee $intern;

    private int $nursePosition;

    /** @var array<string, int> */
    private array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 09:00:00');
        [$this->company, $this->admin] = $this->createCompany('Alpha', ['modules' => [['slug' => 'hr'], ['slug' => 'roster'], ['slug' => 'leave'], ['slug' => 'allowance'], ['slug' => 'self-service']]]);
        setPermissionsTeamId($this->company->id);
        $this->inCompany(function () {
            $this->ward = Department::create(['name' => 'Ward']);
            $this->nursePosition = JobPosition::create(['title' => 'Nurse', 'department_id' => $this->ward->id, 'is_active' => true])->id;
            $stc = NatureOfContract::create(['contract_name' => 'Specific time', 'code' => 'STC', 'employment_type' => 'full_time', 'has_allowance' => true, 'has_annual_leave' => true]);
            $int = NatureOfContract::create(['contract_name' => 'Internship', 'code' => 'INT', 'employment_type' => 'internship', 'has_allowance' => false]);
            $this->user = User::factory()->create(['company_id' => $this->company->id]);
            $this->user->assignRole('employee');
            $make = function (string $no, NatureOfContract $type, ?int $position, ?int $userId = null) {
                $e = Employee::create(['employee_no' => $no, 'fname' => $no, 'lname' => 'X', 'gender' => 'Female', 'department_id' => $this->ward->id, 'job_position_id' => $position,
                    'user_id' => $userId, 'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'has_annual_leave' => true, 'employment_date' => '2020-01-01']);
                EmployeeContract::create(['employee_id' => $e->id, 'nature_of_contract_id' => $type->id, 'contract_no' => $no, 'basic_salary' => 900000, 'period' => 12,
                    'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active', 'is_active' => true]);

                return $e;
            };
            $this->nurse = $make('Nurse', $stc, $this->nursePosition, $this->user->id);
            $this->intern = $make('Intern', $int, $this->nursePosition);
            $this->codes['N'] = RosterCode::create(['code' => 'N', 'duty' => 'Night', 'can_punch' => true, 'start_time' => '20:00', 'end_time' => '08:00', 'pass_day' => true, 'work_hour' => 12])->id;
            $this->codes['M'] = RosterCode::create(['code' => 'M', 'duty' => 'Morning', 'can_punch' => true, 'start_time' => '07:30', 'end_time' => '15:30', 'work_hour' => 8])->id;
            $this->codes['O'] = RosterCode::create(['code' => 'O', 'duty' => 'Off', 'can_punch' => false])->id;
            WorkflowTemplate::where('process', 'leave-request')->delete();
        });
    }

    private function inCompany(callable $fn): mixed
    {
        return app(CompanyContext::class)->run($this->company, $fn);
    }

    private function category(string $name, string $code, string $unit, string $generation = 'auto'): int
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/allowance/categories', ['name' => $name, 'code' => $code, 'unit' => $unit, 'generation' => $generation])->assertCreated()->json('data.id');
    }

    private function rate(int $category, float $amount, ?int $position = null)
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/allowance/rates', ['category_id' => $category, 'department_id' => $this->ward->id, 'job_position_id' => $position, 'amount' => $amount]);
    }

    private function entries(): array
    {
        return $this->inCompany(fn () => DailyAllowance::where('employee_id', $this->nurse->id)->where('status', 'approved')->orderBy('start_date')->get()
            ->map(fn ($e) => $e->category.' '.$e->start_date->format('m-d').' '.(int) $e->amount)->all());
    }

    public function test_allowances_are_worked_out_from_the_roster_and_calendar(): void
    {
        $night = $this->category('Night allowance', 'NA', 'night');
        $daily = $this->category('Duty allowance', 'DA', 'day');
        $holiday = $this->category('Holiday duty', 'HD', 'holiday');
        $monthly = $this->category('Risk allowance', 'RA', 'month');
        $this->rate($night, 10000)->assertCreated();
        $this->rate($night, 15000, $this->nursePosition)->assertCreated()->assertJsonPath('data.position.title', 'Nurse');
        $this->rate($night, 9000)->assertUnprocessable();
        $this->rate($daily, 2000)->assertCreated();
        $this->rate($holiday, 20000)->assertCreated();
        $this->rate($monthly, 50000)->assertCreated();

        $this->postJson('/api/v1/roster/holidays', ['title' => 'Local holiday', 'type' => 'once', 'date' => '2026-11-04'])->assertCreated();
        $roster = $this->postJson('/api/v1/roster/rosters', ['department_id' => $this->ward->id, 'from_date' => '2026-11-02', 'to_date' => '2026-11-04'])->json('data.id');
        $duty = fn ($date, $code) => ['employee_id' => $this->nurse->id, 'date' => $date, 'roster_code_id' => $this->codes[$code]];
        $this->putJson("/api/v1/roster/rosters/{$roster}/duties", ['duties' => [$duty('2026-11-02', 'N'), $duty('2026-11-03', 'O'), $duty('2026-11-04', 'M')]])->assertOk();
        $this->postJson("/api/v1/roster/rosters/{$roster}/submit")->assertOk();
        $this->inCompany(fn () => app(ManageLeave::class)->request($this->nurse, ['leave_type_id' => LeaveType::where('code', 'AL')->value('id'), 'start_date' => '2026-11-05', 'days' => 2], $this->admin, 'hr'));

        $this->postJson('/api/v1/allowance/generate', ['from' => '2026-11-01', 'to' => '2026-11-10'])->assertOk()->assertJsonPath('data.added', 7);
        $this->assertSame([
            'Risk allowance 11-01 50000', 'Night allowance 11-02 15000', 'Duty allowance 11-02 2000', 'Duty allowance 11-04 2000', 'Holiday duty 11-04 20000',
            'Duty allowance 11-09 2000', 'Duty allowance 11-10 2000',
        ], $this->entries());
        $this->assertSame(0, $this->inCompany(fn () => DailyAllowance::where('employee_id', $this->intern->id)->count()), 'internship contract gives no allowances');

        $this->putJson("/api/v1/roster/rosters/{$roster}/duties", ['duties' => [$duty('2026-11-02', 'M')]])->assertUnprocessable();
        $this->inCompany(fn () => EmployeeTimetable::where('roster_id', $roster)->whereDate('day_date', '2026-11-02')->update(['code' => 'M', 'pass_day' => false, 'start_time' => '07:30', 'end_time' => '15:30']));
        $this->postJson('/api/v1/allowance/generate', ['from' => '2026-11-01', 'to' => '2026-11-10'])->assertOk()->assertJsonPath('data.removed', 1)->assertJsonPath('data.added', 0);
        $this->assertNotContains('Night allowance 11-02 15000', $this->entries());

        $res = $this->getJson('/api/v1/allowance/entries?month=2026-11')->assertOk()->assertJsonPath('meta.total', 78000)->assertJsonCount(1, 'data');
        $this->assertSame(['Duty allowance', 4, 8000], [$res->json('data.0.categories.0.category'), $res->json('data.0.categories.0.items'), $res->json('data.0.categories.0.amount')]);
        $this->actingAs($this->user)->getJson('/api/v1/self/allowances?month=2026-11')->assertOk()->assertJsonPath('meta.total', 78000)->assertJsonCount(6, 'data');
        $this->artisan('allowance:generate')->expectsOutputToContain('Allowances added: 2, removed: 0')->assertSuccessful();
    }

    public function test_claimed_allowances_go_through_approval(): void
    {
        $theatre = $this->category('Theatre allowance', 'OT', 'case', 'auto');
        $this->getJson('/api/v1/allowance/categories')->assertJsonPath('data.0.generation', 'claim');
        $daily = $this->category('Duty allowance', 'DA', 'day');
        $this->rate($theatre, 5000)->assertCreated();
        $this->rate($daily, 2000)->assertCreated();
        $hr = $this->inCompany(fn () => tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('hr-officer'));
        $this->inCompany(fn () => WorkflowTemplate::create(['process' => 'allowance-claim', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'HR', 'approver_type' => 'user', 'user_id' => $hr->id]));

        $claim = fn (Employee $e, array $d) => $this->actingAs($this->admin)->postJson("/api/v1/allowance/employees/{$e->id}/claims", $d);
        $claim($this->nurse, ['allowance_category_id' => $daily, 'date' => '2026-11-10', 'items' => 1])->assertUnprocessable()->assertJsonValidationErrors(['allowance_category_id' => 'automatically']);
        $claim($this->nurse, ['allowance_category_id' => $theatre, 'date' => '2026-11-20', 'items' => 1])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $claim($this->intern, ['allowance_category_id' => $theatre, 'date' => '2026-11-10', 'items' => 1])->assertUnprocessable()->assertJsonValidationErrors(['employee_id' => 'does not give allowances']);
        $id = $claim($this->nurse, ['allowance_category_id' => $theatre, 'date' => '2026-11-10', 'items' => 3, 'description' => 'Emergency C-sections'])->assertCreated()
            ->assertJsonPath('data.amount', 15000)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', 'HR')->json('data.id');

        $workflow = DailyAllowance::find($id)->workflow;
        $this->actingAs($hr)->getJson("/api/v1/workflow/requests/{$workflow->id}")->assertJsonPath('data.lines.0', '10 Nov 2026: 3 × 5,000.00 = 15,000.00');
        $this->postJson("/api/v1/workflow/requests/{$workflow->id}/approve")->assertOk();
        $this->assertSame('approved', DailyAllowance::find($id)->status);
        $this->actingAs($this->user)->getJson('/api/v1/me/notifications')->assertJsonPath('data.0.title', 'Allowance approved');
        $this->actingAs($this->admin)->postJson('/api/v1/allowance/generate', ['from' => '2026-11-10', 'to' => '2026-11-10'])->assertOk();
        $this->assertSame('approved', DailyAllowance::find($id)->status, 'claims are not touched when working allowances out again');
        $this->actingAs($this->user)->getJson('/api/v1/allowance/entries')->assertForbidden();
    }

    public function test_heads_enter_a_days_counts_as_a_batch_sent_for_approval(): void
    {
        $theatre = $this->category('Theatre allowance', 'OT', 'case', 'claim');
        $this->rate($theatre, 5000)->assertCreated();
        $hr = $this->inCompany(fn () => tap(User::factory()->create(['company_id' => $this->company->id]))->assignRole('hr-officer'));
        $this->inCompany(fn () => WorkflowTemplate::create(['process' => 'allowance-batch', 'is_active' => true])->steps()->create(['step_order' => 1, 'name' => 'HR', 'approver_type' => 'user', 'user_id' => $hr->id]));
        $scrub = $this->inCompany(fn () => tap(Employee::create(['employee_no' => 'Scrub', 'fname' => 'Scrub', 'lname' => 'X', 'gender' => 'Male', 'department_id' => $this->ward->id, 'job_position_id' => $this->nursePosition,
            'emp_type' => 'full_time', 'employment_status' => 'confirmed', 'employment_date' => '2020-01-01']), fn ($e) => EmployeeContract::create(['employee_id' => $e->id,
            'nature_of_contract_id' => NatureOfContract::where('code', 'STC')->value('id'), 'contract_no' => 'S1', 'basic_salary' => 900000, 'period' => 12, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active', 'is_active' => true])));
        $this->inCompany(fn () => app(ManageLeave::class)->request($this->nurse, ['leave_type_id' => LeaveType::where('code', 'AL')->value('id'), 'start_date' => '2026-11-10', 'days' => 1], $this->admin, 'hr'));

        $monthly = $this->category('Risk allowance', 'RA', 'month', 'claim');
        $this->rate($monthly, 30000)->assertCreated();
        $callOut = $this->category('Call-out allowance', 'CO', 'day', 'claim');
        $choices = $this->actingAs($this->admin)->getJson('/api/v1/allowance/batches')->assertOk()->json('meta.choices');
        $this->assertSame([['category_id' => $theatre, 'category' => 'Theatre allowance', 'department_id' => $this->ward->id, 'department' => 'Ward']], $choices, 'only claimed, not monthly, with a rate for the team');
        $this->postJson('/api/v1/allowance/batches', ['allowance_category_id' => $callOut, 'department_id' => $this->ward->id, 'date' => '2026-11-10'])->assertUnprocessable()->assertJsonValidationErrors(['allowance_category_id' => 'no rate']);
        $this->postJson('/api/v1/allowance/batches', ['allowance_category_id' => $monthly, 'department_id' => $this->ward->id, 'date' => '2026-11-10'])->assertUnprocessable()->assertJsonValidationErrors(['allowance_category_id']);
        $new = fn (string $date) => $this->postJson('/api/v1/allowance/batches', ['allowance_category_id' => $theatre, 'department_id' => $this->ward->id, 'date' => $date]);
        $new('2026-11-20')->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $id = $new('2026-11-10')->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $new('2026-11-10')->assertUnprocessable()->assertJsonValidationErrors(['date' => 'already']);

        $team = collect($this->getJson("/api/v1/allowance/batches/{$id}/employees")->assertOk()->json('data'))->keyBy('name');
        $this->assertStringStartsWith('On ', $team['Nurse X']['blocked']);
        $this->assertSame('Contract does not give allowances', $team['Intern X']['blocked']);
        $this->assertNull($team['Scrub X']['blocked']);
        $this->putJson("/api/v1/allowance/batches/{$id}/lines", ['employee_id' => $this->nurse->id, 'items' => 2])->assertUnprocessable()->assertJsonValidationErrors(['employee_id' => 'On ']);
        $this->postJson("/api/v1/allowance/batches/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        $this->putJson("/api/v1/allowance/batches/{$id}/lines", ['employee_id' => $scrub->id, 'items' => 4])->assertOk()->assertJsonPath('data.amount', 20000);
        $this->putJson("/api/v1/allowance/batches/{$id}/lines", ['employee_id' => $scrub->id, 'items' => 3])->assertOk()->assertJsonPath('data.amount', 15000);
        $this->actingAs($hr)->getJson("/api/v1/allowance/batches/{$id}")->assertOk()->assertJsonPath('data.batch.can_edit', true);
        $this->actingAs($this->user)->getJson("/api/v1/allowance/batches/{$id}")->assertNotFound();

        $this->actingAs($this->admin)->postJson("/api/v1/allowance/batches/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->putJson("/api/v1/allowance/batches/{$id}/lines", ['employee_id' => $scrub->id, 'items' => 5])->assertUnprocessable();
        $this->assertSame(0, DailyAllowance::where('employee_id', $scrub->id)->count());
        $workflow = \App\Models\AllowanceBatch::find($id)->workflow;
        $this->actingAs($hr)->getJson("/api/v1/workflow/requests/{$workflow->id}")->assertJsonPath('data.lines.0', '1 employees, 3 items, total 15,000.00')->assertJsonPath('data.link', "/allowance/batches/{$id}");
        $this->getJson("/api/v1/allowance/batches/{$id}")->assertOk()->assertJsonPath('data.lines.0.name', 'Scrub X')->assertJsonPath('data.batch.amount', 15000);
        $this->postJson("/api/v1/workflow/requests/{$workflow->id}/approve")->assertOk();
        $this->assertSame('approved', \App\Models\AllowanceBatch::find($id)->status);
        $entry = DailyAllowance::where('employee_id', $scrub->id)->first();
        $this->assertSame(['approved', 'batch', 15000.0], [$entry->status, $entry->source, (float) $entry->amount]);
        $this->actingAs($this->admin)->deleteJson("/api/v1/allowance/batches/{$id}")->assertUnprocessable();
    }

    public function test_activity_allowances_use_a_daily_count_from_the_source_or_entered_by_hand(): void
    {
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            if (($q['Start_Date'] ?? '') === '2026-11-11') {
                return Http::response('down', 500);
            }

            return Http::response(['status' => 'OK', 'returnData' => ['list_of_items' => [['Item_ID' => 3, 'Total' => 5], ['Item_ID' => 4, 'Total' => 2], ['Item_ID' => 99, 'Total' => 100]]]]);
        });
        $config = ['url' => 'http://afya.test/report', 'params' => ['Start_Date' => '{date}', 'l' => '0'], 'token' => 'secret',
            'list_path' => 'returnData.list_of_items', 'count_field' => 'Total', 'id_field' => 'Item_ID', 'ids' => ['3', '4']];
        $theatre = $this->actingAs($this->admin)->postJson('/api/v1/allowance/categories', ['name' => 'Theatre allowance', 'code' => 'OT', 'unit' => 'case', 'generation' => 'activity',
            'activity_source' => 'rest', 'activity_config' => $config])->assertCreated()
            ->assertJsonPath('data.generation', 'activity')->assertJsonPath('data.activity_config.has_token', true)->assertJsonMissingPath('data.activity_config.token')->json('data.id');
        $this->rate($theatre, 361, $this->nursePosition)->assertCreated();
        $this->postJson("/api/v1/allowance/categories/{$theatre}/test-source", ['date' => '2026-11-10'])->assertOk()->assertJsonPath('data.count', 7);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'Start_Date=2026-11-10') && $r->hasHeader('Authorization', 'Bearer secret'));

        $this->inCompany(fn () => app(ManageLeave::class)->request($this->nurse, ['leave_type_id' => LeaveType::where('code', 'AL')->value('id'), 'start_date' => '2026-11-09', 'days' => 1], $this->admin, 'hr'));
        $this->postJson('/api/v1/allowance/activity-counts/fetch', ['category_id' => $theatre, 'from' => '2026-11-09', 'to' => '2026-11-11'])->assertOk()
            ->assertJsonPath('data.fetched', 2)->assertJsonPath('data.failed', 1);
        $this->assertSame(['Theatre allowance 11-10 2527'], $this->entries(), 'on leave on 11-09; 11-11 not fetched');
        $this->assertSame(0, $this->inCompany(fn () => DailyAllowance::where('employee_id', $this->intern->id)->count()));
        $days = collect($this->getJson("/api/v1/allowance/activity-counts?category_id={$theatre}&from=2026-11-09&to=2026-11-11")->assertOk()->json('data'))->keyBy('date');
        $this->assertSame(['failed', 'ok'], [$days['2026-11-11']['status'], $days['2026-11-10']['status']]);
        $this->assertStringContainsString('HTTP 500', $days['2026-11-11']['error']);

        $this->postJson('/api/v1/allowance/activity-counts', ['category_id' => $theatre, 'date' => '2026-11-10', 'count' => 3])->assertOk();
        $this->assertSame(['Theatre allowance 11-10 1083'], $this->entries());
        $this->postJson('/api/v1/allowance/activity-counts/fetch', ['category_id' => $theatre, 'from' => '2026-11-10', 'to' => '2026-11-10'])->assertOk()->assertJsonPath('data.kept', 1);
        $this->assertSame(['Theatre allowance 11-10 1083'], $this->entries(), 'a count entered by hand is kept');

        $this->postJson("/api/v1/allowance/employees/{$this->nurse->id}/claims", ['allowance_category_id' => $theatre, 'date' => '2026-11-10', 'items' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['allowance_category_id' => 'daily activity count']);
        $this->actingAs($this->user)->getJson('/api/v1/allowance/activity-counts')->assertForbidden();
    }
}
