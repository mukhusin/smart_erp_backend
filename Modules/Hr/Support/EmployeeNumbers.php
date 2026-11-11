<?php

namespace Modules\Hr\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeNumberFormat;
use App\Models\EmployeeNumberFormatSegment;
use App\Models\HrSetting;
use App\Models\NatureOfContract;
use Illuminate\Support\Collection;

/**
 * Employee numbers from the company's number formats (HR settings). A format is a list of segments
 * (fixed text, running number, year, department code) joined by a separator. Each contract type uses one format;
 * one format can serve many contract types; the default format is used when nothing else is chosen.
 *
 * The running number is the highest in use for that format + 1 (removed employees and old contract numbers count,
 * as in HRMS), so a freed number is never handed out again. Without any format: continue the latest number.
 */
class EmployeeNumbers
{
    private ?Collection $formats = null;

    /** Built-in formats used while the company has none of its own, numbering like HRMS (keyed by employee type). */
    public const BUILT_IN = [
        'full_time' => ['Staff (ST)', [['text', 'ST'], ['sequence', 0]]],
        'part_time' => ['Part time (PT)', [['text', 'PT'], ['sequence', 0]]],
        'volunteer' => ['Volunteer (VOL)', [['text', 'VOL'], ['sequence', 0]]],
        'indirect' => ['Indirect (IND)', [['text', 'IND'], ['sequence', 0]]],
        'internship' => ['Interns', [['text', 'INT/{company}/'], ['department', 'OTHER'], ['text', ':'], ['sequence', 4], ['text', '/'], ['year', 2]]],
        'resident' => ['Residents', [['text', 'RES/{company}/SN:'], ['sequence', 3], ['text', '/'], ['year', 2]]],
    ];

    private ?Collection $builtIn = null;

    /** Whether the company set up its own number formats. */
    public function configured(): bool
    {
        return $this->persisted()->isNotEmpty();
    }

    public function defaultFormat(?string $empType = null): ?EmployeeNumberFormat
    {
        if (! $this->configured()) {
            return $this->builtIn()->get($empType ?: 'full_time') ?? $this->builtIn()->get('full_time');
        }

        return $this->all()->firstWhere('is_default', true);
    }

    /** @return Collection<string, EmployeeNumberFormat> */
    public function builtIn(): Collection
    {
        return $this->builtIn ??= collect(self::BUILT_IN)->map(function (array $def, string $type) {
            $code = $this->companyCode();
            $format = new EmployeeNumberFormat(['name' => $def[0], 'separator' => '', 'is_default' => $type === 'full_time']);
            $format->setRelation('segments', collect($def[1])->values()->map(fn (array $seg, int $i) => new EmployeeNumberFormatSegment([
                'position' => $i + 1, 'type' => $seg[0],
                'value' => in_array($seg[0], ['text', 'department'], true) ? str_replace('{company}', $code, (string) $seg[1]) : null,
                'length' => in_array($seg[0], ['sequence', 'year'], true) ? (int) $seg[1] : null,
                'start_at' => $seg[0] === 'sequence' ? 1 : null,
            ])));

            return $format;
        });
    }

    public function find(?int $formatId): ?EmployeeNumberFormat
    {
        return $formatId ? $this->all()->firstWhere('id', $formatId) : null;
    }

    public function forContractType(?int $natureOfContractId): ?EmployeeNumberFormat
    {
        $nature = NatureOfContract::withoutCompanyScope()->withTrashed()->whereKey($natureOfContractId)->first(['employee_number_format_id', 'employment_type']);
        if (! $this->configured()) {
            return $this->builtIn()->get(EmployeeOptions::empTypeOf($nature?->employment_type) ?? '');
        }

        return $this->find($nature?->employee_number_format_id);
    }

    /**
     * Next number in the given format, or the default one. Without the company's own formats: the HRMS-style
     * series of `$empType` (ST, PT, VOL, interns, residents).
     */
    public function next(?int $formatId = null, ?int $departmentId = null, ?string $empType = null): string
    {
        $format = $this->find($formatId) ?? $this->defaultFormat($empType);

        return $format ? $this->nextIn($format, $departmentId) : $this->legacyNext();
    }

    public function nextIn(EmployeeNumberFormat $format, ?int $departmentId = null): string
    {
        $pattern = $this->pattern($format);
        $max = $this->usedNumbers($format)
            ->map(fn (string $no) => preg_match($pattern, $no, $m) && isset($m['seq']) ? (int) $m['seq'] : null)
            ->filter()->max();
        $start = $format->segments->firstWhere('type', 'sequence')?->start_at ?: 1;

        for ($n = $max ? $max + 1 : $start; ; $n++) {
            $candidate = $this->build($format, $n, $departmentId);
            if (! $this->taken($candidate)) {
                return $candidate;
            }
            if (! $format->segments->contains('type', 'sequence')) {
                return $candidate;
            }
        }
    }

