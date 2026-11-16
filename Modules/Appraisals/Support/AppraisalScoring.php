<?php

namespace Modules\Appraisals\Support;

use Illuminate\Support\Carbon;
use Modules\Appraisals\Models\Appraisal;
use Modules\Tasks\Models\Task;

class AppraisalScoring
{
    public const ORDER = ['hr', 'hod', 'supervisor', 'self'];

    public function partScores(Appraisal $a, string $rater): array
    {
        $rows = $a->scores->where('rater', $rater);
        $out = [];
        foreach ($a->cycle->parts() as $p) {
            $part = $rows->where('part', $p['key'])->whereNotNull('score');
            if ($part->isNotEmpty()) {
                $out[$p['key']] = round((float) $part->avg('score'), 2);
            }
        }

        return $out;
    }

    public function effective(Appraisal $a): array
    {
        $out = [];
        foreach ($a->cycle->parts() as $p) {
            foreach (self::ORDER as $rater) {
                $s = $this->partScores($a, $rater)[$p['key']] ?? null;
                if ($s !== null) {
                    $out[$p['key']] = ['score' => $s, 'by' => $rater];
                    break;
                }
            }
        }

        return $out;
    }

    public function final(Appraisal $a, ?array $scores = null): ?float
    {
        $scores ??= array_map(fn ($e) => $e['score'], $this->effective($a));
        $sum = $weights = 0;
        foreach ($a->cycle->parts() as $p) {
            if (isset($scores[$p['key']])) {
                $sum += $scores[$p['key']] * $p['weight'];
                $weights += $p['weight'];
            }
        }

        return $weights ? round($sum / $weights, 2) : null;
    }

    public function tasks(Appraisal $a): array
    {
        $from = $a->cycle->start_date->toDateString();
        $to = $a->cycle->end_date->toDateString();
        $tasks = Task::withoutGlobalScopes()->where('company_id', $a->company_id)->where('employee_id', $a->employee_id)->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereBetween('due_date', [$from, $to])->orWhere(fn ($q) => $q->whereNull('due_date')->whereBetween('created_at', [$from, $to.' 23:59:59'])))
            ->get();
        $withDue = $tasks->filter(fn ($t) => $t->due_date);
        $onTime = $withDue->filter(fn ($t) => $t->status === 'done' && $t->completed_at && $t->completed_at->lte(Carbon::parse($t->due_date)->endOfDay()));
        $overdue = $withDue->filter(fn ($t) => $t->status !== 'done' && $t->due_date->lt(today()));

        return [
            'total' => $tasks->count(),
            'done' => $tasks->where('status', 'done')->count(),
            'on_time' => $onTime->count(),
            'overdue' => $overdue->count(),
            'average_progress' => $tasks->isEmpty() ? null : round((float) $tasks->avg('progress'), 1),
            'on_time_rate' => $withDue->isEmpty() ? null : round($onTime->count() / $withDue->count() * 100, 1),
            'suggested' => $tasks->isEmpty() ? null : round((float) $tasks->avg('progress') * ($withDue->isEmpty() ? 1 : 0.8) + ($withDue->isEmpty() ? 0 : $onTime->count() / $withDue->count() * 20), 1),
            'list' => $tasks->sortBy('due_date')->take(50)->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'status_label' => Task::STATUS_LABELS[$t->status] ?? $t->status,
                'progress' => (int) $t->progress, 'due_date' => $t->due_date?->toDateString()])->values()->all(),
        ];
    }

    public function toScale(?float $percent, string $scale): ?float
    {
        return $percent === null ? null : ($scale === 'five' ? round($percent / 20, 2) : $percent);
    }

    public function fromScale(?float $value, string $scale): ?float
    {
        return $value === null ? null : ($scale === 'five' ? round($value * 20, 2) : round($value, 2));
    }
}
