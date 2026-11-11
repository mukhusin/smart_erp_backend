<?php

namespace Modules\Hr\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeNumberFormat;
use App\Models\HrSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Hr\Http\Requests\NumberFormatRequest;
use Modules\Hr\Http\Resources\NumberFormatResource;

/**
 * Employee number formats. A format is a list of segments (fixed text, running number, year, department code)
 * joined by a separator. Each contract type uses one format; one format can serve many contract types.
 *
 * @tags HR: Settings
 */
class NumberFormatController extends Controller
{
    /**
     * List number formats, with the contract types using each one and the renumbering switch.
     *
     * @response array{data: list<NumberFormatResource>, meta: array{renumber_on_contract_change: bool}}
     */
    public function index(): JsonResponse
    {
        $formats = EmployeeNumberFormat::with(['segments', 'contractTypes'])->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json([
            'data' => NumberFormatResource::collection($formats)->resolve(),
            'meta' => ['renumber_on_contract_change' => HrSetting::current()->renumber_on_contract_change],
        ]);
    }

    /**
     * Number formats for the registration form.
     *
     * @response array{data: list<array{id: int, name: string, is_default: bool}>}
     */
    public function options(): JsonResponse
    {
        return response()->json(['data' => EmployeeNumberFormat::orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'is_default' => $f->is_default])]);
    }

    /**
     * Add a number format.
     */
    public function store(NumberFormatRequest $request): NumberFormatResource
    {
        return new NumberFormatResource($this->save(new EmployeeNumberFormat, $request));
    }

    /**
     * Update a number format. The segment list replaces the old one.
     */
    public function update(NumberFormatRequest $request, EmployeeNumberFormat $numberFormat): NumberFormatResource
    {
        return new NumberFormatResource($this->save($numberFormat, $request));
    }

    /**
     * Delete a number format. Formats used by contract types cannot be deleted.
     */
    public function destroy(EmployeeNumberFormat $numberFormat): Response
    {
        $used = $numberFormat->contractTypes()->pluck('contract_name');
        abort_if($used->isNotEmpty(), 422, 'Used by: '.$used->implode(', ').'. Choose another format for these contract types first.');
        $numberFormat->delete();

        return response()->noContent();
    }

    /**
     * Turn renumbering on contract change on or off.
     *
     * On: when a contract starts, the employee gets a number in its contract type's format (if the current number
     * is not already in that format). Removing the contract by mistake gives the old number back.
     *
     * @response array{data: array{renumber_on_contract_change: bool}}
     */
    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['renumber_on_contract_change' => ['required', 'boolean']]);
        $settings = HrSetting::current();
        $settings->fill($data)->save();

        return response()->json(['data' => ['renumber_on_contract_change' => $settings->renumber_on_contract_change]]);
    }

    private function save(EmployeeNumberFormat $format, NumberFormatRequest $request): EmployeeNumberFormat
    {
        $data = $request->validated();

        DB::transaction(function () use ($format, $data) {
            $format->fill(['name' => $data['name'], 'separator' => $data['separator'] ?? '', 'is_default' => (bool) ($data['is_default'] ?? false)])->save();
            if ($format->is_default) {
                EmployeeNumberFormat::whereKeyNot($format->id)->update(['is_default' => false]);
            }
            $format->segments()->delete();
            foreach (array_values($data['segments']) as $i => $segment) {
                $format->segments()->create([
                    'position' => $i + 1,
                    'type' => $segment['type'],
                    'value' => $segment['value'] ?? null,
                    'length' => match ($segment['type']) {
                        'sequence' => (int) ($segment['length'] ?? 0),
                        'year' => (int) ($segment['length'] ?? 2),
                        default => null,
                    },
                    'start_at' => $segment['type'] === 'sequence' ? (int) ($segment['start_at'] ?? 1) : null,
                ]);
            }
        });

        return $format->load(['segments', 'contractTypes']);
    }
}
