<?php

namespace Modules\Appraisals\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Appraisals\Models\Appraisal;
use Modules\Appraisals\Support\AppraisalAccess;
use Modules\Appraisals\Support\AppraisalScoring;
use Modules\Appraisals\Workflow\AppraisalProcess;
use Modules\Core\Notifications\Notice;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowStep;
use Modules\Workflow\Services\WorkflowEngine;

class ManageAppraisals
{
    public function __construct(private readonly AppraisalAccess $access, private readonly AppraisalScoring $scoring, private readonly WorkflowEngine $workflow) {}

    public function saveSelf(Appraisal $a, User $by, array $scores, ?string $comment, bool $submit): Appraisal
    {
        if (! $this->access->isEmployee($by, $a)) {
            abort(403);
        }
        if (! in_array($a->status, ['open', 'returned'], true) || $a->cycle->status !== 'open') {
            throw ValidationException::withMessages(['appraisal' => 'Your self-assessment cannot be changed now.']);
        }

        return DB::transaction(function () use ($a, $by, $scores, $comment, $submit) {
            $this->store($a, 'self', $by, $scores, $submit);
            $a->update(['self_comment' => $comment]);
            if ($submit) {
                $a->update(['status' => 'review', 'submitted_at' => now(), 'decision_comment' => null]);
                if (! $this->workflow->start(AppraisalProcess::KEY, $a, $by)) {
                    $this->finalise($a->refresh());
                }
            }

            return $a->refresh();
        });
    }

    public function review(Appraisal $a, User $by, array $scores, ?string $comment, string $decision): Appraisal
    {
        $rater = $this->access->raterFor($by, $a);
        if (! $rater) {
            throw ValidationException::withMessages(['appraisal' => 'This appraisal is not waiting for you.']);
        }
        if ($decision === 'return' && ! $comment) {
            throw ValidationException::withMessages(['comment' => 'Say what the employee should look at again.']);
        }
        if ($decision === 'approve' && $rater !== 'hr' && ! $comment) {
            throw ValidationException::withMessages(['comment' => 'Write your overall comment on the employee\'s performance.']);
        }

        return DB::transaction(function () use ($a, $by, $scores, $comment, $decision, $rater) {
            $instance = $a->workflow()->where('status', WorkflowInstance::PENDING)->firstOrFail();
            if ($decision === 'return') {
                if ($scores) {
                    $this->store($a, $rater, $by, $scores, false);
                }
                $this->workflow->reject($instance, $by, $comment);

                return $a->refresh();
            }
            $this->store($a, $rater, $by, $scores, true, $comment);
            $this->workflow->approve($instance, $by, $comment);
            while (($instance = $instance->refresh())->status === WorkflowInstance::PENDING && $this->workflow->canAct($by, $instance)
                && in_array($instance->currentStep()?->approver_type, [WorkflowStep::APPROVER_SUPERVISOR, WorkflowStep::APPROVER_DEPARTMENT_HEAD, WorkflowStep::APPROVER_SECTION_HEAD], true)) {
                $this->workflow->approve($instance, $by, 'Also rated as '.strtolower($instance->currentStep()->name).'.');
            }

            return $a->refresh();
        });
    }

    public function finalise(Appraisal $a): void
    {
        $a->load('scores', 'cycle');
        $final = $this->scoring->final($a);
        $a->update(['status' => 'acknowledge', 'final_score' => $final, 'rating' => $a->cycle->band($final)]);
        $a->employee?->user?->notify(new Notice('Appraisal completed', $final !== null ? 'Your appraisal for :name is complete: :score%. Read it and acknowledge.' : 'Your appraisal for :name is complete. Read it and acknowledge.', "/appraisals/{$a->id}", 'star-half',
            params: ['name' => $a->cycle->name, 'score' => $final]));
    }

    public function returned(Appraisal $a, ?string $comment, bool $withdrawn): void
    {
        $a->update(['status' => $withdrawn ? 'open' : 'returned', 'decision_comment' => $withdrawn ? null : $comment]);
        if (! $withdrawn) {
            $a->employee?->user?->notify(new Notice('Appraisal returned', 'Your appraisal for :name was returned: :reason', "/appraisals/{$a->id}", 'arrow-counterclockwise', params: ['name' => $a->cycle->name, 'reason' => $comment]));
        }
    }

