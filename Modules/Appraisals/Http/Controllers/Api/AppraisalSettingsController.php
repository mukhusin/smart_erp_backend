<?php

namespace Modules\Appraisals\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Appraisals\Models\AppraisalCompetency;
use Modules\Appraisals\Models\AppraisalScore;
use Modules\Appraisals\Models\AppraisalSetting;

/**
 * @tags Appraisals
 */
class AppraisalSettingsController extends Controller
{
    /**
     * Scale, mid-year review, score parts with weights and rating bands. Open periods keep the settings they opened with.
     *
     * @response array{data: array{scale: string, mid_year: bool, parts: list<array{key: string, label: string, weight: int, enabled: bool}>, bands: list<array{min: int, label: string}>}}
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->shape(AppraisalSetting::current())]);
    }

    /** @response array{data: array{scale: string, mid_year: bool, parts: list<array{key: string, label: string, weight: int, enabled: bool}>, bands: list<array{min: int, label: string}>}} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scale' => ['required', Rule::in(['percent', 'five'])],
            'mid_year' => ['required', 'boolean'],
            'parts' => ['required', 'array', 'size:3'],
            'parts.*.key' => ['required', Rule::in(['tasks', 'competencies', 'attendance']), 'distinct'],
            'parts.*.label' => ['required', 'string', 'max:80'],
            'parts.*.weight' => ['required', 'integer', 'between:0,100'],
            'parts.*.enabled' => ['required', 'boolean'],
            'bands' => ['required', 'array', 'min:2', 'max:10'],
            'bands.*.min' => ['required', 'integer', 'between:0,100', 'distinct'],
            'bands.*.label' => ['required', 'string', 'max:60'],
        ]);
        $enabled = collect($data['parts'])->where('enabled', true);
        if ($enabled->isEmpty() || $enabled->sum('weight') !== 100) {
            throw ValidationException::withMessages(['parts' => 'The weights of the parts switched on must add up to 100.']);
        }
        if (! collect($data['bands'])->contains('min', 0)) {
            throw ValidationException::withMessages(['bands' => 'One rating must start at 0.']);
        }
        $settings = AppraisalSetting::current();
        $settings->fill($data + ['bands' => collect($data['bands'])->sortByDesc('min')->values()->all()])->save();

        return response()->json(['data' => $this->shape($settings)]);
    }

    /**
     * Competencies rated in every appraisal (e.g. teamwork, communication).
     *
     * @response array{data: list<array{id: int, name: string, description: string|null, is_active: bool, sort_order: int}>}
     */
    public function competencies(): JsonResponse
    {
        return response()->json(['data' => AppraisalCompetency::orderBy('sort_order')->orderBy('name')->get()->map(fn ($c) => $this->competency($c))->values()]);
    }

    /** @response array{data: array{id: int, name: string, description: string|null, is_active: bool, sort_order: int}} */
    public function storeCompetency(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->competency(AppraisalCompetency::create($this->validated($request)))], 201);
    }

    /** @response array{data: array{id: int, name: string, description: string|null, is_active: bool, sort_order: int}} */
    public function updateCompetency(Request $request, AppraisalCompetency $competency): JsonResponse
    {
        $competency->update($this->validated($request));

        return response()->json(['data' => $this->competency($competency)]);
    }

    /** @response array{message: string} */
    public function destroyCompetency(AppraisalCompetency $competency): JsonResponse
    {
        if (AppraisalScore::where('appraisal_competency_id', $competency->id)->exists()) {
            throw ValidationException::withMessages(['competency' => 'This competency has been rated. Switch it off instead.']);
        }
        $competency->delete();

        return response()->json(['message' => 'Competency deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,999'],
        ]);
    }

    private function competency(AppraisalCompetency $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'is_active' => (bool) $c->is_active, 'sort_order' => (int) $c->sort_order];
    }

    private function shape(AppraisalSetting $s): array
    {
        $c = $s->config();

        return ['scale' => (string) $c['scale'], 'mid_year' => (bool) $c['mid_year'],
            'parts' => array_map(fn ($p) => ['key' => (string) $p['key'], 'label' => (string) $p['label'], 'weight' => (int) $p['weight'], 'enabled' => (bool) ($p['enabled'] ?? true)], $c['parts']),
            'bands' => array_map(fn ($b) => ['min' => (int) $b['min'], 'label' => (string) $b['label']], $c['bands'])];
    }
}
