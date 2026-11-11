<?php

namespace Modules\Hr\Support;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeNumberFormat;
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

    public function defaultFormat(): ?EmployeeNumberFormat
    {
        return $this->all()->firstWhere('is_default', true);
    }

    public function find(?int $formatId): ?EmployeeNumberFormat
    {
        return $formatId ? $this->all()->firstWhere('id', $formatId) : null;
    }

    public function forContractType(?int $natureOfContractId): ?EmployeeNumberFormat
    {
        $formatId = NatureOfContract::withoutCompanyScope()->withTrashed()->whereKey($natureOfContractId)->value('employee_number_format_id');

        return $this->find($formatId);
    }

    /** Next number in the given format (or the default one), for an employee of `$departmentId`. */
    public function next(?int $formatId = null, ?int $departmentId = null): string
    {
        $format = $this->find($formatId) ?? $this->defaultFormat();

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

    public function renumberOnContractChange(): bool
    {
        return HrSetting::current()->renumber_on_contract_change;
    }

    public function forget(): void
    {
        $this->formats = null;
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
        return $this->formats ??= EmployeeNumberFormat::with('segments')->get();
    }
}
