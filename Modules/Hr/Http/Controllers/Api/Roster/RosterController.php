<?php

namespace Modules\Hr\Http\Controllers\Api\Roster;

use Modules\Core\Support\PdfDocument;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Roster;
use App\Models\RosterCode;
use App\Services\WorkCalendar;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Modules\Hr\Actions\ManageRosters;
use Modules\Hr\Http\Requests\Roster\RosterDutiesRequest;
use Modules\Hr\Http\Requests\Roster\RosterRequest;
use Modules\Hr\Support\RosterAccess;

/**
 * Duty rosters: each employee's duty for every day of a period, by department or section.
 *
 * @tags Roster: Duty rosters
 */
class RosterController extends Controller
{
    public function __construct(private readonly ManageRosters $rosters, private readonly RosterAccess $access) {}

    /**
     * List duty rosters the user can see, newest period first.
     *
     * @response array{data: list<array{id: int, title: string, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, from_date: string, to_date: string, days: int, status: string, status_label: string, employees: int, prepared_by: string|null, waiting_for: string|null, decision_comment: string|null, can_manage: bool}>}
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->canList($request->user()), 403);
        $filters = $request->validate([
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,pending,published,rejected'],
            /** Rosters covering any day of this month, e.g. 2026-12. */
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $query = Roster::duty()->with(['department:id,name', 'section:id,name', 'creator.employee', 'workflow.steps'])->withCount(['duties as employees' => fn ($q) => $q->select(\DB::raw('count(distinct employee_id)'))])
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q->where('department_id', $id)->orWhere('section_id', $id)))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['month'] ?? null, function ($q, $m) {
                $start = Carbon::createFromFormat('Y-m-d', "$m-01")->startOfMonth();
                $q->whereDate('from_date', '<=', $start->copy()->endOfMonth())->whereDate('to_date', '>=', $start);
            });

        return response()->json(['data' => $this->access->apply($query, $request->user())->orderByDesc('from_date')->orderBy('title')->limit(200)->get()
            ->map(fn (Roster $r) => $this->row($r, $request))]);
    }

    /**
     * What the user needs to create a roster: departments and sections they can prepare rosters for, active duty
     * codes, and earlier rosters to repeat.
     *
     * @response array{data: array{departments: list<array{id: int, name: string, parent_id: int|null, parent: string|null}>, codes: int, rosters: list<array{id: int, title: string, department_id: int, section_id: int|null, from_date: string, to_date: string}>}}
     */
    public function options(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('roster.manage'), 403);
        $departments = $this->access->manageable($request->user())->with('parent:id,name')->get()
            ->sortBy(fn ($d) => ($d->parent?->name ?? $d->name).' '.($d->parent ? $d->name : ''))->values()
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'parent_id' => $d->parent_id, 'parent' => $d->parent?->name]);
        $rosters = $this->access->apply(Roster::duty(), $request->user())->orderByDesc('from_date')->limit(50)
            ->get(['id', 'title', 'department_id', 'section_id', 'from_date', 'to_date'])
            ->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'department_id' => $r->department_id, 'section_id' => $r->section_id,
                'from_date' => $r->from_date->toDateString(), 'to_date' => $r->to_date->toDateString()]);

        return response()->json(['data' => ['departments' => $departments, 'codes' => RosterCode::where('is_active', true)->count(), 'rosters' => $rosters]]);
    }

    /**
     * Create a draft roster for a department or section (optionally repeating an earlier roster).
     *
     * @response array{data: array{id: int, title: string, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, from_date: string, to_date: string, days: int, status: string, status_label: string, employees: int, prepared_by: string|null, waiting_for: string|null, decision_comment: string|null, can_manage: bool}}
     */
    public function store(RosterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $department = Department::findOrFail($data['department_id']);
        abort_unless($this->access->canManage($request->user(), $department->parent_id ?: $department->id, $department->parent_id ? $department->id : ($data['section_id'] ?? null)), 403, 'You cannot prepare rosters for this department.');
        $roster = $this->rosters->create($data, $request->user());

        return response()->json(['data' => $this->row($roster->load(['department', 'section', 'creator.employee'])->loadCount(['duties as employees' => fn ($q) => $q->select(\DB::raw('count(distinct employee_id)'))]), $request)], 201);
    }

    /**
     * A roster with its days, employees, duties and the duty codes to choose from.
     *
     * @response array{data: array{roster: array{id: int, title: string, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, from_date: string, to_date: string, days: int, status: string, status_label: string, employees: int, prepared_by: string|null, waiting_for: string|null, decision_comment: string|null, can_manage: bool}, can_edit: bool, locked_before: string|null, days: list<array{date: string, weekday: string, weekend: bool, holiday: string|null}>, employees: list<array{id: int, name: string, employee_no: string|null, position: string|null}>, duties: list<array{employee_id: int, date: string, roster_code_id: int|null, code: string, duty: string, color: string|null, is_working: bool, start_time: string|null, end_time: string|null, hours: float, overtime: bool}>, codes: list<array{id: int, code: string, duty: string, description: string|null, is_working: bool, start_time: string|null, end_time: string|null, crosses_midnight: bool, work_hour: float, break_minutes: int, color: string|null, is_active: bool}>}}
     */
    public function show(Request $request, Roster $roster): JsonResponse
    {
        abort_unless($roster->type === 'duty' && $this->access->canView($request->user(), $roster), 404);
        $roster->load(['department', 'section', 'creator.employee', 'workflow.steps'])->loadCount(['duties as employees' => fn ($q) => $q->select(\DB::raw('count(distinct employee_id)'))]);
        $calendar = app(WorkCalendar::class);
        $canManage = $this->access->canManage($request->user(), $roster->department_id, $roster->section_id);
        $duties = $roster->duties()->orderBy('day_date')->get();
        $usedCodes = $duties->pluck('roster_code_id')->filter()->unique();

        return response()->json(['data' => [
            'roster' => $this->row($roster, $request),
            'can_edit' => $canManage && $roster->editable(),
            'locked_before' => $roster->status === Roster::PUBLISHED ? now()->toDateString() : null,
            'days' => collect(CarbonPeriod::create($roster->from_date, $roster->to_date))->map(fn ($d) => [
                'date' => $d->toDateString(), 'weekday' => $d->format('D'), 'weekend' => $d->isWeekend(), 'holiday' => $calendar->holidayOn($d)?->title,
            ])->values(),
            'employees' => $this->rosters->employees($roster)->map(fn ($e) => [
                'id' => $e->id, 'name' => $e->full_name, 'employee_no' => $e->employee_no, 'position' => $e->jobPosition?->title,
            ])->values(),
            'duties' => $duties->map(fn ($d) => [
                'employee_id' => $d->employee_id, 'date' => $d->day_date->toDateString(), 'roster_code_id' => $d->roster_code_id, 'code' => $d->code, 'duty' => $d->duty,
                'color' => $d->color, 'is_working' => (bool) $d->can_punch, 'start_time' => $d->start_time, 'end_time' => $d->end_time, 'hours' => (float) $d->work_hour, 'overtime' => (bool) $d->overtime,
            ])->values(),
            'codes' => RosterCode::where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $usedCodes))->orderBy('code')->get()
                ->map(fn ($c) => RosterCodeController::row($c))->values(),
        ]]);
    }

    /** Download a duty roster as CSV (opens in Excel): one row per employee, one column per day, worked hours at the end. */
    public function csv(Request $request, Roster $roster): Response
    {
        $g = $this->grid($request, $roster);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$g['title']]);
        fputcsv($out, [$g['subtitle']]);
        fputcsv($out, []);
        fputcsv($out, array_merge(['Employee no', 'Name', 'Position'], array_map(fn ($d) => $d['date'].' '.$d['weekday'], $g['days']), ['Hours']));
        foreach ($g['rows'] as $r) {
            fputcsv($out, array_merge([$r['employee_no'], $r['name'], $r['position']], array_map(fn ($c) => $c['code'] ?? '', $r['cells']), [$r['hours']]));
        }
        fputcsv($out, []);
        fputcsv($out, ['Code', 'Duty', 'Time']);
        foreach ($g['codes'] as $c) {
            fputcsv($out, [$c['code'], $c['duty'], $c['time']]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.\Illuminate\Support\Str::slug($g['title']).'.csv"']);
    }

    /** A duty roster as a PDF grid with the company header and a legend of codes (opens in the browser). */
    public function pdf(Request $request, Roster $roster, PdfDocument $pdf): Response
    {
        $g = $this->grid($request, $roster);

        return $pdf->view($request->user(), 'pdf.roster', $g['title'], $g, 'landscape');
    }

    private function grid(Request $request, Roster $roster): array
    {
        abort_unless($roster->type === 'duty' && $this->access->canView($request->user(), $roster), 404);
        $roster->load(['department', 'section']);
        $calendar = app(WorkCalendar::class);
        $days = collect(CarbonPeriod::create($roster->from_date, $roster->to_date))->map(fn ($d) => [
            'date' => $d->toDateString(), 'day' => $d->format('j'), 'weekday' => $d->format('D'), 'weekend' => $d->isWeekend(), 'holiday' => $calendar->holidayOn($d)?->title,
        ])->values()->all();
        $all = $roster->duties()->get();
        $codeHours = RosterCode::whereIn('id', $all->pluck('roster_code_id')->filter()->unique())->pluck('work_hour', 'id');
        $short = fn (?string $code) => $code === null ? null : (trim(preg_replace('/\s+/', ' ', preg_replace('/\s*\(.*\)\s*/', '', $code))) ?: $code);
        $hours = fn ($d) => (float) $d->work_hour ?: ($d->can_punch ? (float) ($codeHours[$d->roster_code_id] ?? 0) : 0.0);
        $duties = $all->groupBy('employee_id')->map(fn ($g) => $g->keyBy(fn ($d) => $d->day_date->toDateString()));
        $rows = $this->rosters->employees($roster)->map(function ($e) use ($days, $duties, $short, $hours) {
            $mine = $duties[$e->id] ?? collect();

            return [
                'employee_no' => $e->employee_no, 'name' => $e->full_name, 'position' => $e->jobPosition?->title,
                'cells' => array_map(fn ($d) => ($x = $mine[$d['date']] ?? null) ? ['code' => $x->code, 'short' => $short($x->code), 'color' => $x->color, 'working' => (bool) $x->can_punch]
                    : ['code' => null, 'short' => null, 'color' => null, 'working' => false], $days),
                'hours' => round($mine->sum($hours), 1),
            ];
        })->values()->all();
        $used = $roster->duties()->select('roster_code_id')->distinct()->pluck('roster_code_id')->filter();
        $codes = RosterCode::whereIn('id', $used)->orderBy('code')->get()->map(fn ($c) => [
            'code' => $c->code, 'short' => $short($c->code), 'duty' => $c->duty, 'color' => $c->color,
            'time' => $c->start_time && $c->end_time ? substr($c->start_time, 0, 5).'–'.substr($c->end_time, 0, 5) : ($c->is_working ? '' : 'Off'),
        ])->values()->all();
        $place = collect([$roster->department?->name, $roster->section?->name])->filter()->implode(' · ');

        return [
            'title' => $roster->title ?: 'Duty roster',
            'subtitle' => trim($place.' · '.$roster->from_date->format('d M Y').' – '.$roster->to_date->format('d M Y').' · '.(Roster::STATUS_LABELS[$roster->status] ?? $roster->status), ' ·'),
            'days' => $days, 'rows' => $rows, 'codes' => $codes,
        ];
    }

    /**
     * Change the title or period of a draft or returned roster. Duties outside the new period are removed.
     *
     * @response array{data: array{id: int, title: string, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, from_date: string, to_date: string, days: int, status: string, status_label: string, employees: int, prepared_by: string|null, waiting_for: string|null, decision_comment: string|null, can_manage: bool}}
     */
    public function update(RosterRequest $request, Roster $roster): JsonResponse
    {
        $this->authorizeManage($request, $roster);
        $roster = $this->rosters->update($roster, $request->validated());

        return response()->json(['data' => $this->row($roster->load(['department', 'section', 'creator.employee'])->loadCount(['duties as employees' => fn ($q) => $q->select(\DB::raw('count(distinct employee_id)'))]), $request)]);
    }

    /** Delete a draft or returned roster. */
    public function destroy(Request $request, Roster $roster): Response
    {
        $this->authorizeManage($request, $roster);
        $this->rosters->delete($roster);

        return response()->noContent();
    }

    /**
     * Save changed days. On a published roster only today and later can change.
     *
     * @response array{data: array{saved: int}}
     */
    public function duties(RosterDutiesRequest $request, Roster $roster): JsonResponse
    {
        $this->authorizeManage($request, $roster);

        return response()->json(['data' => ['saved' => $this->rosters->saveDuties($roster, $request->validated()['duties'], $request->user())]]);
    }

    /**
     * Submit the roster: published at once, or after the "Duty roster" approval workflow when one is set up.
     *
     * @response array{data: array{id: int, title: string, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, from_date: string, to_date: string, days: int, status: string, status_label: string, employees: int, prepared_by: string|null, waiting_for: string|null, decision_comment: string|null, can_manage: bool}}
     */
    public function submit(Request $request, Roster $roster): JsonResponse
    {
        $this->authorizeManage($request, $roster);
        $roster = $this->rosters->submit($roster, $request->user());

        return response()->json(['data' => $this->row($roster->load(['department', 'section', 'creator.employee', 'workflow.steps'])->loadCount(['duties as employees' => fn ($q) => $q->select(\DB::raw('count(distinct employee_id)'))]), $request)]);
    }

    /**
     * Withdraw a roster waiting for approval, back to draft.
     *
     * @response array{data: array{id: int, title: string, department: array{id: int, name: string}|null, section: array{id: int, name: string}|null, from_date: string, to_date: string, days: int, status: string, status_label: string, employees: int, prepared_by: string|null, waiting_for: string|null, decision_comment: string|null, can_manage: bool}}
     */
    public function withdraw(Request $request, Roster $roster): JsonResponse
    {
        $this->authorizeManage($request, $roster);
        $roster = $this->rosters->withdraw($roster, $request->user());

        return response()->json(['data' => $this->row($roster->load(['department', 'section', 'creator.employee'])->loadCount(['duties as employees' => fn ($q) => $q->select(\DB::raw('count(distinct employee_id)'))]), $request)]);
    }

    private function authorizeManage(Request $request, Roster $roster): void
    {
        abort_unless($roster->type === 'duty', 404);
        abort_unless($this->access->canManage($request->user(), $roster->department_id, $roster->section_id), 403, 'You cannot change rosters of this department.');
    }

    private function row(Roster $r, Request $request): array
    {
        $pending = $r->status === Roster::PENDING ? $r->workflow?->steps?->firstWhere('status', 'pending') : null;

        return [
            'id' => $r->id, 'title' => $r->title,
            'department' => $r->department ? ['id' => $r->department->id, 'name' => $r->department->name] : null,
            'section' => $r->section ? ['id' => $r->section->id, 'name' => $r->section->name] : null,
            'from_date' => $r->from_date->toDateString(), 'to_date' => $r->to_date->toDateString(), 'days' => (int) $r->from_date->diffInDays($r->to_date) + 1,
            'status' => $r->status, 'status_label' => Roster::STATUS_LABELS[$r->status] ?? $r->status, 'employees' => (int) ($r->employees ?? 0),
            'prepared_by' => $r->creator ? ($r->creator->display_name) : null,
            'waiting_for' => $pending?->name, 'decision_comment' => $r->decision_comment,
            'can_manage' => $this->access->canManage($request->user(), $r->department_id, $r->section_id),
        ];
    }
}