    public function build(EmployeeNumberFormat $format, int $sequence, ?int $departmentId = null): string
    {
        $department = $departmentId ? Department::withoutCompanyScope()->find($departmentId) : null;

        return $format->segments->map(fn ($s) => match ($s->type) {
            'text' => (string) $s->value,
            'sequence' => str_pad((string) $sequence, (int) $s->length, '0', STR_PAD_LEFT),
            'year' => now()->format($s->length === 4 ? 'Y' : 'y'),
            'department' => strtoupper($department?->short_name ?: ($s->value ?: 'GEN')),
            default => '',
        })->implode($format->separator ?? '');
    }

    public function matches(EmployeeNumberFormat $format, ?string $number): bool
    {
        return $number !== null && $format->segments->isNotEmpty() && preg_match($this->pattern($format), $number) === 1;
    }

    /** The format a number belongs to (to give a fresh number of the same kind). */
    public function formatOf(?string $number): ?EmployeeNumberFormat
    {
        return $this->all()->first(fn ($f) => $this->matches($f, $number));
    }

    public function taken(string $number, ?int $exceptEmployeeId = null): bool
    {
        return Employee::withTrashed()->where('employee_no', $number)->when($exceptEmployeeId, fn ($q) => $q->whereKeyNot($exceptEmployeeId))->exists();
    }

    /** Stored setting; without one, on while the built-in HRMS numbering is used (as HRMS did) and off otherwise. */
    public function renumberOnContractChange(): bool
    {
        $setting = HrSetting::query()->first();

        return $setting ? $setting->renumber_on_contract_change : ! $this->configured();
    }

    public function forget(): void
    {
        $this->formats = null;
        $this->builtIn = null;
    }

    private function companyCode(): string
    {
        $company = app(\App\Services\CompanyContext::class)->get();
        if ($company?->short_name) {
            return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $company->short_name));
        }
        $initials = collect(preg_split('/\s+/', trim((string) $company?->name)))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->implode('');

        return strtoupper($initials ?: 'CO');
    }

    private function legacyNext(): string
    {
        $last = Employee::withTrashed()->latest('id')->limit(200)->pluck('employee_no')
            ->first(fn ($no) => preg_match('/^(.*?)(\d+)$/', (string) $no) && ! $this->formatOf($no));
        if (! $last || ! preg_match('/^(.*?)(\d+)$/', $last, $m)) {
            $m = [null, 'EMP-', '0000'];
        }
        $width = strlen($m[2]);
        for ($n = (int) $m[2] + 1; ; $n++) {
            $candidate = $m[1].str_pad((string) $n, $width, '0', STR_PAD_LEFT);
            if (! $this->taken($candidate)) {
                return $candidate;
            }
        }
    }

    /** @return Collection<int, string> */
    private function usedNumbers(EmployeeNumberFormat $format): Collection
    {
        $first = $format->segments->first();
        $like = $first?->type === 'text' && $first->value !== '' ? str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $first->value).'%' : '%';
        $employees = Employee::withTrashed()->where('employee_no', 'like', $like)->pluck('employee_no');
        $contracts = EmployeeContract::withTrashed()->whereIn('employee_id', Employee::withTrashed()->select('id'))
            ->where('contract_no', 'like', $like)->pluck('contract_no');

        return $employees->merge($contracts)->filter()->unique()->values();
    }

    private function pattern(EmployeeNumberFormat $format): string
    {
        $firstSequence = $format->segments->firstWhere('type', 'sequence')?->id;
        $parts = $format->segments->map(fn ($s) => match ($s->type) {
            'text' => preg_quote((string) $s->value, '/'),
            'sequence' => $s->id === $firstSequence ? '(?<seq>\d+)' : '\d+',
            'year' => $s->length === 4 ? '\d{4}' : '\d{2}',
            'department' => '(?:'.$this->departmentCodes($s->value ?: 'GEN')->map(fn ($c) => preg_quote($c, '/'))->implode('|').')',
            default => '',
        });

        return '/^'.$parts->implode(preg_quote($format->separator ?? '', '/')).'$/';
    }

    /** @return Collection<int, string> */
    private function departmentCodes(string $fallback): Collection
    {
        return Department::whereNotNull('short_name')->where('short_name', '!=', '')->pluck('short_name')
            ->map(fn ($c) => strtoupper($c))->push(strtoupper($fallback))->unique()->values();
    }

    /** @return Collection<int, EmployeeNumberFormat> */
    private function all(): Collection
    {
        return $this->configured() ? $this->persisted() : $this->builtIn()->values();
    }

    /** @return Collection<int, EmployeeNumberFormat> */
    private function persisted(): Collection
    {
        return $this->formats ??= EmployeeNumberFormat::with('segments')->get();
    }
}