    public function withdraw(Appraisal $a, User $by): Appraisal
    {
        if (! $this->access->isEmployee($by, $a) || $a->status !== 'review') {
            throw ValidationException::withMessages(['appraisal' => 'Only your appraisal under review can be withdrawn.']);
        }
        $instance = $a->workflow()->where('status', WorkflowInstance::PENDING)->first();
        if ($instance && $instance->steps()->where('status', 'approved')->exists()) {
            throw ValidationException::withMessages(['appraisal' => 'A reviewer has already rated it; it can no longer be withdrawn.']);
        }
        $instance ? $this->workflow->cancel($instance, $by) : $a->update(['status' => 'open']);

        return $a->refresh();
    }

    public function midYear(Appraisal $a, User $by, string $comment): Appraisal
    {
        if (! $this->access->canMidYear($by, $a)) {
            throw ValidationException::withMessages(['appraisal' => 'You cannot record the mid-year review of this appraisal now.']);
        }
        $a->update(['mid_year_comment' => $comment, 'mid_year_by' => $by->id, 'mid_year_at' => now()]);
        $a->employee?->user?->notify(new Notice('Mid-year review', ':name recorded your mid-year review.', "/appraisals/{$a->id}", 'star-half', params: ['name' => $by->display_name]));

        return $a;
    }

    public function acknowledge(Appraisal $a, User $by, bool $agrees, ?string $comment): Appraisal
    {
        if (! $this->access->isEmployee($by, $a) || $a->status !== 'acknowledge') {
            throw ValidationException::withMessages(['appraisal' => 'This appraisal is not waiting for your acknowledgement.']);
        }
        if (! $agrees && ! $comment) {
            throw ValidationException::withMessages(['comment' => 'Say what you do not agree with.']);
        }
        $a->update(['status' => 'done', 'employee_agrees' => $agrees, 'employee_comment' => $comment, 'acknowledged_at' => now()]);

        return $a;
    }

    public function cancel(Appraisal $a): Appraisal
    {
        if (in_array($a->status, ['done', 'cancelled'], true)) {
            throw ValidationException::withMessages(['appraisal' => 'This appraisal is already '.strtolower(Appraisal::STATUS_LABELS[$a->status]).'.']);
        }
        if ($instance = $a->workflow()->where('status', WorkflowInstance::PENDING)->first()) {
            $instance->steps()->whereIn('status', ['pending', 'waiting'])->delete();
            $instance->update(['status' => WorkflowInstance::CANCELLED, 'current_step_order' => null, 'completed_at' => now()]);
        }
        $a->update(['status' => 'cancelled']);

        return $a;
    }

    private function store(Appraisal $a, string $rater, User $by, array $scores, bool $complete, ?string $comment = null): void
    {
        $cycle = $a->cycle;
        $scale = $cycle->scale();
        [$min, $max] = $scale === 'five' ? [1, 5] : [0, 100];
        $competencies = collect($cycle->config['competencies'] ?? [])->pluck('id')->all();
        $rows = [];
        foreach ($cycle->parts() as $p) {
            if ($p['key'] === 'competencies') {
                foreach ($competencies as $id) {
                    $rows[] = ['part' => 'competencies', 'appraisal_competency_id' => $id, 'score' => $scores['competencies'][$id] ?? $scores['competencies'][(string) $id] ?? null, 'comment' => null];
                }
            } else {
                $rows[] = ['part' => $p['key'], 'appraisal_competency_id' => null, 'score' => $scores[$p['key']] ?? null, 'comment' => $scores['comments'][$p['key']] ?? null];
            }
        }
        foreach ($rows as $r) {
            if ($r['score'] !== null && ($r['score'] < $min || $r['score'] > $max)) {
                throw ValidationException::withMessages(['scores' => "Scores go from {$min} to {$max}."]);
            }
            if ($complete && $r['score'] === null) {
                throw ValidationException::withMessages(['scores' => 'Give a score for every part and competency.']);
            }
        }
        $a->scores()->where('rater', $rater)->delete();
        foreach ($rows as $r) {
            $a->scores()->create(['rater' => $rater, 'user_id' => $by->id, 'score' => $this->scoring->fromScale($r['score'] === null ? null : (float) $r['score'], $scale)] + $r);
        }
        if ($comment !== null && $rater !== 'self') {
            $a->scores()->create(['rater' => $rater, 'user_id' => $by->id, 'part' => 'overall', 'score' => null, 'comment' => $comment]);
        }
        $a->unsetRelation('scores');
    }
}
